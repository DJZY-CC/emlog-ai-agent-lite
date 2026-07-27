<?php
/*
Plugin Name: AI Agent 智能助手
Version: 1.0.5
Plugin URL: https://www.emlog.net/plugin/detail/ai_agent
Description: 首款基于 ReAct Agent + 工具调用 + 三层记忆系统的博客智能运维助手，让AI真正"读懂你的博客"。
Author: AI Agent Team
Author URL: https://www.emlog.net/profiles/ai_agent
*/

defined('EMLOG_ROOT') || exit('access denied!');

// 插件常量
define('AI_AGENT_VERSION', '1.0.5');
define('AI_AGENT_DIR', __DIR__);
define('AI_AGENT_URL', '../content/plugins/ai_agent');

// 加载核心文件
require_once AI_AGENT_DIR . '/agent/PromptBuilder.php';
require_once AI_AGENT_DIR . '/agent/ToolRunner.php';
require_once AI_AGENT_DIR . '/agent/AgentLoop.php';
require_once AI_AGENT_DIR . '/agent/MemoryManager.php';
require_once AI_AGENT_DIR . '/agent/ChatHistory.php';

/**
 * 获取插件配置
 */
function ai_agent_get_config()
{
    // Q-004: static cache
    static $cache = null;
    if ($cache !== null) return $cache;
    $storage = Storage::getInstance('ai_agent');
    $stored = $storage->getValue('config');

    // 默认配置（新字段在此添加，旧安装自动获得）
    $defaults = [
        // LLM 配置
        'llm_api_url'       => '',
        'llm_api_key'       => '',
        'llm_model'         => 'deepseek-v4-pro',
        'llm_temperature'   => 0.7,
        'llm_max_tokens'    => 2000,
        'llm_timeout'       => 60,

        // Agent 配置
        'agent_enabled'     => 1,
        'agent_max_rounds'  => 3,
        'agent_retry'       => 1,
        'agent_history_limit' => 20,

        // 记忆配置
        'memory_enabled'    => 1,
        'memory_trigger'    => 10,
        'memory_expire_days'=> 90,
        'session_expire'    => 1800,
        'memory_max_size'   => 10,
        'memory_truncate'   => 3000,

        // 安全配置
        'guest_allowed'     => 0,
        'danger_block'      => 1,
        'draft_default'     => 1,
        'publish_roles'     => ['admin'],
        'guest_daily_limit' => 5,
        'user_daily_limit'  => 50,
        'admin_daily_limit' => 0,

        // 安全增强
        'disabled_tools'    => '',
        'rate_limit_per_min' => 20,
        'rate_limit_per_hour' => 200,
        'admin_only_tool'   => 'publish_article,update_article,batch_generate_covers,batch_add_tags',
        'content_filter'    => 1,
    'reply_author_name' => 'AI Assistant',
        'max_input_length'  => 2000,

        // 配图配置
        'img_api_url'       => '',
        'img_api_key'       => '',
        'img_model'         => 'Kwai-Kolors/Kolors',
        'img_size'          => '1024x1024',
        'img_dir'           => 'content/upload/ai_images/',
        'img_compress_enabled' => 1, 'img_compress_quality' => 80, 'img_compress_max_width' => 1920,
        'img_batch_limit'   => 5,
        'img_batch_interval' => 1,

        // 配图模式: generate(纯文生图) / generate_then_search(文生图优先+搜索降级) / search(纯搜索)
        'img_mode'            => 'generate',
        // 图片搜索配置（支持多源）
        'img_search_provider' => 'pexels',
        'img_search_api_key'  => '',
        'img_search_unsplash_key' => '',
        'img_search_pixabay_key'  => '',

        // 标签配置
        'tag_query_limit'   => 50,
        'tag_recent_count'  => 20,

        // 前台配置
        'frontend_timeout'  => 120,
        'panel_width'       => 420,
        'panel_height'      => 580,

        // 日志配置
        'error_log_enabled' => 1,
        'error_log_path'    => '',
        'debug_log_enabled' => 0,

        // Prompt 配置
        'system_prompt_max' => 8000,

        // 版本信息
        'version'           => AI_AGENT_VERSION,
        'auto_publish_enabled' => 0, 'auto_publish_interval' => 3600, 'auto_publish_daily_max' => 5,
        'auto_publish_hour_start' => 8, 'auto_publish_hour_end' => 22,
        'auto_publish_sortid' => 0, 'auto_publish_tags' => '', 'auto_publish_prompt' => '',
        'auto_publish_last_run' => 0, 'auto_publish_last_success' => 0, 'auto_publish_run_count' => 0,
    ];

    // 总是合并默认值（确保旧安装也能获得新字段，导出时字段完整）
    if (empty($stored)) {
        $config = $defaults;
    } else {
        $config = array_merge($defaults, $stored);
    }
    $cache = $config;
    return $config;
}

