// 模型预置选择
function ai_agent_apply_preset(model) {
    if (!model || model === 'custom') return;
    var presets = {
        // DeepSeek
        'deepseek-chat': {url: 'https://api.deepseek.com/v1/chat/completions', model: 'deepseek-chat'},
        'deepseek-reasoner': {url: 'https://api.deepseek.com/v1/chat/completions', model: 'deepseek-reasoner'},
        // 通义千问
        'qwen-plus': {url: 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', model: 'qwen-plus'},
        'qwen-turbo': {url: 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', model: 'qwen-turbo'},
        'qwen-max': {url: 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', model: 'qwen-max'},
        'qwen-long': {url: 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', model: 'qwen-long'},
        // 智谱 GLM
        'glm-4-flash': {url: 'https://open.bigmodel.cn/api/paas/v4/chat/completions', model: 'glm-4-flash'},
        'glm-4-plus': {url: 'https://open.bigmodel.cn/api/paas/v4/chat/completions', model: 'glm-4-plus'},
        'glm-4-long': {url: 'https://open.bigmodel.cn/api/paas/v4/chat/completions', model: 'glm-4-long'},
        // 月之暗面 Moonshot
        'moonshot-v1-8k': {url: 'https://api.moonshot.cn/v1/chat/completions', model: 'moonshot-v1-8k'},
        'moonshot-v1-32k': {url: 'https://api.moonshot.cn/v1/chat/completions', model: 'moonshot-v1-32k'},
        'moonshot-v1-128k': {url: 'https://api.moonshot.cn/v1/chat/completions', model: 'moonshot-v1-128k'},
        // 阶跃星辰 StepFun
        'step-1-8k': {url: 'https://api.stepfun.com/v1/chat/completions', model: 'step-1-8k'},
        'step-1-32k': {url: 'https://api.stepfun.com/v1/chat/completions', model: 'step-1-32k'},
        'step-1-128k': {url: 'https://api.stepfun.com/v1/chat/completions', model: 'step-1-128k'},
        'step-2-16k': {url: 'https://api.stepfun.com/v1/chat/completions', model: 'step-2-16k'},
        // 小米 MiMo
        'MiMo-7B-RL': {url: 'https://api.xiaomimimo.com/v1/chat/completions', model: 'MiMo-7B-RL'},
        // Agnes AI
        'agnes-2.0-flash': {url: 'https://apihub.agnes-ai.com/v1/chat/completions', model: 'agnes-2.0-flash'},
        // NVIDIA Build
        'nvidia/llama-3.1-nemotron-70b-instruct': {url: 'https://integrate.api.nvidia.com/v1/chat/completions', model: 'nvidia/llama-3.1-nemotron-70b-instruct'},
        'meta/llama-3.1-405b-instruct': {url: 'https://integrate.api.nvidia.com/v1/chat/completions', model: 'meta/llama-3.1-405b-instruct'},
        // 硅基流动
        'Qwen/Qwen3-8B': {url: 'https://api.siliconflow.cn/v1/chat/completions', model: 'Qwen/Qwen3-8B'},
        'Qwen/Qwen2.5-7B-Instruct': {url: 'https://api.siliconflow.cn/v1/chat/completions', model: 'Qwen/Qwen2.5-7B-Instruct'},
        'deepseek-ai/DeepSeek-V3': {url: 'https://api.siliconflow.cn/v1/chat/completions', model: 'deepseek-ai/DeepSeek-V3'},
        // OpenAI
        'gpt-4o': {url: 'https://api.openai.com/v1/chat/completions', model: 'gpt-4o'},
        'gpt-4o-mini': {url: 'https://api.openai.com/v1/chat/completions', model: 'gpt-4o-mini'},
        'gpt-3.5-turbo': {url: 'https://api.openai.com/v1/chat/completions', model: 'gpt-3.5-turbo'},
    };
    var p = presets[model];
    if (p) {
        document.getElementById('llm_api_url').value = p.url;
        document.getElementById('llm_model').value = p.model;
    }
}

// 图像模型预置选择
function ai_agent_apply_img_preset(model) {
    if (!model || model === 'custom') return;
    var presets = {
        'Kwai-Kolors/Kolors': {url: 'https://api.siliconflow.cn/v1/images/generations', model: 'Kwai-Kolors/Kolors'},
        'stabilityai/stable-diffusion-3-5-large': {url: 'https://api.siliconflow.cn/v1/images/generations', model: 'stabilityai/stable-diffusion-3-5-large'},
        'black-forest-labs/FLUX.1-schnell': {url: 'https://api.siliconflow.cn/v1/images/generations', model: 'black-forest-labs/FLUX.1-schnell'},
    };
    var p = presets[model];
    if (p) {
        document.getElementById('img_api_url').value = p.url;
        document.getElementById('img_model').value = p.model;
    }
}

/**
 * AI Agent 智能助手 - 后台 JS
 */

// 重置 SOUL 为默认
function ai_agent_reset_soul() {
    if (confirm('确认将 AI 人格重置为默认模板？当前修改将丢失。')) {
        location.href = './plugin.php?plugin=ai_agent&action=reset_soul';
    }
}

// 加载人格模板
function ai_agent_load_template(type) {
    var templates = {
        essay: '# AI 人格配置\n\n## 角色定位\n你是一个文艺随笔风格的博客助手，擅长用温暖细腻的文字表达。\n\n## 写作风格\n- 语言优美，富有画面感\n- 善用比喻和意象\n- 段落之间留白，节奏舒缓\n- 适当引用诗词或名言\n\n## 输出规范\n- 标题含蓄有意境\n- 正文不少于800字\n- 摘要精炼，点到即止',
        tech: '# AI 人格配置\n\n## 角色定位\n你是一个技术博客助手，专注于产出高质量的技术文章。\n\n## 写作风格\n- 逻辑清晰，条理分明\n- 代码示例完整可运行\n- 专业术语准确\n- 适当使用图表说明\n\n## 输出规范\n- 标题明确技术主题\n- 包含代码示例和说明\n- 正文不少于1000字\n- 摘要概括核心知识点',
        media: '# AI 人格配置\n\n## 角色定位\n你是一个自媒体风格的博客助手，擅长写吸引眼球的内容。\n\n## 写作风格\n- 标题党但不离谱\n- 口语化，接地气\n- 多用短句和感叹\n- 适当加入个人观点\n\n## 输出规范\n- 标题15字以内，有吸引力\n- 开头直击痛点\n- 正文500-800字\n- 摘要制造悬念',
        formal: '# AI 人格配置\n\n## 角色定位\n你是一个简约官方风格的博客助手。\n\n## 写作风格\n- 语言简洁专业\n- 结构化表达\n- 避免冗余修饰\n- 数据和事实支撑\n\n## 输出规范\n- 标题简洁明了\n- 段落主题明确\n- 正文600-1000字\n- 摘要客观概括'
    };

    if (templates[type]) {
        var textarea = document.querySelector('textarea[name="soul_content"]');
        if (textarea) {
            textarea.value = templates[type];
        }
    }
}

// 查看工具 Schema
function ai_agent_view_schema(toolName) {
    alert('工具 Schema 查看功能将在后续版本中提供可视化界面。当前请直接查看 ai/tools.json 文件。');
}

// 查看用户记忆
function ai_agent_view_memory(uid) {
    alert('记忆查看功能将在二期迭代中完善。当前请直接查看 ai/memory/' + uid + '.MEMORY.md 文件。');
}

// 运行定时任务
function ai_agent_run_cron() {
    if (confirm('确认运行定时任务？\n\n将执行：\n- 清理过期会话缓存\n- 清理过期对话历史\n- 记忆自动整理\n- 清理过期记忆文件')) {
        location.href = './plugin.php?plugin=ai_agent&action=run_cron';
    }
}
