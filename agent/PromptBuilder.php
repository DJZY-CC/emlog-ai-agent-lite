<?php
/**
 * AI Agent - Prompt 组装器
 * 负责组装完整的 System Prompt，包括人格、站点上下文、记忆等
 */

defined('EMLOG_ROOT') || exit('access denied!');

class AIAgent_PromptBuilder
{
    private $config;
    private $uid;

    public function __construct($config, $uid = 0)
    {
        $this->config = $config;
        $this->uid = $uid;
    }

    /**
     * 构建完整的 System Prompt
     */
    public function buildSystemPrompt()
    {
        $parts = [];

        // 1. 加载人格 SOUL
        $soul = $this->loadSoul();
        $parts[] = "## AI 人格与规则\n{$soul}";

        // 2. 加载站点上下文 BLOG
        $blog = $this->buildBlogContext();
        $parts[] = "## 当前博客站点信息\n{$blog}";

        // 3. 加载用户长期记忆
        if ($this->config['memory_enabled'] && $this->uid > 0) {
            $memory = $this->loadUserMemory();
            if (!empty($memory)) {
                $parts[] = "## 用户偏好记忆\n{$memory}";
            }
        }

        // 4. 工具使用说明
        $parts[] = "## 工具使用规范\n" . $this->getToolInstructions();

        // 5. 输出格式规范
        $parts[] = "## 输出规范\n" . $this->getOutputRules();

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * 加载 SOUL.md 人格配置
     */
    private function loadSoul()
    {
        $soul_file = AI_AGENT_DIR . '/ai/SOUL.md';
        if (file_exists($soul_file)) {
            $soul = file_get_contents($soul_file);
            // 注入当前配图模式描述
            $img_mode = $this->config['img_mode'] ?? 'generate';
            $mode_descs = [
                'generate' => '模式 A：纯文生图（仅使用 AI 生成图片）',
                'generate_then_search' => '模式 B：文生图优先 + 搜索降级（先AI生成，失败后搜索网络图片）',
                'search' => '模式 C：纯搜索（直接从网络搜索图片，不使用AI生成）',
            ];
            $soul = str_replace('{img_mode_desc}', $mode_descs[$img_mode] ?? $mode_descs['generate'], $soul);
            return $soul;
        }
        return '你是一个博客智能运维助手。';
    }

    /**
     * 构建站点上下文（运行时渲染，不落地文件）
     */
    private function buildBlogContext()
    {
        $db = MySql::getInstance();
        $blog_info = [];

        // 站点名称
        $blog_name = Option::get('blogname');
        $blog_info[] = "站点名称：{$blog_name}";

        // 站点描述
        $blog_desc = Option::get('bloginfo');
        if (!empty($blog_desc)) {
            $blog_info[] = "站点描述：{$blog_desc}";
        }

        // 站点URL
        $blog_url = Option::get('blogurl');
        if (!empty($blog_url)) {
            $blog_info[] = "站点地址：{$blog_url}";
            $blog_info[] = "文章链接由工具自动返回（url字段），禁止自行拼接";
        }

        // 分类列表
        $categories = $this->getCategories();
        if (!empty($categories)) {
            $cat_lines = [];
            foreach ($categories as $cat) {
                $cat_lines[] = "- [ID:{$cat['sid']}] {$cat['sortname']}";
            }
            $blog_info[] = "分类列表：\n" . implode("\n", $cat_lines);
        }

        // 常用标签
        $tags = $this->getRecentTags(max(5, intval($this->config['tag_recent_count'] ?? 20)));
        if (!empty($tags)) {
            $blog_info[] = "常用标签：" . implode('、', $tags);
        }

        // 文章统计
        $stats = $this->getBasicStats();
        $blog_info[] = "文章统计：共 {$stats['articles']} 篇文章，{$stats['categories']} 个分类，{$stats['comments']} 条评论";

        return implode("\n", $blog_info);
    }

    /**
     * 获取分类列表
     */
    private function getCategories()
    {
        $db = MySql::getInstance();
        $result = [];
        $query = $db->query("SELECT sid, sortname FROM " . DB_PREFIX . "sort ORDER BY sid ASC");
        while ($row = $db->fetch_array($query)) {
            $result[] = $row;
        }
        return $result;
    }

    /**
     * 获取最近使用的标签
     */
    private function getRecentTags($limit = 20)
    {
        $db = MySql::getInstance();
        $result = [];
        $query = $db->query("SELECT tagname FROM " . DB_PREFIX . "tag ORDER BY tid DESC LIMIT " . intval($limit));
        while ($row = $db->fetch_array($query)) {
            $result[] = $row['tagname'];
        }
        return $result;
    }

    /**
     * 获取基础统计数据
     */
    private function getBasicStats()
    {
        $db = MySql::getInstance();

        $articles = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "blog WHERE hide='n'");
        $categories = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "sort");
        $comments = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "comment");

        return [
            'articles'   => intval($articles['cnt']),
            'categories' => intval($categories['cnt']),
            'comments'   => intval($comments['cnt']),
        ];
    }

    /**
     * 加载用户长期记忆
     */
    private function loadUserMemory()
    {
        $memory_file = AI_AGENT_DIR . '/ai/memory/' . $this->uid . '.MEMORY.md';
        if (file_exists($memory_file)) {
            $content = file_get_contents($memory_file);
            // 限制记忆长度，防止 prompt 过长
            if (strlen($content) > max(1000, intval($this->config['memory_truncate'] ?? 3000))) {
                $content = substr($content, 0, max(1000, intval($this->config['memory_truncate'] ?? 3000))) . "\n\n[记忆已截断]";
            }
            return $content;
        }
        return '';
    }

    /**
     * 工具使用说明
     */
    private function getToolInstructions()
    {
        return <<<'INST'
你可以使用以下工具来操作博客：

1. 调用工具前，先分析用户意图，确定需要哪些工具
2. 如果需要发文，必须先调用 get_categories 获取分类ID
3. 如果用户提到了标签，先调用 get_tags 检查是否存在
4. 工具调用结果会自动返回，请基于结果继续处理
5. 如果工具调用失败，可以重试一次，仍然失败则告知用户
6. 禁止无意义地重复调用同一个工具
INST;
    }

    /**
     * 输出格式规范
     */
    private function getOutputRules()
    {
        return <<<'RULES'
- 使用中文回复
- 回复简洁明了，避免冗余
- 操作完成后给出明确的结果摘要
- 如果操作失败，说明原因并给出建议
- 文章内容使用 Markdown 格式
RULES;
    }

    /**
     * 构建工具定义（供 LLM Function Calling 使用）
     */
    public function getToolsDefinition()
    {
        $tools_file = AI_AGENT_DIR . '/ai/tools.json';
        if (file_exists($tools_file)) {
            $content = file_get_contents($tools_file);
            $tools = json_decode($content);
            if (is_array($tools)) {
                // Convert stdClass objects to arrays while preserving empty objects as {}
                return $tools;  // Return stdClass directly
            }
        }
        return [];
    }

    /**
     * Convert tools definition to array format, preserving empty objects
     */
    private function convertToolsToArray($tools)
    {
        $result = [];
        foreach ($tools as $tool) {
            $item = [
                'name'        => $tool->name,
                'description' => $tool->description,
                'parameters'  => $this->convertParamsToArray($tool->parameters),
            ];
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Convert parameters object to array, preserving empty objects as {}
     */
    private function convertParamsToArray($params)
    {
        if (!is_object($params) && !is_array($params)) {
            return $params;
        }
	// Fix: empty object must remain as object, not array
	if (is_object($params) && empty((array)$params)) return new \stdClass();
        $result = [];
        foreach ($params as $key => $value) {
            if (is_object($value)) {
                // Check if it's an empty object
                if (count((array)$value) === 0) {
                    $result[$key] = new \stdClass();
                } else {
                    $result[$key] = $this->convertParamsToArray($value);
                }
            } elseif (is_array($value)) {
                $result[$key] = $this->convertParamsToArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
