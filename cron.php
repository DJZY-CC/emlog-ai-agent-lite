<?php
/**
 * AI Agent 智能助手 - 定时任务 + 自动发文
 * 记忆整理、日志清理、自动发文、运行状态追踪
 */

defined('EMLOG_ROOT') || exit('access denied!');

require_once __DIR__ . '/agent/ChatHistory.php';
require_once __DIR__ . '/agent/MemoryManager.php';
require_once __DIR__ . '/agent/PromptBuilder.php';
require_once __DIR__ . '/agent/ToolRunner.php';
require_once __DIR__ . '/agent/AgentLoop.php';

/**
 * 执行定时任务（原有功能）
 */
function ai_agent_cron_run()
{
    $storage = Storage::getInstance('ai_agent');
    $config = $storage->getValue('config');
    if (empty($config)) return [];

    $results = [];

    // 1. 清理过期会话缓存
    $runtime_dir = __DIR__ . '/ai/runtime';
    if (is_dir($runtime_dir)) {
        $expire = intval($config['session_expire'] ?? 1800);
        $cleaned = 0;
        $files = glob($runtime_dir . '/session_*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && (time() - $data['updated'] > $expire)) {
                unlink($file);
                $cleaned++;
            }
        }
        $results[] = "清理过期会话缓存: {$cleaned} 个";
    }

    // 2. 清理过期对话历史
    $chat_history = new AIAgent_ChatHistory();
    $chat_history->cleanExpired(intval($config['memory_expire_days'] ?? 90));
    $results[] = "清理过期对话历史完成";

    // 3. 记忆自动整理
    if (!empty($config['memory_enabled'])) {
        $memory_manager = new AIAgent_MemoryManager($config, 0);
        if ($memory_manager->shouldTriggerCleanup()) {
            $memory_manager->triggerAsyncCleanup();
            $results[] = "记忆自动整理完成";
        }
    }

    // 4. 清理过期记忆文件
    $memory_dir = __DIR__ . '/ai/memory';
    if (is_dir($memory_dir)) {
        $expire_days = intval($config['memory_expire_days'] ?? 90);
        $expire_time = time() - ($expire_days * 86400);
        $cleaned = 0;
        $files = glob($memory_dir . '/*.MEMORY.md');
        foreach ($files as $file) {
            if (filemtime($file) < $expire_time) {
                unlink($file);
                $cleaned++;
            }
        }
        $results[] = "清理过期记忆文件: {$cleaned} 个";
    }

    return $results;
}

/**
 * 伪 Cron 自动发文（随访客访问触发）
 */
function ai_agent_auto_publish_trigger()
{
    $storage = Storage::getInstance('ai_agent');
    $config = $storage->getValue('config');

    // 检查是否开启自动发文
    if (empty($config['auto_publish_enabled'])) return;

    // 检查上次运行时间
    $last_run = intval($config['auto_publish_last_run'] ?? 0);
    $interval = intval($config['auto_publish_interval'] ?? 3600); // 默认1小时
    $now = time();

    if (($now - $last_run) < $interval) return;

    // 检查今日发文上限
    $daily_max = intval($config['auto_publish_daily_max'] ?? 5);
    $today_start = strtotime('today 00:00:00');
    $db = MySql::getInstance();
    $today_count = $db->once_fetch_array(
        "SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "blog WHERE author=1 AND date >= {$today_start}"
    );
    if (intval($today_count['cnt'] ?? 0) >= $daily_max) return;

    // 检查发布时间段
    $hour = intval(date('G'));
    $hour_start = intval($config['auto_publish_hour_start'] ?? 8);
    $hour_end = intval($config['auto_publish_hour_end'] ?? 22);
    if ($hour < $hour_start || $hour >= $hour_end) return;

    // 执行自动发文
    ai_agent_do_auto_publish($config);
}

/**
 * 执行自动发文
 */
