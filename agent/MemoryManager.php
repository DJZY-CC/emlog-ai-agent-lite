<?php
/**
 * AI Agent - 记忆管理器
 * 管理用户长期记忆、会话缓存、自动整理
 */

defined('EMLOG_ROOT') || exit('access denied!');

class AIAgent_MemoryManager
{
    private $config;
    private $uid;

    public function __construct($config, $uid = 0)
    {
        $this->config = $config;
        $this->uid = $uid;
    }

    /**
     * 获取用户记忆文件路径
     */
    private function getMemoryPath()
    {
        return AI_AGENT_DIR . '/ai/memory/' . $this->uid . '.MEMORY.md';
    }

    /**
     * 读取用户记忆
     */
    public function getMemory()
    {
        $path = $this->getMemoryPath();
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return '';
    }

    /**
     * 保存用户记忆
     */
    public function saveMemory($content)
    {
        $path = $this->getMemoryPath();

        // 文件锁防并发写入
        $fp = fopen($path . '.lock', 'c');
        if (flock($fp, LOCK_EX)) {
            file_put_contents($path, $content);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    /**
     * 追加记忆条目
     */
    public function appendMemory($entry)
    {
        $current = $this->getMemory();
        $timestamp = date('Y-m-d H:i');
        $new_entry = "\n\n[{$timestamp}] {$entry}";

        // 限制记忆文件大小
        $max_size = max(5, intval($this->config['memory_max_size'] ?? 10)) * 1024;
        $new_content = $current . $new_entry;

        if (strlen($new_content) > $max_size) {
            // 截断旧记忆
            $new_content = substr($new_content, -$max_size);
            // 找到第一个完整条目的起始位置
            $pos = strpos($new_content, "\n[");
            if ($pos !== false) {
                $new_content = substr($new_content, $pos + 1);
            }
        }

        $this->saveMemory($new_content);
    }

    /**
     * 获取会话缓存路径
     */
    private function getSessionPath($session_id)
    {
        return AI_AGENT_DIR . '/ai/runtime/session_' . $session_id . '.json';
    }

    /**
     * 保存会话缓存
     */
    public function saveSession($session_id, $messages)
    {
        $path = $this->getSessionPath($session_id);
        $data = [
            'uid'       => $this->uid,
            'messages'  => $messages,
            'updated'   => time(),
        ];
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 读取会话缓存
     */
    public function loadSession($session_id)
    {
        $path = $this->getSessionPath($session_id);
        if (!file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!$data) {
            return null;
        }

        // 检查是否过期
        $expire = intval($this->config['session_expire']);
        if (time() - $data['updated'] > $expire) {
            unlink($path);
            return null;
        }

        return $data['messages'] ?? [];
    }

    /**
     * 递增对话计数器
     */
    public function incrementCounter()
    {
        $storage = Storage::getInstance('ai_agent');
        $counter = $storage->getValue('memory_counter');
        $counter = intval($counter) + 1;
        $storage->setValue('memory_counter', $counter, 'number');
    }

    /**
     * 检查是否需要触发记忆整理
     */
    public function shouldTriggerCleanup()
    {
        $storage = Storage::getInstance('ai_agent');
        $counter = intval($storage->getValue('memory_counter'));
        $trigger = intval($this->config['memory_trigger']);
        return $counter >= $trigger;
    }

    /**
     * 触发异步记忆整理
     */
    public function triggerAsyncCleanup()
    {
        // 重置计数器
        $storage = Storage::getInstance('ai_agent');
        $storage->setValue('memory_counter', 0, 'number');

        // 清理过期会话缓存
        $this->cleanExpiredSessions();

        // 清理过期记忆
        $this->cleanExpiredMemories();
    }

    /**
     * 清理过期的会话缓存
     */
    private function cleanExpiredSessions()
    {
        $runtime_dir = AI_AGENT_DIR . '/ai/runtime';
        if (!is_dir($runtime_dir)) {
            return;
        }

        $expire = intval($this->config['session_expire']);
        $files = glob($runtime_dir . '/session_*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && (time() - $data['updated'] > $expire)) {
                unlink($file);
            }
        }
    }

    /**
     * 清理过期的用户记忆
     */
    private function cleanExpiredMemories()
    {
        $memory_dir = AI_AGENT_DIR . '/ai/memory';
        if (!is_dir($memory_dir)) {
            return;
        }

        $expire_days = intval($this->config['memory_expire_days']);
        $expire_time = time() - ($expire_days * 86400);

        $files = glob($memory_dir . '/*.MEMORY.md');
        foreach ($files as $file) {
            if (filemtime($file) < $expire_time) {
                unlink($file);
            }
        }
    }

    /**
     * 手动清空指定用户记忆
     */
    public function clearMemory($uid = 0)
    {
        $target_uid = $uid > 0 ? $uid : $this->uid;
        $path = AI_AGENT_DIR . '/ai/memory/' . $target_uid . '.MEMORY.md';
        if (file_exists($path)) {
            unlink($path);
            return true;
        }
        return false;
    }

    /**
     * 获取所有用户记忆列表
     */
    public function listMemories()
    {
        $memory_dir = AI_AGENT_DIR . '/ai/memory';
        if (!is_dir($memory_dir)) {
            return [];
        }

        $result = [];
        $files = glob($memory_dir . '/*.MEMORY.md');
        foreach ($files as $file) {
            $basename = basename($file, '.MEMORY.md');
            $size = filesize($file);
            $modified = filemtime($file);
            $result[] = [
                'uid'      => intval($basename),
                'size'     => $size,
                'modified' => date('Y-m-d H:i:s', $modified),
            ];
        }

        return $result;
    }
}
