<?php
/**
 * AI Agent 智能助手 - 插件事件回调
 */

defined('EMLOG_ROOT') || exit('access denied!');

// emlog enable plugin only loads callback file, not main file
require_once __DIR__ . '/ai_agent.php';

// require_once __DIR__ . "/agent/ChatHistory.php";
/**
 * 插件开启时初始化
 */
function callback_init()
{
    // Check emlog cache directory writability
    $cache_dir = EMLOG_ROOT . '/content/cache';
    if (!is_dir($cache_dir) || !is_writable($cache_dir)) {
        emMsg('AI Agent plugin activation failed: emlog cache directory <code>content/cache</code> is not writable. Please check directory permissions (755 or 777) and try again.');
        return;
    }

    $storage = Storage::getInstance('ai_agent');

    // 初始化默认配置
    $config = $storage->getValue('config');
    if (empty($config)) {
    // H-002 fix: use ai_agent_get_config() to keep defaults consistent with ai_agent.php
    $default_config = ai_agent_get_config();
    $storage->setValue('config', $default_config, 'array');
    }

    // 创建记忆目录（如果不存在）
    $memory_dir = AI_AGENT_DIR . '/ai/memory';
    if (!is_dir($memory_dir)) {
        mkdir($memory_dir, 0755, true);
    }

    $runtime_dir = AI_AGENT_DIR . '/ai/runtime';
    if (!is_dir($runtime_dir)) {
        mkdir($runtime_dir, 0755, true);
    }

    // 创建日志目录
    $logs_dir = AI_AGENT_DIR . '/ai/logs';
    if (!is_dir($logs_dir)) {
        mkdir($logs_dir, 0755, true);
    }

    // 初始化默认 SOUL.md
    $soul_file = AI_AGENT_DIR . '/ai/SOUL.md';
    if (!file_exists($soul_file)) {
        $default_soul = ai_agent_get_default_soul();
        file_put_contents($soul_file, $default_soul);
    }

    // 初始化默认 tools.json
    $tools_file = AI_AGENT_DIR . '/ai/tools.json';
    if (!file_exists($tools_file)) {
        $default_tools = ai_agent_get_default_tools_json();
        file_put_contents($tools_file, $default_tools);
    }

    // 创建对话历史数据表
    $chat_history = new AIAgent_ChatHistory();
    $chat_history->createTable();
}

/**
 * 插件删除时清理所有数据
 */
