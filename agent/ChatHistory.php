<?php
/**
 * AI Agent - 对话历史管理
 * 使用数据库存储对话记录，跨浏览器、跨设备持久化
 */

defined('EMLOG_ROOT') || exit('access denied!');

class AIAgent_ChatHistory
{
    private $db;
    private $table;

    public function __construct()
    {
        $this->db = MySql::getInstance();
        $this->table = DB_PREFIX . 'ai_agent_chat';
    }

    /**
     * 创建数据表
     */
    public function createTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table}` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `session_id` varchar(64) NOT NULL DEFAULT '',
            `uid` int(11) unsigned NOT NULL DEFAULT 0,
            `role` varchar(20) NOT NULL DEFAULT 'user',
            `message` text NOT NULL,
            `response` text NOT NULL,
            `tool_calls` text,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_session` (`session_id`),
            KEY `idx_uid` (`uid`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $this->db->query($sql);

        // 兼容旧表：如果 role 列不存在则添加
        $check = $this->db->once_fetch_array("SHOW COLUMNS FROM `{$this->table}` LIKE 'role'");
        if (empty($check)) {
            $this->db->query("ALTER TABLE `{$this->table}` ADD COLUMN `role` varchar(20) NOT NULL DEFAULT 'user' AFTER `uid`");
        }
    }

    /**
     * 删除数据表
     */
    public function dropTable()
    {
        $this->db->query("DROP TABLE IF EXISTS `{$this->table}`");
    }

    /**
     * 保存一条对话记录
     */
    public function save($session_id, $uid, $message, $response, $tool_calls = [])
    {
        $session_id = addslashes($session_id);
        $uid = intval($uid);
        $message = addslashes($message);
        $response = addslashes($response);
        $tool_calls_json = addslashes(json_encode($tool_calls, JSON_UNESCAPED_UNICODE));
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            "INSERT INTO `{$this->table}` (session_id, uid, role, message, response, tool_calls, created_at)
             VALUES ('{$session_id}', {$uid}, 'user', '{$message}', '{$response}', '{$tool_calls_json}', '{$now}')"
        );
        return $this->db->insert_id();
    }

    /**
     * 获取指定会话的对话历史
     */
    public function getBySession($session_id, $limit = 50)
    {
        $session_id = addslashes($session_id);
        $result = [];
        $query = $this->db->query(
            "SELECT * FROM `{$this->table}` WHERE session_id='{$session_id}' ORDER BY id ASC LIMIT " . intval($limit)
        );
        while ($row = $this->db->fetch_array($query)) {
            $result[] = [
                'id'         => intval($row['id']),
                'session_id' => $row['session_id'],
                'uid'        => intval($row['uid']),
                'message'    => $row['message'],
                'response'   => $row['response'],
                'tool_calls' => json_decode($row['tool_calls'], true) ?: [],
                'created_at' => $row['created_at'],
            ];
        }
        return $result;
    }

    /**
     * 获取用户最近的会话列表
     */
    public function getRecentSessions($uid, $limit = 20)
    {
        $uid = intval($uid);
        $result = [];
        $query = $this->db->query(
            "SELECT session_id, MIN(created_at) AS started, MAX(created_at) AS last_active, COUNT(*) AS msg_count
             FROM `{$this->table}` WHERE uid={$uid}
             GROUP BY session_id ORDER BY last_active DESC LIMIT " . intval($limit)
        );
        while ($row = $this->db->fetch_array($query)) {
            $result[] = [
                'session_id'  => $row['session_id'],
                'started'     => $row['started'],
                'last_active' => $row['last_active'],
                'msg_count'   => intval($row['msg_count']),
            ];
        }
        return $result;
    }

    /**
     * 删除指定会话记录
     */
    public function deleteSession($session_id)
    {
        $session_id = addslashes($session_id);
        $this->db->query("DELETE FROM `{$this->table}` WHERE session_id='{$session_id}'");
    }

    /**
     * 清理过期记录
     */
    public function cleanExpired($days = 90)
    {
        $expire = date('Y-m-d H:i:s', time() - ($days * 86400));
        $this->db->query("DELETE FROM `{$this->table}` WHERE created_at < '{$expire}'");
    }

    /**
     * 获取统计信息
     */
    public function getStats()
    {
        $total = $this->db->once_fetch_array("SELECT COUNT(*) AS cnt FROM `{$this->table}`");
        $sessions = $this->db->once_fetch_array("SELECT COUNT(DISTINCT session_id) AS cnt FROM `{$this->table}`");
        return [
            'total_messages' => intval($total['cnt']),
            'total_sessions' => intval($sessions['cnt']),
        ];
    }
}
