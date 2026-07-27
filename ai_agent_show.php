<?php
/**
 * AI Agent 智能助手 - 前台页面 & API 接口
 */

defined('EMLOG_ROOT') || exit('access denied!');

$action = Input::getStrVar('action', '');

// 对话 API
if ($action === 'chat') {
    ai_agent_handle_chat();
    exit;
}

// 获取对话历史
if ($action === 'history') {
    ai_agent_handle_history();
    exit;
}

// 新建会话
if ($action === 'new_session') {
    ai_agent_handle_new_session();
    exit;
}

// 前台展示页面
?>
<div class="ai-agent-standalone">
    <h2>AI 智能助手</h2>
    <p>请使用页面右下角的对话按钮开始交互。</p>
</div>
<?php

/**
 * 获取对话历史
 */
function ai_agent_handle_history()
{
    $config = ai_agent_get_config();
    $uid = UID;

    if (!$config['guest_allowed'] && $uid < 1) {
        Output::error('请先登录');
        return;
    }

    $session_id = Input::getStrVar('session_id', '');
    if (empty($session_id)) {
        Output::error('缺少 session_id');
        return;
    }

    $chat_history = new AIAgent_ChatHistory();
    $history = $chat_history->getBySession($session_id);

    Output::ok(['history' => $history]);
}

/**
 * 新建会话
 */
function ai_agent_handle_new_session()
{
    $session_id = 'sess_' . time() . '_' . substr(md5(uniqid()), 0, 8);
    Output::ok(['session_id' => $session_id]);
}

/**
 * 处理对话请求
 */
function ai_agent_handle_chat()
{
    // Suppress PHP warnings/errors to ensure clean JSON output
    $old_level = error_reporting(0);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Output::error('请求方式错误', 405);
        return;
    }

    $config = ai_agent_get_config();
    $uid = UID;
    $role = 'guest';

    if ($uid > 0) {
        $user_model = new User_Model();
        $user = $user_model->getOneUser($uid);
        if ($user) {
            $role_map = ['admin' => 'admin', 'editor' => 'editor', 'writer' => 'writer'];
            $role = $role_map[$user['role']] ?? 'visitor';
        } else {
            $role = 'visitor';
        }
    }

    if (!$config['guest_allowed'] && $uid < 1) {
        Output::error('请先登录后再使用 AI 助手');
        return;
    }

    // 每日对话次数限制
    $daily_limit = 0;
    if ($uid < 1) {
        $daily_limit = intval($config['guest_daily_limit'] ?? 5);
    } elseif ($role === 'admin') {
        $daily_limit = intval($config['admin_daily_limit'] ?? 0);
    } else {
        $daily_limit = intval($config['user_daily_limit'] ?? 50);
    }
    if ($daily_limit > 0) {
        $db = MySql::getInstance();
        $today = date('Y-m-d 00:00:00');
        if ($uid > 0) {
            $count_row = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "ai_agent_chat WHERE uid={$uid} AND created_at >= '{$today}'");
        } else {
            $ip = getIp();
            $count_row = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "ai_agent_chat WHERE uid=0 AND created_at >= '{$today}'");
        }
        if (intval($count_row['cnt'] ?? 0) >= $daily_limit) {
            Output::error('今日对话次数已用完（' . $daily_limit . '次/天），请明天再试或登录获得更多额度');
            return;
        }
    }

    $message = Input::postStrVar('message', '');
    $session_id = Input::postStrVar('session_id', '');

    if (empty(trim($message))) {
        Output::error('请输入内容');
        return;
    }

    // 安全：输入长度限制
    $max_len = max(100, intval($config['max_input_length'] ?? 2000));
    if (mb_strlen($message) > $max_len) {
        Output::error('输入内容过长，最多' . $max_len . '个字符');
        return;
    }

    // 安全：频率限制（基于数据库）
    $rate_check = ai_agent_check_rate_limit($uid, $config);
    if (!$rate_check['ok']) {
        Output::error($rate_check['msg']);
        return;
    }

    // 从数据库加载对话历史作为 LLM 上下文
    $chat_history = new AIAgent_ChatHistory();
    $db_history = $chat_history->getBySession($session_id, 20);
    $conversation_history = [];
    foreach ($db_history as $h) {
        $conversation_history[] = ['role' => 'user', 'content' => $h['message']];
        $conversation_history[] = ['role' => 'assistant', 'content' => $h['response']];
    }

    // 执行 Agent
    $agent = new AIAgent_AgentLoop($config, $uid, $role);
    $result = $agent->execute($message, $conversation_history);

    // 内容安全过滤
    if (!empty($config['content_filter'])) {
        $result['response'] = ai_agent_content_filter($result['response']);
    }

    // 仅保存成功的回复到对话历史（避免错误响应污染上下文）
    $is_error = (strpos($result['response'], '抱歉，AI 服务暂时不可用') === 0)
        || (strpos($result['response'], 'LLM API') !== false && strpos($result['response'], '错误') !== false);
    if (!$is_error) {
        $chat_history->save($session_id, $uid, $message, $result['response'], $result['tool_calls']);
    } else {
        file_put_contents(AI_AGENT_DIR . '/ai/logs/llm_error.log', date('Y-m-d H:i:s') . ' SKIPPED_HISTORY: ' . substr($result['response'], 0, 200) . "\n", FILE_APPEND);
    }

    // 记录到用户记忆（错误响应不写入，防止污染系统提示词）
    if ($config['memory_enabled'] && $uid > 0 && !$is_error) {
        $memory_manager = new AIAgent_MemoryManager($config, $uid);
        $memory_manager->appendMemory("用户问: {$message}\nAI答: {$result['response']}");
    }

    error_reporting($old_level);
        Output::ok([
        'response'   => $result['response'],
        'tool_calls' => $result['tool_calls'],
        'log_count'  => count($result['log']),
        'session_id' => $session_id,
    ]);
}

