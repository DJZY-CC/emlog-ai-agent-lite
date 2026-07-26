<?php
/**
 * AI Agent - 核心循环引擎
 * 实现 ReAct Agent 模式：思考 → 工具调用 → 观察 → 再思考
 * 最大3轮工具迭代，支持多工具并行/串行调度
 */

defined('EMLOG_ROOT') || exit('access denied!');

class AIAgent_AgentLoop
{
    private $config;
    private $promptBuilder;
    private $toolRunner;
    private $memoryManager;
    private $maxRounds;
    private $retryCount;
    private $log = []; // 执行日志

    public function __construct($config, $uid = 0, $role = 'guest')
    {
        $this->config = $config;
        $this->maxRounds = min(3, max(1, intval($config['agent_max_rounds'])));
        $this->retryCount = max(0, intval($config['agent_retry']));
        $this->historyLimit = max(5, intval($config['agent_history_limit'] ?? 20));

        $this->promptBuilder = new AIAgent_PromptBuilder($config, $uid);
        $this->toolRunner = new AIAgent_ToolRunner($config, $uid, $role);
        $this->memoryManager = new AIAgent_MemoryManager($config, $uid);
    }

    /**
     * 执行 Agent 对话
     * @param string $user_message 用户输入
     * @param array $conversation_history 会话历史
     * @return array ['response' => string, 'log' => array, 'tool_calls' => array]
     */
    public function execute($user_message, $conversation_history = [])
    {
        $this->log = [];
        $tool_calls_log = [];

        // 构建系统提示词
        $system_prompt = $this->promptBuilder->buildSystemPrompt();
        $tools_definition = $this->promptBuilder->getToolsDefinition();

        // 构建消息列表
        $messages = [
            ['role' => 'system', 'content' => $system_prompt],
        ];

        // 加入会话历史（最近10轮）
        $history = array_slice($conversation_history, -$this->historyLimit);
        foreach ($history as $msg) {
            $messages[] = $msg;
        }

        // 加入当前用户消息
        $messages[] = ['role' => 'user', 'content' => $user_message];

        $this->addLog('info', '开始处理用户请求', ['message' => $user_message]);

        // Agent 循环
        $final_response = '';
        for ($round = 1; $round <= $this->maxRounds; $round++) {
            $this->addLog('info', "第 {$round} 轮 Agent 思考");

            // 调用 LLM
            $llm_response = $this->callLLM($messages, $tools_definition);

            if (!$llm_response['success']) {
                $this->addLog('error', 'LLM 调用失败', ['error' => $llm_response['error']]);
                if (!empty($this->config['error_log_enabled'])) {
                    $log_path = !empty($this->config['error_log_path']) ? $this->config['error_log_path'] : AI_AGENT_DIR . '/ai/logs/llm_error.log';
                    file_put_contents($log_path, date('Y-m-d H:i:s') . ' ' . $llm_response['error'] . "\n", FILE_APPEND);
                }
                return [
                    'response'   => '抱歉，AI 服务暂时不可用: ' . $llm_response['error'],
                    'log'        => $this->log,
                    'tool_calls' => $tool_calls_log,
                ];
            }

            $choice = $llm_response['data'];
            $assistant_message = $choice['message'] ?? [];

            // 检查是否有工具调用
            $tool_calls = $assistant_message['tool_calls'] ?? [];

            // 如果标准 tool_calls 为空，检查是否有 DSML 格式的工具调用
            if (empty($tool_calls)) {
                $content_text = $assistant_message['content'] ?? '';
                $dsml_calls = $this->parseDsmlToolCalls($content_text);
                if (!empty($dsml_calls)) {
                    $this->addLog('info', '检测到 DSML 格式工具调用，共 ' . count($dsml_calls) . ' 个');
                    $tool_calls = [];
                    foreach ($dsml_calls as $dc) {
                        // 转换为标准格式以便后续处理
                        $tool_calls[] = [
                            'id' => 'dsml_' . uniqid(),
                            'type' => 'function',
                            'function' => [
                                'name' => $dc['tool'],
                                'arguments' => json_encode($dc['args']),
                            ],
                        ];
                    }
                    // 从回复中移除 DSML 标记，保留纯文本部分
                    $clean_text = preg_replace('/<｜｜DSML｜｜tool_calls>.*<｜｜DSML｜｜\/tool_calls>/s', '', $content_text);
                    $assistant_message['content'] = trim($clean_text);
                } else {
                    // 没有工具调用，直接返回文本回复
                    $final_response = $content_text;
                    $this->addLog('info', 'AI 返回文本回复（无工具调用）');
                    break;
                }
            }

            // 有工具调用，执行工具
            $this->addLog('info', "AI 请求调用 " . count($tool_calls) . " 个工具");

            // 将 assistant 消息加入历史
            $messages[] = $assistant_message;

            // 执行所有工具调用
            foreach ($tool_calls as $tc) {
                $function = $tc['function'] ?? [];
                $tool_name = $function['name'] ?? '';
                $tool_args = [];

                if (isset($function['arguments'])) {
                    $args_str = $function['arguments'];
                    if (is_string($args_str)) {
                        $tool_args = json_decode($args_str, true) ?: [];
                    } elseif (is_array($args_str)) {
                        $tool_args = $args_str;
                    }
                }

                $this->addLog('tool_call', "调用工具: {$tool_name}", ['args' => $tool_args]);

                // 执行工具（含重试）
                $tool_result = $this->executeToolWithRetry($tool_name, $tool_args);

                $tool_calls_log[] = [
                    'tool'   => $tool_name,
                    'args'   => $tool_args,
                    'result' => $tool_result,
                ];

                // 将工具结果加入消息
                $messages[] = [
                    'role'       => 'tool',
                    'tool_call_id' => $tc['id'] ?? '',
                    'content'    => json_encode($tool_result, JSON_UNESCAPED_UNICODE),
                ];

                $status = $tool_result['success'] ? 'success' : 'error';
                $this->addLog($status, "工具 {$tool_name} 执行完成", $tool_result);
            }

            // 最后一轮后，让 AI 总结结果
            if ($round === $this->maxRounds) {
                $this->addLog('info', '达到最大轮次，让 AI 总结结果');
                $summary_response = $this->callLLM($messages, []);
                if ($summary_response['success']) {
                    $final_response = $summary_response['data']['message']['content'] ?? '操作已完成，但无法生成摘要。';
                } else {
                    $final_response = '操作已完成，但 AI 摘要生成失败。';
                }
            }
        }

        // 如果循环结束还没有回复
        if (empty($final_response)) {
            $final_response = '处理完成，但未能生成回复。';
        }

        // 异步更新记忆（不阻塞响应）
        $this->memoryManager->incrementCounter();
        if ($this->memoryManager->shouldTriggerCleanup()) {
            $this->memoryManager->triggerAsyncCleanup();
        }

        return [
            'response'   => $final_response,
            'log'        => $this->log,
            'tool_calls' => $tool_calls_log,
        ];
    }