function ai_agent_do_auto_publish($config)
{
    $storage = Storage::getInstance('ai_agent');

    // 更新运行状态
    $config['auto_publish_last_run'] = time();
    $config['auto_publish_run_count'] = intval($config['auto_publish_run_count'] ?? 0) + 1;
    $storage->setValue('config', $config, 'array');

    // 获取 Prompt（优先使用自定义，否则智能生成）
    if (!empty($config['auto_publish_prompt'])) {
        $prompt = $config['auto_publish_prompt'];
        $sortid = intval($config['auto_publish_sortid'] ?? 0);
        $tags = trim($config['auto_publish_tags'] ?? '');
        $sort_name = '';
        if ($sortid > 0) {
            $db = MySql::getInstance();
            $sort = $db->once_fetch_array("SELECT sortname FROM " . DB_PREFIX . "sort WHERE sid={$sortid}");
            $sort_name = $sort['sortname'] ?? '';
        }
        $prompt = str_replace(['{分类名}', '{标签}', '{日期}'], [$sort_name, $tags, date('Y年m月d日')], $prompt);
    } else {
        // 智能生成话题
        list($prompt, $sortid, $sort_name) = ai_agent_generate_smart_prompt($config);
    }

    // 调用 AI 发文（简化单步模式：预先查好分类，只给模型 publish_article 一个工具）
    try {

        // 直接调用 LLM + 单个工具，避免多轮链断裂
        $result = ai_agent_single_step_publish($config, $prompt, $sortid);

        // 记录日志
        $log_entry = [
            'time'    => date('Y-m-d H:i:s'),
            'prompt'  => mb_substr($prompt, 0, 100),
            'success' => $result['success'],
            'tools'   => $result['tools'],
            'aid'     => $result['aid'] ?? 0,
        ];

        $log_file = __DIR__ . '/ai/auto_publish_log.json';
        $logs = [];
        if (file_exists($log_file)) {
            $logs = json_decode(file_get_contents($log_file), true) ?: [];
        }
        $logs[] = $log_entry;
        $logs = array_slice($logs, -50); // 只保留最近50条
        file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 更新最后成功时间
        $config['auto_publish_last_success'] = time();
        $storage->setValue('config', $config, 'array');

    } catch (Exception $e) {
        $log_file = __DIR__ . '/ai/auto_publish_error.log';
        file_put_contents($log_file, date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}

/**
 * 默认自动发文 Prompt
 */
/**
 * 智能生成话题 Prompt（基于站点内容分析）
 */
function ai_agent_generate_smart_prompt($config)
{
    $db = MySql::getInstance();
    $sortid = intval($config['auto_publish_sortid'] ?? 0);

    // 1. 获取分类信息
    $categories = [];
    $query = $db->query("SELECT sid, sortname, description FROM " . DB_PREFIX . "sort ORDER BY sid");
    while ($row = $db->fetch_array($query)) {
        $categories[] = $row;
    }

    // 2. 随机选择一个分类（或使用指定分类）
    $target_sort = null;
    if ($sortid > 0) {
        foreach ($categories as $cat) {
            if ($cat['sid'] == $sortid) { $target_sort = $cat; break; }
        }
    }
    if (!$target_sort && !empty($categories)) {
        $target_sort = $categories[array_rand($categories)];
    }

    // 3. 获取该分类下最近的文章标题（避免重复）
    $recent_titles = [];
    if ($target_sort) {
        $query = $db->query("SELECT title FROM " . DB_PREFIX . "blog WHERE sortid={$target_sort['sid']} AND hide='n' ORDER BY date DESC LIMIT 10");
        while ($row = $db->fetch_array($query)) {
            $recent_titles[] = $row['title'];
        }
    }

    // 4. 获取热门标签
    $popular_tags = [];
    $query = $db->query("SELECT tagname FROM " . DB_PREFIX . "tag ORDER BY tid DESC LIMIT 20");
    while ($row = $db->fetch_array($query)) {
        $popular_tags[] = $row['tagname'];
    }

    // 5. 构建智能 Prompt
    $sort_name = $target_sort ? $target_sort['sortname'] : '综合';
    $sort_desc = $target_sort ? ($target_sort['description'] ?? '') : '';
    $recent_list = !empty($recent_titles) ? implode('、', array_slice($recent_titles, 0, 5)) : '暂无';
    $tag_list = !empty($popular_tags) ? implode('、', array_slice($popular_tags, 0, 10)) : '暂无';

    $prompt = <<<PROMPT
你是一个博客内容创作者，请为「{$sort_name}」分类撰写一篇原创文章。

## 站点背景
- 当前分类：{$sort_name}
- 分类描述：{$sort_desc}
- 站点已有标签：{$tag_list}
- 该分类近期文章（请避免重复话题）：{$recent_list}

## 写作要求
1. **选题**：选择一个与「{$sort_name}」相关、但与近期文章不重复的新话题
2. **标题**：SEO友好，吸引点击，包含关键词，不超过30字
3. **正文**：1000-1500字，包含 H2/H3 层级标题，语言自然流畅
4. **摘要**：150字以内的精炼摘要
5. **标签**：从已有标签中选择相关的，或创建新的（2-4个）
6. **风格**：专业但不刻板，避免AI腔调，像真人博主写的

## 重要
- 不要重复已有文章的话题
- 标签尽量复用已有标签
- 直接调用工具发布文章，不要在回复中输出全文
PROMPT;

    return [$prompt, $target_sort ? $target_sort['sid'] : 0, $target_sort ? $target_sort['sortname'] : ''];
}

/**
 * 默认自动发文 Prompt（兼容旧版本）
 */
function ai_agent_get_default_auto_prompt()
{
    return <<<'PROMPT'
请为博客撰写一篇原创文章，要求：

1. **标题**：SEO友好，吸引点击，包含关键词，不超过30字
2. **正文**：1000-1500字，包含 H2/H3 层级标题，语言自然流畅
3. **摘要**：150字以内的精炼摘要
4. **分类**：{分类名}
5. **标签**：{标签}
6. **风格**：专业但不刻板，避免AI腔调，像真人博主写的

请直接调用工具发布文章，不要在回复中输出文章全文。
PROMPT;
}

/**
 * 获取自动发文状态
 */
function ai_agent_get_cron_status()
{
    $storage = Storage::getInstance('ai_agent');
    $config = $storage->getValue('config');

    $last_run = intval($config['auto_publish_last_run'] ?? 0);
    $last_success = intval($config['auto_publish_last_success'] ?? 0);
    $interval = intval($config['auto_publish_interval'] ?? 3600);
    $run_count = intval($config['auto_publish_run_count'] ?? 0);
    $next_run = $last_run > 0 ? $last_run + $interval : 0;

    // 读取日志
    $log_file = __DIR__ . '/ai/auto_publish_log.json';
    $logs = [];
    if (file_exists($log_file)) {
        $logs = json_decode(file_get_contents($log_file), true) ?: [];
    }

    // 读取错误日志
    $error_file = __DIR__ . '/ai/auto_publish_error.log';
    $errors = '';
    if (file_exists($error_file)) {
        $errors = file_get_contents($error_file);
    }

    return [
        'enabled'      => !empty($config['auto_publish_enabled']),
        'last_run'     => $last_run > 0 ? date('Y-m-d H:i:s', $last_run) : '从未运行',
        'last_success' => $last_success > 0 ? date('Y-m-d H:i:s', $last_success) : '从未成功',
        'next_run'     => $next_run > 0 ? date('Y-m-d H:i:s', $next_run) : '-',
        'interval'     => $interval,
        'run_count'    => $run_count,
        'daily_max'    => intval($config['auto_publish_daily_max'] ?? 5),
        'logs'         => array_slice($logs, -10),
        'errors'       => $errors,
    ];
}

/**
 * 简化单步自动发文（避免多轮工具链在部分模型上断裂）
 * 预先查好分类，只给模型 publish_article 一个工具
 */
function ai_agent_single_step_publish($config, $prompt, $sortid = 0)
{
    $db = MySql::getInstance();

    // 构建单个工具定义
    $tools = array(
        array('type' => 'function', 'function' => array(
            'name' => 'publish_article',
            'description' => '发布文章到博客。分类ID已知：' . ($sortid > 0 ? $sortid : '1') . '。标签用逗号分隔。',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'title'   => array('type' => 'string', 'description' => '文章标题'),
                    'content' => array('type' => 'string', 'description' => '文章正文，Markdown格式'),
                    'tags'    => array('type' => 'string', 'description' => '标签，逗号分隔'),
                ),
                'required' => array('title', 'content'),
            ),
        )),
    );

    // 系统提示：直接告诉模型分类ID，避免多轮查询
    $system = "你是博客内容创作者。用户要求发文时直接调用publish_article工具。\n";
    $system .= "分类ID固定为 {$sortid}（如为0则不指定分类）。\n";
    $system .= "标签用逗号分隔（如：春天,花卉）。\n";
    $system .= "正文不少于500字，包含小标题。\n";
    $system .= "禁止在回复中输出文章全文，必须调用工具。";

    $body = array(
        'model'       => $config['llm_model'],
        'temperature' => floatval($config['llm_temperature'] ?? 0.7),
        'max_tokens'  => intval($config['llm_max_tokens'] ?? 2000),
        'messages'    => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $prompt),
        ),
        'tools' => $tools,
    );

    $ch = curl_init($config['llm_api_url']);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Authorization: Bearer ' . $config['llm_api_key']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(30, intval($config['llm_timeout'] ?? 60)),
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return array('success' => false, 'tools' => array(), 'aid' => 0, 'error' => "cURL: {$curl_err}");
    }
    if ($http_code !== 200) {
        return array('success' => false, 'tools' => array(), 'aid' => 0, 'error' => "HTTP {$http_code}");
    }

    $data = json_decode($resp, true);
    if (isset($data['error'])) {
        return array('success' => false, 'tools' => array(), 'aid' => 0, 'error' => $data['error']['message'] ?? '未知错误');
    }

    $msg = $data['choices'][0]['message'] ?? array();
    $tool_calls = $msg['tool_calls'] ?? array();

    if (empty($tool_calls)) {
        return array('success' => false, 'tools' => array(), 'aid' => 0, 'error' => '模型未调用工具');
    }

    // 执行工具调用
    require_once __DIR__ . '/agent/ToolRunner.php';
    $toolRunner = new AIAgent_ToolRunner($config, 1, 'admin');
    $tools_used = array();
    $aid = 0;

    foreach ($tool_calls as $tc) {
        $func = $tc['function'] ?? array();
        $name = $func['name'] ?? '';
        $args = isset($func['arguments']) ? json_decode($func['arguments'], true) : array();

        $result = $toolRunner->run($name, $args ?: array());
        $tools_used[] = $name;

        if ($name === 'publish_article' && $result['success']) {
            $aid = $result['data']['aid'] ?? 0;
        }
    }

    return array(
        'success' => $aid > 0,
        'tools'   => $tools_used,
        'aid'     => $aid,
    );
}