function callback_rm()
{
    $storage = Storage::getInstance('ai_agent');
    $storage->deleteAllName('YES');

    // 清理记忆文件
    $memory_dir = AI_AGENT_DIR . '/ai/memory';
    if (is_dir($memory_dir)) {
        $files = glob($memory_dir . '/*.md');
        foreach ($files as $file) {
            unlink($file);
        }
    }

    // 清理运行时缓存
    $runtime_dir = AI_AGENT_DIR . '/ai/runtime';
    if (is_dir($runtime_dir)) {
        $files = glob($runtime_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    // 删除对话历史表
    $chat_history = new AIAgent_ChatHistory();
    $chat_history->dropTable();
}

/**
 * 插件更新时执行
 */
function callback_up()
{
    $storage = Storage::getInstance('ai_agent');
    $config = $storage->getValue('config');

    if (!empty($config)) {
        // H-003 fix: merge defaults to persist new fields on upgrade
        $defaults = ai_agent_get_config();
        $config = array_merge($defaults, $config);
        $config['version'] = AI_AGENT_VERSION;
        $storage->setValue('config', $config, 'array');
    } else {
        $default_config = ai_agent_get_config();
        $storage->setValue('config', $default_config, 'array');
    }
}

/**
 * 获取默认 SOUL.md 内容
 */
function ai_agent_get_default_soul()
{
    return <<<'SOUL'
# AI Agent 人格配置

## 角色定位
你是一个博客智能运维助手，帮助博主管理博客内容、撰写文章、优化SEO。

## 写作风格
- 语言流畅自然，避免AI腔调
- 段落清晰，适当使用小标题
- 结合站点已有分类和标签

## 输出规范
- 文章标题简洁有力，不超过30个字
- 正文不少于500字
- 自动生成摘要（150字以内）
- 推荐合适的分类和标签

## 禁止行为
- 不生成违法违规内容
- 不删除用户文章（除非明确要求）
- 不修改系统核心配置
- 不泄露API密钥等敏感信息

## 操作规范
- 发文前先查询分类列表，确保分类存在
- 修改文章前先获取文章详情，确认存在
- 配图描述应与文章主题相关
SOUL;
}

/**
 * 获取默认 tools.json 内容
 */
function ai_agent_get_default_tools_json()
{
    $tools = [
        [
            'name'        => 'get_categories',
            'description' => '获取博客全站分类列表（ID和名称），发文前必须先调用此工具确认分类',
            'parameters'  => [
                'type'       => 'object',
                'properties' => new stdClass(),
                'required'   => [],
            ],
        ],
        [
            'name'        => 'get_tags',
            'description' => '获取博客已有标签列表，避免创建重复标签',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'keyword' => ['type' => 'string', 'description' => '搜索关键词（可选）'],
                ],
                'required' => [],
            ],
        ],
        [
            'name'        => 'search_articles',
            'description' => '全文搜索博客文章',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'keyword' => ['type' => 'string', 'description' => '搜索关键词'],
                    'page'    => ['type' => 'integer', 'description' => '页码，默认1'],
                    'limit'   => ['type' => 'integer', 'description' => '每页数量，默认10'],
                ],
                'required' => ['keyword'],
            ],
        ],
        [
            'name'        => 'get_articles',
            'description' => '按分类或排序方式获取文章列表',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'sortid' => ['type' => 'integer', 'description' => '分类ID（可选）'],
                    'order'  => ['type' => 'string', 'description' => '排序方式：new/old/hot（默认new）'],
                    'limit'  => ['type' => 'integer', 'description' => '数量限制，默认10'],
                    'page'   => ['type' => 'integer', 'description' => '页码，默认1'],
                ],
                'required' => [],
            ],
        ],
        [
            'name'        => 'get_article',
            'description' => '获取单篇文章的完整内容',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'aid' => ['type' => 'integer', 'description' => '文章ID'],
                ],
                'required' => ['aid'],
            ],
        ],
        [
            'name'        => 'publish_article',
            'description' => '发布新文章到博客',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'title'          => ['type' => 'string', 'description' => '文章标题'],
                    'content'        => ['type' => 'string', 'description' => '文章正文内容（支持HTML/Markdown）'],
                    'sortid'         => ['type' => 'integer', 'description' => '分类ID'],
                    'tags'           => ['type' => 'string', 'description' => '标签，多个用逗号分隔'],
                    'top'            => ['type' => 'integer', 'description' => '是否置顶：0否/1是'],
                    'allow_comment'  => ['type' => 'integer', 'description' => '允许评论：0否/1是'],
                ],
                'required' => ['title', 'content'],
            ],
        ],
        [
            'name'        => 'update_article',
            'description' => '修改已有文章的内容、分类、标签等',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'aid'     => ['type' => 'integer', 'description' => '文章ID'],
                    'title'   => ['type' => 'string', 'description' => '新标题（可选）'],
                    'content' => ['type' => 'string', 'description' => '新内容（可选）'],
                    'sortid'  => ['type' => 'integer', 'description' => '新分类ID（可选）'],
                    'tags'    => ['type' => 'string', 'description' => '新标签（可选）'],
                ],
                'required' => ['aid'],
            ],
        ],
        [
            'name'        => 'generate_image',
            'description' => '使用AI生成文章封面配图',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'prompt' => ['type' => 'string', 'description' => '图片描述提示词'],
                    'size'   => ['type' => 'string', 'description' => '图片尺寸，如1024x1024'],
                ],
                'required' => ['prompt'],
            ],
        ],
        [
            'name'        => 'get_stats',
            'description' => '获取博客站点统计数据（文章数、分类数、评论数等）',
            'parameters'  => [
                'type'       => 'object',
                'properties' => new stdClass(),
                'required'   => [],
            ],
        ],
    ];

    return json_encode($tools, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}


/**
 * AI auto summary + tag recommendation (save_log hook)
 * Generates excerpt and tags when article is saved
 */
// Lite 版：不含 AI 自动摘要和标签推荐功能