    /**
     * 调用 LLM API
     */
    private function callLLM($messages, $tools = [])
    {
        $api_url = $this->config['llm_api_url'];
        $api_key = $this->config['llm_api_key'];
        $model = $this->config['llm_model'];
        $temperature = floatval($this->config['llm_temperature']);
        $max_tokens = intval($this->config['llm_max_tokens']);

        if (empty($api_url) || empty($api_key)) {
            return ['success' => false, 'error' => 'LLM API 未配置'];
        }

        $post_data = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $max_tokens,
        ];

        // 添加工具定义（如果有）
        if (!empty($tools)) {
            $formatted_tools = [];
            foreach ($tools as $tool) {
                $formatted_tools[] = [
                    'type'     => 'function',
                    'function' => $tool,
                ];
            }
            $post_data['tools'] = $formatted_tools;
        }

        $json_body = json_encode($post_data, JSON_UNESCAPED_UNICODE);
        if ($json_body === false) {
            // 尝试清理非UTF-8字符后重试
            $post_data = $this->cleanUtf8($post_data);
            $json_body = json_encode($post_data, JSON_UNESCAPED_UNICODE);
            if ($json_body === false) {
                return ['success' => false, 'error' => 'JSON编码失败: ' . json_last_error_msg()];
            }
        }