/**
 * 频率限制检查
 */
function ai_agent_check_rate_limit($uid, $config)
{
    $per_min = max(1, intval($config['rate_limit_per_min'] ?? 20));
    $per_hour = max(10, intval($config['rate_limit_per_hour'] ?? 200));

    $db = MySql::getInstance();
    $identifier = $uid > 0 ? "uid={$uid}" : "uid=0";

    // 检查每分钟限制
    $min_ago = date('Y-m-d H:i:s', time() - 60);
    $r = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "ai_agent_chat WHERE {$identifier} AND created_at > '{$min_ago}'");
    if (intval($r['cnt']) >= $per_min) {
        return ['ok' => false, 'msg' => '请求过于频繁，请稍后再试（每分钟最多' . $per_min . '次）'];
    }

    // 检查每小时限制
    $hour_ago = date('Y-m-d H:i:s', time() - 3600);
    $r2 = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "ai_agent_chat WHERE {$identifier} AND created_at > '{$hour_ago}'");
    if (intval($r2['cnt']) >= $per_hour) {
        return ['ok' => false, 'msg' => '本小时请求次数已达上限（' . $per_hour . '次），请稍后再试'];
    }

    return ['ok' => true, 'msg' => ''];
}

/**
 * 内容安全过滤
 * 过滤AI回复中的敏感内容和恶意代码
 */
function ai_agent_content_filter($content)
{
    if (empty($content)) return $content;

    // 1. 移除潜在的XSS脚本标签（保留普通HTML）
    $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);

    // 2. 移除onerror/onload等事件属性
    $content = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $content);
    $content = preg_replace("/\s+on\w+\s*=\s*'[^']*'/i", '', $content);

    // 3. 移除javascript:协议
    $content = preg_replace('/javascript\s*:/i', '', $content);

    // 4. 移除data:text/html协议（防止data URI XSS）
    $content = preg_replace('/data\s*:\s*text\/html[^\s]*/i', '', $content);

    return $content;
}