/**
 * 保存插件配置
 */
function ai_agent_save_config($config)
{
    $storage = Storage::getInstance('ai_agent');
    $config['version'] = AI_AGENT_VERSION;
    $storage->setValue('config', $config, 'array');
}

/**
 * 加载后台样式和脚本
 */
function ai_agent_admin_head()
{
    echo '<link rel="stylesheet" href="' . AI_AGENT_URL . '/assets/css/admin.css?v=' . AI_AGENT_VERSION . '">' . "\n";
    echo '<script src="' . AI_AGENT_URL . '/assets/js/admin.js?v=' . AI_AGENT_VERSION . '"></script>' . "\n";
}
// 处理导出/导入请求
// 伪 Cron：自动发文触发（随访客访问触发）
function ai_agent_cron_trigger()
{
    // 只在前台页面触发，后台不触发
    if (defined('ADMIN_ROOT')) return;
    // 概率触发（1/10 请求），避免每次访问都检查
    if (mt_rand(1, 10) !== 1) return;

    require_once __DIR__ . '/cron.php';
    ai_agent_auto_publish_trigger();
}
addAction('index_footer', 'ai_agent_cron_trigger');

addAction('adm_head', 'ai_agent_admin_head');

/**
 * 后台底部加载脚本
 */
function ai_agent_admin_footer()
{
    $config = ai_agent_get_config();
    echo '<script>var AI_AGENT_URL="' . AI_AGENT_URL . '";var AI_AGENT_VERSION="' . AI_AGENT_VERSION . '";</script>' . "\n";
}
addAction('adm_footer', 'ai_agent_admin_footer');

/**
 * 前台加载对话窗口
 */
function ai_agent_index_footer()
{
    $config = ai_agent_get_config();
    // 检查访客权限
    $uid = UID;
    if (!$config['guest_allowed'] && $uid < 1) {
        return;
    }
    ?>
    <link rel="stylesheet" href="<?php echo AI_AGENT_URL; ?>/assets/css/frontend.css?v=<?php echo AI_AGENT_VERSION; ?>">
    <style>
    #ai-agent-panel { width: <?php echo max(300, intval($config['panel_width'] ?? 420)); ?>px; height: <?php echo max(400, intval($config['panel_height'] ?? 580)); ?>px; }
    </style>
    <div id="ai-agent-widget" data-timeout="<?php echo max(30, intval($config['frontend_timeout'] ?? 120)); ?>">
        <button id="ai-agent-toggle" title="AI 智能助手">🤖</button>
        <div id="ai-agent-panel" style="display:none;">
            <div class="ai-agent-header">
                <span>🤖 AI 智能助手</span>
                <div class="ai-agent-actions">
                    <button id="ai-agent-newchat" title="新对话">+</button>
                    <button id="ai-agent-close" title="关闭">×</button>
                </div>
            </div>
            <div id="ai-agent-messages"></div>
            <div class="ai-agent-input-area">
                <textarea id="ai-agent-input" placeholder="输入指令，如：帮我写一篇关于AI的文章..." rows="2"></textarea>
                <button id="ai-agent-send">发送</button>
            </div>
        </div>
    </div>
    <script>
    var AI_AGENT_API = "<?php echo rtrim(Option::get('blogurl'), '/'); ?>/?plugin=ai_agent&action=";
    </script>
    <script src="<?php echo AI_AGENT_URL; ?>/assets/js/frontend.js?v=<?php echo AI_AGENT_VERSION; ?>"></script>
    <?php
}
addAction('index_footer', 'ai_agent_index_footer');

/**
 * 文章保存时的钩子 - 可用于AI自动摘要等
 */