        // Debug: 记录请求体大小
        file_put_contents(AI_AGENT_DIR . '/ai/logs/llm_debug.log', date('Y-m-d H:i:s') . ' body_size=' . strlen($json_body) . ' msg_count=' . count($messages) . "\n", FILE_APPEND);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(10, intval($this->config['llm_timeout'] ?? 60)));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => "cURL 错误: {$curl_error}"];
        }

        if ($http_code !== 200) {
            // 提供友好的错误提示
            $friendly_error = '';
            if ($http_code === 401) {
                $friendly_error = 'LLM API Key 无效（需更新配置），请在插件设置中更新 API 密钥';
            } elseif ($http_code === 403) {
                $friendly_error = 'LLM API 权限不足，请检查 API Key 权限或账户余额';
            } elseif ($http_code === 429) {
                $friendly_error = 'LLM API 请求频率超限，请稍后再试';
            } elseif ($http_code >= 500) {
                $friendly_error = 'LLM API 服务端错误 (HTTP ' . $http_code . ')，请稍后再试';
            } else {
                // 尝试从响应中提取错误信息
                $err_data = json_decode($response, true);
                if ($err_data && isset($err_data['error']['message'])) {
                    $friendly_error = 'LLM API 错误: ' . $err_data['error']['message'];
                } else {
                    $friendly_error = 'LLM API 请求失败 (HTTP ' . $http_code . ')';
                }
            }
            file_put_contents(AI_AGENT_DIR . '/ai/logs/llm_error.log', date('Y-m-d H:i:s') . ' HTTP ' . $http_code . ': ' . substr($response, 0, 500) . "
", FILE_APPEND);
            return ['success' => false, 'error' => $friendly_error];
        }

        $data = json_decode($response, true);
        if (!$data) {
            file_put_contents(AI_AGENT_DIR . '/ai/logs/llm_error.log', date('Y-m-d H:i:s') . ' JSON解析失败: ' . substr($response, 0, 500) . "\n", FILE_APPEND);
            return ['success' => false, 'error' => 'JSON 解析失败: ' . substr($response, 0, 200)];
        }

        if (isset($data['error'])) {
            file_put_contents(AI_AGENT_DIR . '/ai/logs/llm_error.log', date('Y-m-d H:i:s') . ' API错误: ' . json_encode($data['error']) . "\n", FILE_APPEND);
            return ['success' => false, 'error' => $data['error']['message'] ?? '未知API错误'];
        }

        $choice = $data['choices'][0] ?? null;
        if (!$choice) {
            return ['success' => false, 'error' => 'API 返回空结果'];
        }

        return ['success' => true, 'data' => $choice];
    }

    /**
     * 执行工具（含重试）
     */
    private function executeToolWithRetry($tool_name, $params)
    {
        $result = $this->toolRunner->run($tool_name, $params);

        if (!$result['success'] && $this->retryCount > 0) {
            $this->addLog('info', "工具 {$tool_name} 失败，重试中...");
            $result = $this->toolRunner->run($tool_name, $params);
        }

        return $result;
    }

    /**
     * 添加执行日志
     */
    private function addLog($type, $message, $data = null)
    {
        $this->log[] = [
            'time'    => date('Y-m-d H:i:s'),
            'type'    => $type,
            'message' => $message,
            'data'    => $data,
        ];
    }

    /**
     * 清理非UTF-8字符
     */
    private function cleanUtf8($data)
    {
        if (is_string($data)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        if (is_array($data)) {
            $cleaned = [];
            foreach ($data as $k => $v) {
                $cleaned[$this->cleanUtf8($k)] = $this->cleanUtf8($v);
            }
            return $cleaned;
        }
        return $data;
    }

    /**
     * 解析 DeepSeek DSML 格式的工具调用
     * 当模型用 <｜｜DSML｜｜tool_calls> 格式输出时解析
     */
    private function parseDsmlToolCalls($response)
    {
        $tool_calls = [];
        // 匹配 <｜｜DSML｜｜invoke name="xxx">
        $pattern = '/<｜｜DSML｜｜invoke\s+name="([^"]+)">\s*(.*?)\s*<｜｜DSML｜｜\/invoke>/s';
        if (preg_match_all($pattern, $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tool_name = $match[1];
                $params_block = $match[2];
                $args = [];
                // 匹配 <｜｜DSML｜｜parameter name="xxx" string="true|false">value</｜｜DSML｜｜parameter>
                $param_pattern = '/<｜｜DSML｜｜parameter\s+name="([^"]+)"(?:\s+string="(true|false)")?>([\s\S]*?)<｜｜DSML｜｜\/parameter>/';
                if (preg_match_all($param_pattern, $params_block, $param_matches, PREG_SET_ORDER)) {
                    foreach ($param_matches as $pm) {
                        $pname = $pm[1];
                        $is_string = ($pm[2] === 'true');
                        $pvalue = $pm[3];
                        if (!$is_string && is_numeric($pvalue)) {
                            $pvalue = intval($pvalue);
                        } elseif (!$is_string && $pvalue === 'true') {
                            $pvalue = true;
                        } elseif (!$is_string && $pvalue === 'false') {
                            $pvalue = false;
                        }
                        $args[$pname] = $pvalue;
                    }
                }
                $tool_calls[] = [
                    'tool' => $tool_name,
                    'args' => $args,
                ];
            }
        }
        return $tool_calls;
    }

}
