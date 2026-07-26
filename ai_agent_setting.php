<?php
/**
 * AI Agent 智能助手 - 后台设置页面
 */

defined('EMLOG_ROOT') || exit('access denied!');

function plugin_setting_view()
{
    $config = ai_agent_get_config();
    $active_tab = Input::getStrVar('tab', 'basic');
    ?>
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h4 mb-0 text-gray-800">
            <i class="fas fa-robot mr-2"></i>AI Agent 智能助手
            <small class="text-muted ml-2">v<?php echo AI_AGENT_VERSION; ?></small>
        </h1>
    </div>

    <!-- Tab 导航 -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'basic' ? 'active' : ''; ?>" href="./plugin.php?plugin=ai_agent&tab=basic">
                <i class="fas fa-cog mr-1"></i>基础设置
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'soul' ? 'active' : ''; ?>" href="./plugin.php?plugin=ai_agent&tab=soul">
                <i class="fas fa-theater-masks mr-1"></i>AI 人格
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'tools' ? 'active' : ''; ?>" href="./plugin.php?plugin=ai_agent&tab=tools">
                <i class="fas fa-tools mr-1"></i>工具管理
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'memory' ? 'active' : ''; ?>" href="./plugin.php?plugin=ai_agent&tab=memory">
                <i class="fas fa-brain mr-1"></i>记忆管理
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'autopub' ? 'active' : ''; ?>" href="./plugin.php?plugin=ai_agent&tab=autopub">
                <i class="fas fa-clock mr-1"></i>自动发文
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'logs' ? 'active' : ''; ?>" href="./plugin.php?plugin=ai_agent&tab=logs">
                <i class="fas fa-list-alt mr-1"></i>执行日志
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'advanced' ? 'active' : ''; ?>" href="./plugin.php?plugin=ai_agent&tab=advanced">
                <i class="fas fa-sliders-h mr-1"></i>高级设置
            </a>
        </li>
    </ul>

    <!-- Tab 内容 -->
    <?php
    switch ($active_tab) {
        case 'basic':
            ai_agent_view_basic($config);
            break;
        case 'soul':
            ai_agent_view_soul($config);
            break;
        case 'tools':
            ai_agent_view_tools($config);
            break;
        case 'memory':
            ai_agent_view_memory($config);
            break;
        case 'autopub':
            ai_agent_view_autopub($config);
            break;
        case 'logs':
            ai_agent_view_logs($config);
            break;
        case 'advanced':
            ai_agent_view_advanced($config);
            break;
    }
    ?>

    <script>
        setTimeout(hideActived, 3600);
        $("#menu_category_ext").addClass('active');

        // 导出设置
        window.ai_agent_export_config = function() {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = './plugin.php?plugin=ai_agent&action=setting';
            form.target = '_blank';
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ai_agent_action';
            input.value = 'export_config';
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        };

        // 导入设置
        window.ai_agent_import_config = function(input) {
            if (!input.files || !input.files[0]) return;
            var file = input.files[0];
            if (file.size > 1024 * 1024) {
                cocoMessage.error('文件过大');
                return;
            }
            var reader = new FileReader();
            reader.onload = function(e) {
                try {
                    var config = JSON.parse(e.target.result);
                    if (!config.llm_api_url && !config.llm_model) {
                        cocoMessage.error('无效的配置文件');
                        return;
                    }
                    if (!confirm('确认导入设置？这会覆盖当前所有配置。')) return;

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', './plugin.php?plugin=ai_agent&action=setting', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.code === 0) {
                                cocoMessage.success('导入成功，页面将刷新');
                                setTimeout(function() { location.reload(); }, 1500);
                            } else {
                                cocoMessage.error(resp.msg || '导入失败');
                            }
                        } catch(ex) {
                            cocoMessage.error('导入失败');
                        }
                    };
                    xhr.send('ai_agent_action=import_config&config_data=' + encodeURIComponent(JSON.stringify(config)));
                } catch(ex) {
                    cocoMessage.error('文件格式错误，请选择 JSON 文件');
                }
            };
            reader.readAsText(file);
            input.value = '';
        };

        // 异步提交表单
        $(".ai-agent-form").submit(function(event) {
            event.preventDefault();
            submitForm("#" + $(this).attr("id"));
        });
    </script>
    <?php
}

/**
 * 基础设置 Tab
 */
function ai_agent_view_basic($config)
{
    ?>
    <!-- 导出/导入 -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-exchange-alt mr-2"></i>设置导入/导出</h6>
            <div>
                <button type="button" class="btn btn-sm btn-outline-primary mr-2" onclick="ai_agent_export_config()"><i class="fas fa-download mr-1"></i>导出设置</button>
                <button type="button" class="btn btn-sm btn-outline-success" onclick="document.getElementById('ai_agent_import_file').click()"><i class="fas fa-upload mr-1"></i>导入设置</button>
                <input type="file" id="ai_agent_import_file" style="display:none" accept=".json" onchange="ai_agent_import_config(this)">
            </div>
        </div>
        <div class="card-body py-2">
            <small class="text-muted">导出当前所有设置为 JSON 文件，或从文件导入设置。导入会覆盖当前配置。</small>
            <div id="ai_agent_import_result" class="mt-2" style="display:none"></div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">LLM 接口配置</h6>
        </div>
        <div class="card-body">
            <form method="post" id="ai_agent_basic_form" class="ai-agent-form" action="./plugin.php?plugin=ai_agent&action=save_setting">
                <input type="hidden" name="ai_agent_action" value="save_basic">
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">快速选择</label>
                    <div class="col-sm-9">
                        <select id="ai_agent_preset" class="form-control" onchange="ai_agent_apply_preset(this.value)">
                            <option value="">-- 选择预置模型（可选） --</option>
                            <optgroup label="⭐ 推荐">
                                <option value="deepseek-v4-pro">DeepSeek V4 Pro（推荐）</option>
                            </optgroup>
                            <optgroup label="DeepSeek">
                                <option value="deepseek-v4-pro">DeepSeek V4 Pro</option>
                                <option value="deepseek-v4-flash">DeepSeek V4 Flash（推理）</option>
                            </optgroup>
                            <optgroup label="阿里云 通义千问">
                                <option value="qwen-plus">Qwen Plus（性价比）</option>
                                <option value="qwen-turbo">Qwen Turbo（快速）</option>
                                <option value="qwen-max">Qwen Max（最强）</option>
                                <option value="qwen-long">Qwen Long（长文本）</option>
                            </optgroup>
                            <optgroup label="智谱 AI (新用户送2000万Token)">
                                <option value="glm-4-flash">GLM-4 Flash（永久免费）</option>
                                <option value="glm-4-plus">GLM-4 Plus</option>
                                <option value="glm-4-long">GLM-4 Long（长文本）</option>
                            </optgroup>
                            <optgroup label="月之暗面 (Moonshot)">
                                <option value="moonshot-v1-8k">Moonshot V1 8K</option>
                                <option value="moonshot-v1-32k">Moonshot V1 32K</option>
                                <option value="moonshot-v1-128k">Moonshot V1 128K（长文本）</option>
                            </optgroup>
                            <optgroup label="阶跃星辰（注册送15天免费）">
                                <option value="step-1-8k">Step-1 8K</option>
                                <option value="step-1-32k">Step-1 32K</option>
                                <option value="step-1-128k">Step-1 128K</option>
                                <option value="step-2-16k">Step-2 16K（最新）</option>
                            </optgroup>
                            <optgroup label="小米 MiMo（新用户送¥10体验金）">
                                <option value="MiMo-7B-RL">MiMo-7B-RL（免费）</option>
                            </optgroup>
                            <optgroup label="Agnes AI">
                                <option value="agnes-2.0-flash">Agnes 2.0 Flash</option>
                            </optgroup>
                            <optgroup label="NVIDIA (Build)">
                                <option value="nvidia/llama-3.1-nemotron-70b-instruct">Nemotron 70B</option>
                                <option value="meta/llama-3.1-405b-instruct">Llama 3.1 405B</option>
                            </optgroup>
                            <optgroup label="硅基流动（免费14个模型）">
                                <option value="Qwen/Qwen3-8B">Qwen3-8B（免费）</option>
                                <option value="Qwen/Qwen2.5-7B-Instruct">Qwen2.5-7B（免费）</option>
                                <option value="deepseek-ai/DeepSeek-V3">DeepSeek V3（免费）</option>
                            </optgroup>
                            <optgroup label="OpenAI">
                                <option value="gpt-4o">GPT-4o</option>
                                <option value="gpt-4o-mini">GPT-4o Mini</option>
                                <option value="gpt-3.5-turbo">GPT-3.5 Turbo</option>
                            </optgroup>
                            <optgroup label="其他">
                                <option value="agnes-image-2.1-flash">Agnes Image 2.1 Flash</option>
                            <option value="custom">自定义模型...</option>
                            </optgroup>
                        </select>
                        <small class="form-text text-muted">选择后自动填充接口地址和模型名称，也可手动修改</small>
                        <div class="mt-2" style="font-size:12px;line-height:1.8">
                            <strong>💰 免费注册领取额度：</strong><br>
                            <a href="https://cloud.siliconflow.cn/i/dIi69w1x" target="_blank">硅基流动</a> 注册送¥16 · 
                            <a href="https://www.bigmodel.cn/invite?icode=G%2F3xWlGjP692EmpwczmPIX3uFJ1nZ0jLLgipQkYjpcA%3D" target="_blank">智谱GLM</a> 送2000万Token · 
                            <a href="https://platform.stepfun.com/?invite_code=LAQAJGQJ" target="_blank">阶跃星辰</a> 送15天免费 · 
                            <a href="https://platform.xiaomimimo.com/?ref=2ZV9NV" target="_blank">小米MiMo</a> 送¥10体验金
                        </div>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">接口地址</label>
                    <div class="col-sm-9">
                        <input type="text" name="llm_api_url" id="llm_api_url" class="form-control" value="<?php echo htmlspecialchars($config['llm_api_url']); ?>" placeholder="https://api.deepseek.com/v1/chat/completions">
                        <small class="form-text text-muted">支持 OpenAI 协议的 API 地址（DeepSeek / 通义千问 / 智谱 等）</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">API 密钥</label>
                    <div class="col-sm-9">
                        <input type="password" name="llm_api_key" id="llm_api_key" class="form-control" value="<?php echo htmlspecialchars($config['llm_api_key']); ?>" placeholder="sk-****">
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">模型名称</label>
                    <div class="col-sm-9">
                        <input type="text" name="llm_model" id="llm_model" class="form-control" value="<?php echo htmlspecialchars($config['llm_model']); ?>" placeholder="deepseek-v4-pro">
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">温度值</label>
                    <div class="col-sm-9">
                        <input type="number" name="llm_temperature" class="form-control" value="<?php echo $config['llm_temperature']; ?>" min="0" max="1" step="0.1">
                        <small class="form-text text-muted">0=确定性 1=随机性，推荐 0.7</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">最大生成长度</label>
                    <div class="col-sm-9">
                        <input type="number" name="llm_max_tokens" class="form-control" value="<?php echo $config['llm_max_tokens']; ?>" min="100" max="8000">
                    </div>
                </div>
                <hr>
                <h6 class="font-weight-bold text-primary">Agent 循环配置</h6>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">开启 Agent 多轮循环</label>
                    <div class="col-sm-9">
                        <select name="agent_enabled" class="form-control">
                            <option value="1" <?php echo $config['agent_enabled'] ? 'selected' : ''; ?>>开启</option>
                            <option value="0" <?php echo !$config['agent_enabled'] ? 'selected' : ''; ?>>关闭（单轮模式）</option>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">最大工具轮次</label>
                    <div class="col-sm-9">
                        <select name="agent_max_rounds" class="form-control">
                            <option value="1" <?php echo $config['agent_max_rounds'] == 1 ? 'selected' : ''; ?>>1 轮</option>
                            <option value="2" <?php echo $config['agent_max_rounds'] == 2 ? 'selected' : ''; ?>>2 轮</option>
                            <option value="3" <?php echo $config['agent_max_rounds'] == 3 ? 'selected' : ''; ?>>3 轮（推荐）</option>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">失败重试次数</label>
                    <div class="col-sm-9">
                        <select name="agent_retry" class="form-control">
                            <option value="0" <?php echo $config['agent_retry'] == 0 ? 'selected' : ''; ?>>不重试</option>
                            <option value="1" <?php echo $config['agent_retry'] == 1 ? 'selected' : ''; ?>>1 次（推荐）</option>
                            <option value="2" <?php echo $config['agent_retry'] == 2 ? 'selected' : ''; ?>>2 次</option>
                        </select>
                    </div>
                </div>
                <hr>
                <h6 class="font-weight-bold text-primary">记忆系统配置</h6>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">长期记忆</label>
                    <div class="col-sm-9">
                        <select name="memory_enabled" class="form-control">
                            <option value="1" <?php echo $config['memory_enabled'] ? 'selected' : ''; ?>>开启</option>
                            <option value="0" <?php echo !$config['memory_enabled'] ? 'selected' : ''; ?>>关闭</option>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">自动整理触发次数</label>
                    <div class="col-sm-9">
                        <input type="number" name="memory_trigger" class="form-control" value="<?php echo $config['memory_trigger']; ?>" min="5" max="100">
                        <small class="form-text text-muted">每N次对话触发一次记忆整理</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">记忆过期天数</label>
                    <div class="col-sm-9">
                        <input type="number" name="memory_expire_days" class="form-control" value="<?php echo $config['memory_expire_days']; ?>" min="7" max="365">
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">会话缓存时间（秒）</label>
                    <div class="col-sm-9">
                        <input type="number" name="session_expire" class="form-control" value="<?php echo $config['session_expire']; ?>" min="300" max="86400">
                    </div>
                </div>
                <hr>
                <h6 class="font-weight-bold text-primary">访客权限</h6>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">访客使用 AI</label>
                    <div class="col-sm-9">
                        <select name="guest_allowed" class="form-control">
                            <option value="0" <?php echo !$config['guest_allowed'] ? 'selected' : ''; ?>>禁止（仅登录用户）</option>
                            <option value="1" <?php echo $config['guest_allowed'] ? 'selected' : ''; ?>>允许</option>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">访客每日对话上限</label>
                    <div class="col-sm-9">
                        <input type="number" name="guest_daily_limit" class="form-control" value="<?php echo $config['guest_daily_limit'] ?? 5; ?>" min="0" max="100">
                        <small class="form-text text-muted">0=不限制，建议设置限制防止滥用</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">注册用户每日对话上限</label>
                    <div class="col-sm-9">
                        <input type="number" name="user_daily_limit" class="form-control" value="<?php echo $config['user_daily_limit'] ?? 50; ?>" min="0" max="500">
                        <small class="form-text text-muted">0=不限制</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">管理员每日对话上限</label>
                    <div class="col-sm-9">
                        <input type="number" name="admin_daily_limit" class="form-control" value="<?php echo $config['admin_daily_limit'] ?? 0; ?>" min="0" max="9999">
                        <small class="form-text text-muted">0=不限制</small>
                    </div>
                </div>

                <h6 class="font-weight-bold text-primary mt-4">发文权限</h6>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">允许 AI 发文的角色</label>
                    <div class="col-sm-9">
                        <div class="custom-control custom-checkbox mb-1">
                            <input type="checkbox" name="publish_roles[]" value="admin" class="custom-control-input" id="role_admin" <?php echo in_array('admin', $config['publish_roles'] ?? ['admin']) ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="role_admin">管理员</label>
                        </div>
                        <div class="custom-control custom-checkbox mb-1">
                            <input type="checkbox" name="publish_roles[]" value="editor" class="custom-control-input" id="role_editor" <?php echo in_array('editor', $config['publish_roles'] ?? []) ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="role_editor">编辑</label>
                        </div>
                        <div class="custom-control custom-checkbox mb-1">
                            <input type="checkbox" name="publish_roles[]" value="writer" class="custom-control-input" id="role_writer" <?php echo in_array('writer', $config['publish_roles'] ?? []) ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="role_writer">作者</label>
                        </div>
                        <div class="custom-control custom-checkbox mb-1">
                            <input type="checkbox" name="publish_roles[]" value="visitor" class="custom-control-input" id="role_visitor" <?php echo in_array('visitor', $config['publish_roles'] ?? []) ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="role_visitor">注册用户（无角色）</label>
                        </div>
                        <small class="form-text text-muted">勾选的角色可通过 AI 发布文章</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">AI 发文默认存草稿</label>
                    <div class="col-sm-9">
                        <select name="draft_default" class="form-control">
                            <option value="1" <?php echo ($config['draft_default'] ?? 1) ? 'selected' : ''; ?>>是（推荐，需审核）</option>
                            <option value="0" <?php echo !($config['draft_default'] ?? 1) ? 'selected' : ''; ?>>否（直接发布）</option>
                        </select>
                    </div>
                </div>

                <h6 class="font-weight-bold text-primary mt-4">安全防护</h6>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">高危操作拦截</label>
                    <div class="col-sm-9">
                        <select name="danger_block" class="form-control">
                            <option value="1" <?php echo $config['danger_block'] ? 'selected' : ''; ?>>开启（推荐）</option>
                            <option value="0" <?php echo !$config['danger_block'] ? 'selected' : ''; ?>>关闭</option>
                        </select>
                        <small class="form-text text-muted">拦截批量删除、清空站点等危险操作</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">用户单次输入长度限制</label>
                    <div class="col-sm-9">
                        <input type="number" name="max_input_length" class="form-control" value="<?php echo $config['max_input_length'] ?? 2000; ?>" min="100" max="10000">
                        <small class="form-text text-muted">字符数，防止超长输入消耗过多Token</small>
                    </div>
                </div>
                <hr>
                <h6 class="font-weight-bold text-primary">配图 API 配置</h6>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">快速选择</label>
                    <div class="col-sm-9">
                        <select id="ai_agent_img_preset" class="form-control" onchange="ai_agent_apply_img_preset(this.value)">
                            <option value="">-- 选择预置绘图模型 --</option>
                            <option value="Kwai-Kolors/Kolors">快手 Kolors（推荐）</option>
                            <option value="stabilityai/stable-diffusion-3-5-large">Stable Diffusion 3.5</option>
                            <option value="black-forest-labs/FLUX.1-schnell">FLUX.1 Schnell</option>
                            <option value="agnes-image-2.1-flash">Agnes Image 2.1 Flash</option>
                            <option value="custom">自定义模型...</option>
                        </select>
                        <small class="form-text text-muted">选择后自动填充接口地址和模型名称</small>
                        <div class="mt-2" style="font-size:12px"><a href="https://cloud.siliconflow.cn/i/dIi69w1x" target="_blank">硅基流动注册送¥16</a>，含多个免费图像模型</div>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">绘图接口地址</label>
                    <div class="col-sm-9">
                        <input type="text" name="img_api_url" id="img_api_url" class="form-control" value="<?php echo htmlspecialchars($config['img_api_url']); ?>" placeholder="https://api.siliconflow.cn/v1/images/generations">
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">绘图模型名称</label>
                    <div class="col-sm-9">
                        <input type="text" name="img_model" id="img_model" class="form-control" value="<?php echo htmlspecialchars($config['img_model'] ?: 'Kwai-Kolors/Kolors'); ?>" placeholder="Kwai-Kolors/Kolors">
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">绘图接口密钥</label>
                    <div class="col-sm-9">
                        <input type="password" name="img_api_key" class="form-control" value="<?php echo htmlspecialchars($config['img_api_key']); ?>" placeholder="如与LLM不同则填写">
                        <small class="form-text text-muted">留空则使用 emlog 系统内置的 AI 配置</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">默认图片尺寸</label>
                    <div class="col-sm-9">
                        <select name="img_size" class="form-control">
                            <option value="1024x1024" <?php echo $config['img_size'] === '1024x1024' ? 'selected' : ''; ?>>1024x1024</option>
                            <option value="1792x1024" <?php echo $config['img_size'] === '1792x1024' ? 'selected' : ''; ?>>1792x1024（横版）</option>
                            <option value="1024x1792" <?php echo $config['img_size'] === '1024x1792' ? 'selected' : ''; ?>>1024x1792（竖版）</option>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">图片自动压缩</label>
                    <div class="col-sm-9">
                        <select name="img_compress_enabled" class="form-control">
                            <option value="1" <?php echo ($config['img_compress_enabled'] ?? 1) ? 'selected' : ''; ?>>开启（推荐）</option>
                            <option value="0" <?php echo !($config['img_compress_enabled'] ?? 1) ? 'selected' : ''; ?>>关闭</option>
                        </select>
                        <small class="form-text text-muted">AI生成的图片通常较大，压缩后可加快加载速度</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">压缩质量</label>
                    <div class="col-sm-9">
                        <input type="number" name="img_compress_quality" class="form-control" value="<?php echo $config['img_compress_quality'] ?? 80; ?>" min="10" max="100" step="5">
                        <small class="form-text text-muted">10-100，数值越小压缩越狠。推荐80（文件缩小约60%，肉眼无明显差异）</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">压缩后最大宽度</label>
                    <div class="col-sm-9">
                        <input type="number" name="img_compress_max_width" class="form-control" value="<?php echo $config['img_compress_max_width'] ?? 1920; ?>" min="400" max="4096" step="100">
                        <small class="form-text text-muted">超出此宽度的图片会被等比缩小，0=不缩放</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">配图保存目录</label>
                    <div class="col-sm-9">
                        <input type="text" name="img_dir" class="form-control" value="<?php echo htmlspecialchars($config['img_dir']); ?>">
                    </div>
                </div>

                <hr class="my-4">
                <h6 class="font-weight-bold text-primary">配图模式设置</h6>
                <p class="text-muted small mb-3">选择文章配图的来源方式。三种模式都支持，可按需切换。</p>

                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">配图模式</label>
                    <div class="col-sm-9">
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" name="img_mode" id="img_mode_generate" value="generate" class="custom-control-input" <?php echo ($config['img_mode'] ?? 'generate') === 'generate' ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="img_mode_generate"><strong>纯文生图</strong> — 仅使用 AI 生成图片（需配置上方绘图API）</label>
                        </div>
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" name="img_mode" id="img_mode_both" value="generate_then_search" class="custom-control-input" <?php echo ($config['img_mode'] ?? '') === 'generate_then_search' ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="img_mode_both"><strong>文生图优先 + 搜索降级</strong> — 先尝试AI生成，失败后自动搜索网络图片</label>
                        </div>
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" name="img_mode" id="img_mode_search" value="search" class="custom-control-input" <?php echo ($config['img_mode'] ?? '') === 'search' ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="img_mode_search"><strong>纯搜索</strong> — 直接从网络搜索图片，不使用AI生成（免费、速度快）</label>
                        </div>
                    </div>
                </div>

                <hr class="my-4">
                <h6 class="font-weight-bold text-primary">网络图片搜索配置</h6>
                <p class="text-muted small mb-3">配置免费图片库 API，支持多个平台。推荐同时配置多个，搜索时按顺序自动降级。</p>

                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">默认搜索源</label>
                    <div class="col-sm-9">
                        <select name="img_search_provider" class="form-control">
                            <option value="pexels" <?php echo ($config['img_search_provider'] ?? 'pexels') === 'pexels' ? 'selected' : ''; ?>>Pexels（推荐）</option>
                            <option value="unsplash" <?php echo ($config['img_search_provider'] ?? '') === 'unsplash' ? 'selected' : ''; ?>>Unsplash</option>
                            <option value="pixabay" <?php echo ($config['img_search_provider'] ?? '') === 'pixabay' ? 'selected' : ''; ?>>Pixabay</option>
                            <option value="pexels,unsplash,pixabay" <?php echo ($config['img_search_provider'] ?? '') === 'pexels,unsplash,pixabay' ? 'selected' : ''; ?>>全部（按顺序降级）</option>
                        </select>
                        <small class="form-text text-muted">多个源用逗号分隔，搜索时按顺序尝试直到找到结果</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">Pexels API Key</label>
                    <div class="col-sm-9">
                        <input type="password" name="img_search_api_key" class="form-control" value="<?php echo htmlspecialchars($config['img_search_api_key'] ?? ''); ?>" placeholder="在 pexels.com/api 免费申请">
                        <small class="form-text text-muted"><a href="https://www.pexels.com/api/" target="_blank">pexels.com/api</a> 免费注册，每月 20,000 次请求</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">Unsplash API Key</label>
                    <div class="col-sm-9">
                        <input type="password" name="img_search_unsplash_key" class="form-control" value="<?php echo htmlspecialchars($config['img_search_unsplash_key'] ?? ''); ?>" placeholder="在 unsplash.com/developers 免费申请">
                        <small class="form-text text-muted"><a href="https://unsplash.com/developers" target="_blank">unsplash.com/developers</a> 免费注册，每小时 5,000 次请求</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">Pixabay API Key</label>
                    <div class="col-sm-9">
                        <input type="password" name="img_search_pixabay_key" class="form-control" value="<?php echo htmlspecialchars($config['img_search_pixabay_key'] ?? ''); ?>" placeholder="在 pixabay.com/api/docs 免费申请">
                        <small class="form-text text-muted"><a href="https://pixabay.com/api/docs/" target="_blank">pixabay.com/api/docs</a> 免费注册，每分钟 100 次请求</small>
                    </div>
                </div>

                <div class="form-group row">
                    <div class="col-sm-9 offset-sm-3">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>保存设置</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php
}

/**
 * AI 人格配置 Tab
 */
function ai_agent_view_soul($config)
{
    $soul_file = AI_AGENT_DIR . '/ai/SOUL.md';
    $soul_content = file_exists($soul_file) ? file_get_contents($soul_file) : '';
    ?>
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">AI 人格配置（SOUL.md）</h6>
            <div>
                <button class="btn btn-sm btn-outline-secondary" onclick="ai_agent_reset_soul()">重置为默认</button>
            </div>
        </div>
        <div class="card-body">
            <form method="post" id="ai_agent_soul_form" class="ai-agent-form" action="./plugin.php?plugin=ai_agent&action=save_setting">
                <input type="hidden" name="ai_agent_action" value="save_soul">
                <div class="form-group">
                    <textarea name="soul_content" class="form-control" rows="20" style="font-family:monospace;font-size:13px;"><?php echo htmlspecialchars($soul_content); ?></textarea>
                    <small class="form-text text-muted">修改 AI 的角色定位、写作风格、输出规范、禁止行为等</small>
                </div>
                <div class="form-group">
                    <label class="font-weight-bold">快速模板：</label>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-info" onclick="ai_agent_load_template('essay')">随笔风</button>
                        <button type="button" class="btn btn-outline-info" onclick="ai_agent_load_template('tech')">技术文</button>
                        <button type="button" class="btn btn-outline-info" onclick="ai_agent_load_template('media')">自媒体风</button>
                        <button type="button" class="btn btn-outline-info" onclick="ai_agent_load_template('formal')">简约官方风</button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>保存人格</button>
            </form>
        </div>
    </div>
    <?php
}

/**
 * 工具管理 Tab
 */
function ai_agent_view_tools($config)
{
    $tools_file = AI_AGENT_DIR . '/ai/tools.json';
    $tools = [];
    if (file_exists($tools_file)) {
        $tools = json_decode(file_get_contents($tools_file), true) ?: [];
    }
    ?>
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">工具管理</h6>
        </div>
        <div class="card-body">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr>
                        <th>工具名</th>
                        <th>功能描述</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tools as $tool): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($tool['name']); ?></code></td>
                        <td><?php echo htmlspecialchars($tool['description']); ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-info" onclick="ai_agent_view_schema('<?php echo htmlspecialchars($tool['name']); ?>')">查看 Schema</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

/**
 * 记忆管理 Tab
 */
function ai_agent_view_memory($config)
{
    $memory_manager = new AIAgent_MemoryManager($config);
    $memories = $memory_manager->listMemories();
    ?>
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">记忆管理</h6>
            <button class="btn btn-sm btn-outline-danger" onclick="if(confirm('确认清理所有过期记忆？'))location.href='./plugin.php?plugin=ai_agent&action=clean_memories'">清理过期记忆</button>
        </div>
        <div class="card-body">
            <?php if (empty($memories)): ?>
                <p class="text-muted">暂无用户记忆数据</p>
            <?php else: ?>
                <table class="table table-bordered table-sm">
                    <thead class="thead-light">
                        <tr>
                            <th>用户 UID</th>
                            <th>记忆大小</th>
                            <th>最后更新</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($memories as $m): ?>
                        <tr>
                            <td><?php echo $m['uid']; ?></td>
                            <td><?php echo number_format($m['size']); ?> 字节</td>
                            <td><?php echo $m['modified']; ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-info" onclick="ai_agent_view_memory(<?php echo $m['uid']; ?>)">查看</button>
                                <button class="btn btn-sm btn-outline-danger" onclick="if(confirm('确认清空该用户记忆？'))location.href='./plugin.php?plugin=ai_agent&action=clear_memory&uid=<?php echo $m['uid']; ?>'">清空</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * 执行日志 Tab
 */
function ai_agent_view_logs($config)
{
    $chat_history = new AIAgent_ChatHistory();
    $stats = $chat_history->getStats();
    $sessions = $chat_history->getRecentSessions(UID > 0 ? UID : 0, 20);

    // 获取工具调用统计
    $db = MySql::getInstance();
    $tool_stats = [];
    $q = $db->query("SELECT tool_calls FROM " . DB_PREFIX . "ai_agent_chat WHERE tool_calls IS NOT NULL AND tool_calls != '[]' ORDER BY id DESC LIMIT 100");
    while ($r = $db->fetch_array($q)) {
        $tc = json_decode($r['tool_calls'], true);
        if (is_array($tc)) {
            foreach ($tc as $t) {
                $name = $t['tool'] ?? 'unknown';
                if (!isset($tool_stats[$name])) $tool_stats[$name] = ['total' => 0, 'success' => 0, 'fail' => 0];
                $tool_stats[$name]['total']++;
                if (($t['result']['success'] ?? false)) $tool_stats[$name]['success']++;
                else $tool_stats[$name]['fail']++;
            }
        }
    }
    ?>
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">执行日志</h6>
            <div>
                <span class="badge badge-info mr-2">共 <?php echo $stats['total_messages']; ?> 条消息，<?php echo $stats['total_sessions']; ?> 个会话</span>
                <button class="btn btn-sm btn-outline-primary" onclick="ai_agent_run_cron()"><i class="fas fa-sync mr-1"></i>运行定时任务</button>
            </div>
        </div>
        <div class="card-body">
            <!-- 工具调用统计 -->
            <?php if (!empty($tool_stats)): ?>
            <h6 class="font-weight-bold text-primary mb-2">工具调用统计（最近100条记录）</h6>
            <table class="table table-bordered table-sm mb-4">
                <thead class="thead-light">
                    <tr><th>工具名</th><th>总调用</th><th>成功</th><th>失败</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($tool_stats as $name => $st): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($name); ?></code></td>
                        <td><?php echo $st['total']; ?></td>
                        <td><span class="text-success"><?php echo $st['success']; ?></span></td>
                        <td><span class="text-danger"><?php echo $st['fail']; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- 会话列表 -->
            <h6 class="font-weight-bold text-primary mb-2">会话记录</h6>
            <?php if (empty($sessions)): ?>
                <p class="text-muted">暂无对话记录</p>
            <?php else: ?>
                <table class="table table-bordered table-sm">
                    <thead class="thead-light">
                        <tr><th>会话ID</th><th>消息数</th><th>开始时间</th><th>最后活跃</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($s['session_id']); ?></code></td>
                            <td><?php echo $s['msg_count']; ?></td>
                            <td><?php echo $s['started']; ?></td>
                            <td><?php echo $s['last_active']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * 高级设置 Tab
 */
function ai_agent_view_advanced($config)
{
    ?>
    <form method="post" id="ai_agent_advanced_form" class="ai-agent-form" action="./plugin.php?plugin=ai_agent&action=save_setting">
                <input type="hidden" name="ai_agent_action" value="save_advanced">

    <!-- LLM 高级 -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">LLM 高级配置</h6>
        </div>
        <div class="card-body">
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">API 超时时间（秒）</label>
                <div class="col-sm-8">
                    <input type="number" name="llm_timeout" class="form-control" value="<?php echo ($config['llm_timeout'] ?? 60); ?>" min="10" max="300">
                    <small class="form-text text-muted">网络较慢时建议调大，默认60秒</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">会话历史传入条数</label>
                <div class="col-sm-8">
                    <input type="number" name="agent_history_limit" class="form-control" value="<?php echo ($config['agent_history_limit'] ?? 20); ?>" min="5" max="50">
                    <small class="form-text text-muted">传给AI的上下文轮数，越大消耗token越多，默认20</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">系统提示词最大长度</label>
                <div class="col-sm-8">
                    <input type="number" name="system_prompt_max" class="form-control" value="<?php echo ($config['system_prompt_max'] ?? 8000); ?>" min="2000" max="30000">
                    <small class="form-text text-muted">SOUL+BLOG+MEMORY总长度上限，默认8000</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 配图高级 -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">配图高级配置</h6>
        </div>
        <div class="card-body">
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">批量配图单次上限</label>
                <div class="col-sm-8">
                    <input type="number" name="img_batch_limit" class="form-control" value="<?php echo ($config['img_batch_limit'] ?? 5); ?>" min="1" max="20">
                    <small class="form-text text-muted">一次批量操作最多处理几篇，默认5</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">批量配图间隔（秒）</label>
                <div class="col-sm-8">
                    <input type="number" name="img_batch_interval" class="form-control" value="<?php echo ($config['img_batch_interval'] ?? 1); ?>" min="0" max="10">
                    <small class="form-text text-muted">每张图之间间隔，防止API限流，默认1秒</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">图片保存目录</label>
                <div class="col-sm-8">
                    <input type="text" name="img_dir" class="form-control" value="<?php echo htmlspecialchars($config['img_dir']); ?>">
                    <small class="form-text text-muted">相对于网站根目录，默认 content/upload/ai_images/</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 标签配置 -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">标签配置</h6>
        </div>
        <div class="card-body">
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">标签查询返回上限</label>
                <div class="col-sm-8">
                    <input type="number" name="tag_query_limit" class="form-control" value="<?php echo ($config['tag_query_limit'] ?? 50); ?>" min="10" max="200">
                    <small class="form-text text-muted">get_tags 工具最多返回多少个标签，默认50</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">上下文常用标签数量</label>
                <div class="col-sm-8">
                    <input type="number" name="tag_recent_count" class="form-control" value="<?php echo ($config['tag_recent_count'] ?? 20); ?>" min="5" max="50">
                    <small class="form-text text-muted">注入到系统提示词中的常用标签数，默认20</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 记忆高级 -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">记忆高级配置</h6>
        </div>
        <div class="card-body">
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">单用户记忆文件大小上限（KB）</label>
                <div class="col-sm-8">
                    <input type="number" name="memory_max_size" class="form-control" value="<?php echo ($config['memory_max_size'] ?? 50); ?>" min="5" max="100">
                    <small class="form-text text-muted">超过后自动截断旧记忆，默认10KB</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">记忆截取长度（字符）</label>
                <div class="col-sm-8">
                    <input type="number" name="memory_truncate" class="form-control" value="<?php echo ($config['memory_truncate'] ?? 5000); ?>" min="1000" max="10000">
                    <small class="form-text text-muted">注入到系统提示词中的记忆最大长度，默认3000</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 前台配置 -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">前台对话窗口配置</h6>
        </div>
        <div class="card-body">
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">前端请求超时（秒）</label>
                <div class="col-sm-8">
                    <input type="number" name="frontend_timeout" class="form-control" value="<?php echo ($config['frontend_timeout'] ?? 60); ?>" min="30" max="300">
                    <small class="form-text text-muted">前台等待AI回复的超时时间，默认120秒</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">对话窗口宽度（px）</label>
                <div class="col-sm-8">
                    <input type="number" name="panel_width" class="form-control" value="<?php echo ($config['panel_width'] ?? 400); ?>" min="300" max="600">
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">对话窗口高度（px）</label>
                <div class="col-sm-8">
                    <input type="number" name="panel_height" class="form-control" value="<?php echo ($config['panel_height'] ?? 600); ?>" min="400" max="800">
                </div>
            </div>
        </div>
    </div>

    <!-- 日志配置 -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">日志配置</h6>
        </div>
        <div class="card-body">
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">启用错误日志</label>
                <div class="col-sm-8">
                    <select name="error_log_enabled" class="form-control">
                        <option value="1" <?php echo ($config['error_log_enabled'] ?? 0) ? 'selected' : ''; ?>>启用</option>
                        <option value="0" <?php echo !($config['error_log_enabled'] ?? 0) ? 'selected' : ''; ?>>禁用</option>
                    </select>
                    <small class="form-text text-muted">生产环境可关闭以减少磁盘写入</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">启用调试日志</label>
                <div class="col-sm-8">
                    <select name="debug_log_enabled" class="form-control">
                        <option value="1" <?php echo ($config['debug_log_enabled'] ?? 0) ? 'selected' : ''; ?>>启用</option>
                        <option value="0" <?php echo !($config['debug_log_enabled'] ?? 0) ? 'selected' : ''; ?>>禁用（推荐）</option>
                    </select>
                    <small class="form-text text-muted">仅调试时开启，会产生大量日志</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">自定义日志路径</label>
                <div class="col-sm-8">
                    <input type="text" name="error_log_path" class="form-control" value="<?php echo htmlspecialchars(($config['error_log_path'] ?? '')); ?>" placeholder="留空使用默认 /tmp/">
                </div>
            </div>
        </div>
    </div>

    <!-- 安全增强 -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-danger"><i class="fas fa-shield-alt mr-1"></i>安全增强配置</h6>
        </div>
        <div class="card-body">
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">禁用工具列表</label>
                <div class="col-sm-8">
                    <input type="text" name="disabled_tools" class="form-control" value="<?php echo htmlspecialchars(($config['disabled_tools'] ?? '')); ?>" placeholder="如: batch_generate_covers,update_article">
                    <small class="form-text text-muted">逗号分隔，这些工具将完全不可用</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">仅管理员可用工具</label>
                <div class="col-sm-8">
                    <input type="text" name="admin_only_tool" class="form-control" value="<?php echo htmlspecialchars(($config['admin_only_tool'] ?? '')); ?>" placeholder="如: publish_article,update_article">
                    <small class="form-text text-muted">逗号分隔，非管理员无法调用这些工具</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">每分钟请求上限</label>
                <div class="col-sm-8">
                    <input type="number" name="rate_limit_per_min" class="form-control" value="<?php echo ($config['rate_limit_per_min'] ?? 20); ?>" min="1" max="100">
                    <small class="form-text text-muted">防刷，每用户每分钟最多请求次数，默认20</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">每小时请求上限</label>
                <div class="col-sm-8">
                    <input type="number" name="rate_limit_per_hour" class="form-control" value="<?php echo ($config['rate_limit_per_hour'] ?? 200); ?>" min="10" max="1000">
                    <small class="form-text text-muted">防刷，每用户每小时最多请求次数，默认200</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">用户输入长度上限</label>
                <div class="col-sm-8">
                    <input type="number" name="max_input_length" class="form-control" value="<?php echo ($config['max_input_length'] ?? 2000); ?>" min="100" max="10000">
                    <small class="form-text text-muted">单条消息最大字符数，默认2000</small>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 col-form-label">内容安全过滤</label>
                <div class="col-sm-8">
                    <select name="content_filter" class="form-control">
                        <option value="1" <?php echo ($config['content_filter'] ?? 0) ? 'selected' : ''; ?>>启用（推荐）</option>
                        <option value="0" <?php echo !($config['content_filter'] ?? 0) ? 'selected' : ''; ?>>禁用</option>
                    </select>
                    <small class="form-text text-muted">过滤AI回复中的敏感内容和恶意代码</small>
                </div>
            </div>
        </div>
    </div>

    <div class="form-group">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>保存高级设置</button>
    </div>
    </form>
    <?php
}

// 导出配置
function ai_agent_export_config()
{
    $config = ai_agent_get_config();
    // 移除敏感信息
    $export = $config;
    $export['llm_api_key'] = !empty($export['llm_api_key']) ? '***已配置***' : '';
    $export['img_api_key'] = !empty($export['img_api_key']) ? '***已配置***' : '';
    $export['img_search_api_key'] = !empty($export['img_search_api_key'] ?? '') ? '***已配置***' : '';
    $export['img_search_unsplash_key'] = !empty($export['img_search_unsplash_key'] ?? '') ? '***已配置***' : '';
    $export['img_search_pixabay_key'] = !empty($export['img_search_pixabay_key'] ?? '') ? '***已配置***' : '';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="ai_agent_config_' . date('Ymd') . '.json"');
    echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 导入配置
function ai_agent_import_config()
{
    $raw = file_get_contents('php://input');
    parse_str($raw, $body);
    $json = $body['config_data'] ?? '';
    if (empty($json)) $json = $raw;
    if (empty($json)) {
        $json = file_get_contents('php://input');
    }
    $data = json_decode($json, true);
    if (!$data || !is_array($data)) {
        Output::error('无效的 JSON 数据');
        return;
    }

    $config = ai_agent_get_config();

    // 保留当前的 API Key（不从导入文件覆盖带***的值）
    foreach (['llm_api_key', 'img_api_key', 'img_search_api_key', 'img_search_unsplash_key', 'img_search_pixabay_key'] as $key) {
        if (isset($data[$key]) && strpos($data[$key], '***') !== false) {
            unset($data[$key]);
        }
    }

    // 合并配置
    foreach ($data as $key => $value) {
        $config[$key] = $value;
    }

    ai_agent_save_config($config);
    Output::ok();
}

/**
 * 处理设置保存
 */
function plugin_setting()
{
    // 优先检查 ai_agent_action（用于导出/导入等特殊操作）
    $ai_action = Input::postStrVar('ai_agent_action', '');
    if (empty($ai_action)) {
        $ai_action = Input::getStrVar('action', '');
    }

    switch ($ai_action) {
        case 'export_config':
            ai_agent_export_config();
            break;
        case 'import_config':
            ai_agent_import_config();
            break;
        case 'save_basic':
            ai_agent_save_basic();
            break;
        case 'save_soul':
            ai_agent_save_soul();
            break;
        case 'clean_memories':
            ai_agent_clean_memories();
            break;
        case 'clear_memory':
            ai_agent_clear_memory();
            break;
        case 'run_cron':
            ai_agent_run_cron();
            break;
        case 'save_advanced':
            ai_agent_save_advanced();
            break;
        case 'save_autopub':
            ai_agent_save_autopub();
            break;
        default:
            Output::error('未知操作');
    }
}

function ai_agent_save_basic()
{
    $config = ai_agent_get_config();

    // 字符串字段
    $str_fields = [
        'llm_api_url', 'llm_api_key', 'llm_model',
        'img_api_url', 'img_api_key', 'img_model', 'img_dir', 'img_search_api_key', 'img_search_provider', 'img_search_unsplash_key', 'img_search_pixabay_key', 'img_mode',
        'error_log_path', 'disabled_tools', 'admin_only_tool',
    'img_size',
];
    foreach ($str_fields as $f) {
        $config[$f] = Input::postStrVar($f, $config[$f] ?? '');
    }

    // 数值字段（带范围限制）
    $num_fields = [
        'llm_temperature' => ['min' => 0, 'max' => 1, 'default' => 0.7],
        'llm_max_tokens'  => ['min' => 100, 'max' => 8000, 'default' => 2000],
        'llm_timeout'     => ['min' => 10, 'max' => 300, 'default' => 60],
        'agent_history_limit' => ['min' => 5, 'max' => 50, 'default' => 20],
        'system_prompt_max' => ['min' => 2000, 'max' => 30000, 'default' => 8000],
        'memory_trigger'  => ['min' => 5, 'max' => 100, 'default' => 10],
        'memory_expire_days' => ['min' => 7, 'max' => 365, 'default' => 90],
        'memory_max_size' => ['min' => 5, 'max' => 100, 'default' => 50],
        'memory_truncate' => ['min' => 1000, 'max' => 10000, 'default' => 5000],
        'session_expire'  => ['min' => 300, 'max' => 86400, 'default' => 1800],
        'img_batch_limit' => ['min' => 1, 'max' => 20, 'default' => 5],
        'img_batch_interval' => ['min' => 0, 'max' => 10, 'default' => 1],
        'img_compress_quality' => ['min' => 10, 'max' => 100, 'default' => 80],
        'img_compress_max_width' => ['min' => 0, 'max' => 4096, 'default' => 1920],
        'tag_query_limit' => ['min' => 10, 'max' => 200, 'default' => 50],
        'tag_recent_count' => ['min' => 5, 'max' => 50, 'default' => 20],
        'frontend_timeout' => ['min' => 30, 'max' => 300, 'default' => 60],
        'panel_width'     => ['min' => 300, 'max' => 600, 'default' => 400],
        'panel_height'    => ['min' => 400, 'max' => 800, 'default' => 600],
        'guest_daily_limit' => ['min' => 0, 'max' => 100, 'default' => 5],
        'user_daily_limit' => ['min' => 0, 'max' => 500, 'default' => 50],
        'admin_daily_limit' => ['min' => 0, 'max' => 9999, 'default' => 0],
        'rate_limit_per_min' => ['min' => 1, 'max' => 100, 'default' => 20],
        'rate_limit_per_hour' => ['min' => 10, 'max' => 1000, 'default' => 200],
        'max_input_length' => ['min' => 100, 'max' => 10000, 'default' => 2000],
    ];
    foreach ($num_fields as $f => $range) {
        $val = Input::postStrVar($f, $config[$f] ?? $range['default']);
        $config[$f] = max($range['min'], min($range['max'], floatval($val)));
    }

    // 整数字段（0或1开关）
    $int_fields = [
        'agent_enabled', 'agent_max_rounds', 'agent_retry',
        'memory_enabled', 'guest_allowed', 'admin_only_publish',
        'danger_block', 'draft_default',
        'error_log_enabled', 'debug_log_enabled', 'content_filter', 'img_compress_enabled',
    ];
    foreach ($int_fields as $f) {
        $config[$f] = Input::postIntVar($f, $config[$f] ?? 0);
    }

    // 角色权限（多选）
    $publish_roles = Input::postStrArray('publish_roles');
    $config['publish_roles'] = !empty($publish_roles) ? $publish_roles : ['admin'];

    // 特殊字段

    ai_agent_save_config($config);
    Output::ok();
}

function ai_agent_save_soul()
{
    $content = Input::postStrVar('soul_content', '');
    $soul_file = AI_AGENT_DIR . '/ai/SOUL.md';
    file_put_contents($soul_file, $content);
    Output::ok();
}

function ai_agent_clean_memories()
{
    $config = ai_agent_get_config();
    $memory_manager = new AIAgent_MemoryManager($config);
    $memory_manager->triggerAsyncCleanup();
    header('Location: ./plugin.php?plugin=ai_agent&tab=memory&succ=1');
    exit;
}

function ai_agent_clear_memory()
{
    $uid = Input::getIntVar('uid', 0);
    if ($uid > 0) {
        $config = ai_agent_get_config();
        $memory_manager = new AIAgent_MemoryManager($config);
        $memory_manager->clearMemory($uid);
    }
    header('Location: ./plugin.php?plugin=ai_agent&tab=memory&succ=1');
    exit;
}

/**
 * 自动发文 Tab
 */
function ai_agent_view_autopub($config)
{
    require_once AI_AGENT_DIR . "/cron.php";
    $status = ai_agent_get_cron_status();
    ?>
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-clock mr-2"></i>定时自动发文</h6>
            <span class="badge badge-<?php echo $status['enabled'] ? 'success' : 'secondary'; ?>">
                <?php echo $status['enabled'] ? '运行中' : '已关闭'; ?>
            </span>
        </div>
        <div class="card-body">
            <!-- 运行状态看板 -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-light">
                        <div class="card-body text-center py-3">
                            <div class="text-muted small">上次运行</div>
                            <div class="font-weight-bold"><?php echo $status['last_run']; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light">
                        <div class="card-body text-center py-3">
                            <div class="text-muted small">下次运行</div>
                            <div class="font-weight-bold"><?php echo $status['next_run']; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light">
                        <div class="card-body text-center py-3">
                            <div class="text-muted small">累计运行</div>
                            <div class="font-weight-bold"><?php echo $status['run_count']; ?> 次</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light">
                        <div class="card-body text-center py-3">
                            <div class="text-muted small">上次成功</div>
                            <div class="font-weight-bold"><?php echo $status['last_success']; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <form method="post" id="ai_agent_autopub_form" class="ai-agent-form" action="./plugin.php?plugin=ai_agent&action=save_setting">
                <input type="hidden" name="ai_agent_action" value="save_autopub">

                <h6 class="font-weight-bold text-primary">基础配置</h6>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">开启自动发文</label>
                    <div class="col-sm-9">
                        <select name="auto_publish_enabled" class="form-control">
                            <option value="0" <?php echo empty($config['auto_publish_enabled']) ? 'selected' : ''; ?>>关闭</option>
                            <option value="1" <?php echo !empty($config['auto_publish_enabled']) ? 'selected' : ''; ?>>开启</option>
                        </select>
                        <small class="form-text text-muted">开启后随访客访问自动触发发文</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">发布间隔（分钟）</label>
                    <div class="col-sm-9">
                        <input type="number" name="auto_publish_interval" class="form-control" value="<?php echo intval(($config['auto_publish_interval'] ?? 3600) / 60); ?>" min="10" max="1440">
                        <small class="form-text text-muted">两次发文之间的最小间隔，推荐60分钟以上</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">每日最大发文数</label>
                    <div class="col-sm-9">
                        <input type="number" name="auto_publish_daily_max" class="form-control" value="<?php echo $config['auto_publish_daily_max'] ?? 5; ?>" min="1" max="50">
                        <small class="form-text text-muted">每天最多自动发布几篇文章</small>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">发布时间段</label>
                    <div class="col-sm-9">
                        <div class="input-group">
                            <input type="number" name="auto_publish_hour_start" class="form-control" value="<?php echo $config['auto_publish_hour_start'] ?? 8; ?>" min="0" max="23">
                            <div class="input-group-append"><span class="input-group-text">时 至</span></div>
                            <input type="number" name="auto_publish_hour_end" class="form-control" value="<?php echo $config['auto_publish_hour_end'] ?? 22; ?>" min="0" max="23">
                            <div class="input-group-append"><span class="input-group-text">时</span></div>
                        </div>
                        <small class="form-text text-muted">只在此时间段内触发发文</small>
                    </div>
                </div>

                <hr>
                <h6 class="font-weight-bold text-primary">发布配置</h6>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">指定分类</label>
                    <div class="col-sm-9">
                        <select name="auto_publish_sortid" class="form-control">
                            <option value="0">不指定（AI 自动选择）</option>
                            <?php
                            $db = MySql::getInstance();
                            $query = $db->query("SELECT sid, sortname FROM " . DB_PREFIX . "sort ORDER BY sid");
                            while ($row = $db->fetch_array($query)) {
                                $selected = intval($config['auto_publish_sortid'] ?? 0) == $row['sid'] ? 'selected' : '';
                                echo '<option value="' . $row['sid'] . '" ' . $selected . '>' . htmlspecialchars($row['sortname']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">默认标签</label>
                    <div class="col-sm-9">
                        <input type="text" name="auto_publish_tags" class="form-control" value="<?php echo htmlspecialchars($config['auto_publish_tags'] ?? ''); ?>" placeholder="如：科技,教程,AI">
                        <small class="form-text text-muted">多个标签用逗号分隔，留空则 AI 自动判断</small>
                    </div>
                </div>

                <hr>
                <h6 class="font-weight-bold text-primary">发文模式</h6>
                <div class="form-group row">
                    <label class="col-sm-3 col-form-label">发文模式</label>
                    <div class="col-sm-9">
                        <select id="ai_agent_pub_mode" class="form-control" onchange="ai_agent_toggle_prompt(this.value)">
                            <option value="smart" <?php echo empty($config['auto_publish_prompt']) ? 'selected' : ''; ?>>智能模式（自动分析站点内容生成话题）</option>
                            <option value="custom" <?php echo !empty($config['auto_publish_prompt']) ? 'selected' : ''; ?>>自定义 Prompt</option>
                        </select>
                        <small class="form-text text-muted">智能模式会自动分析站点分类、标签和近期文章，生成符合站点定位的内容</small>
                    </div>
                </div>

                <h6 class="font-weight-bold text-primary">自定义 Prompt</h6>
                <div id="ai_agent_prompt_area" style="display:<?php echo !empty($config['auto_publish_prompt']) ? 'block' : 'none'; ?>;">
                <div class="form-group">
                    <textarea name="auto_publish_prompt" class="form-control" rows="10" style="font-family:monospace;font-size:13px;"><?php echo htmlspecialchars($config['auto_publish_prompt'] ?? ai_agent_get_default_auto_prompt()); ?></textarea>
                    <small class="form-text text-muted">
                        可用变量：<code>{分类名}</code> <code>{标签}</code> <code>{日期}</code><br>
                        留空使用智能模式
                    </small>
                </div>
                </div>
                <script>
                function ai_agent_toggle_prompt(mode) {
                    document.getElementById('ai_agent_prompt_area').style.display = (mode === 'custom') ? 'block' : 'none';
                }
                </script>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>保存设置</button>
            </form>
        </div>
    </div>

    <!-- 运行日志 -->
    <?php if (!empty($status['logs'])): ?>
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">最近运行记录</h6>
        </div>
        <div class="card-body">
            <table class="table table-sm">
                <thead><tr><th>时间</th><th>提示词</th><th>工具</th><th>状态</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($status['logs']) as $log): ?>
                    <tr>
                        <td><?php echo $log['time']; ?></td>
                        <td><?php echo mb_substr($log['prompt'] ?? '', 0, 50); ?>...</td>
                        <td><?php echo implode(', ', $log['tools'] ?? []); ?></td>
                        <td><span class="badge badge-<?php echo $log['success'] ? 'success' : 'danger'; ?>"><?php echo $log['success'] ? '成功' : '失败'; ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($status['errors'])): ?>
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-danger">错误日志</h6>
        </div>
        <div class="card-body">
            <pre style="max-height:200px;overflow:auto;font-size:12px;"><?php echo htmlspecialchars($status['errors']); ?></pre>
        </div>
    </div>
    <?php endif; ?>
    <?php
}

/**
 * 保存自动发文配置
 */
function ai_agent_save_autopub()
{
    $config = ai_agent_get_config();

    $config['auto_publish_enabled'] = Input::postIntVar('auto_publish_enabled', 0);
    $interval_min = Input::postIntVar('auto_publish_interval', 60);
    $config['auto_publish_interval'] = max(600, min(86400, $interval_min * 60));
    $config['auto_publish_daily_max'] = Input::postIntVar('auto_publish_daily_max', 5);
    $config['auto_publish_hour_start'] = Input::postIntVar('auto_publish_hour_start', 8);
    $config['auto_publish_hour_end'] = Input::postIntVar('auto_publish_hour_end', 22);
    $config['auto_publish_sortid'] = Input::postIntVar('auto_publish_sortid', 0);
    $config['auto_publish_tags'] = Input::postStrVar('auto_publish_tags', '');
    $config['auto_publish_prompt'] = Input::postStrVar('auto_publish_prompt', '');

    ai_agent_save_config($config);
    Output::ok();
}

function ai_agent_save_advanced()
{
    $config = ai_agent_get_config();

    // 数字字段
    $num_fields = [
        'llm_timeout'       => ['min' => 10, 'max' => 300],
        'agent_history_limit' => ['min' => 5, 'max' => 50],
        'system_prompt_max' => ['min' => 2000, 'max' => 30000],
        'img_batch_limit'   => ['min' => 1, 'max' => 20],
        'img_batch_interval' => ['min' => 0, 'max' => 10],
        'tag_query_limit'   => ['min' => 10, 'max' => 200],
        'tag_recent_count'  => ['min' => 5, 'max' => 50],
        'memory_max_size'   => ['min' => 5, 'max' => 100],
        'memory_truncate'   => ['min' => 1000, 'max' => 10000],
        'frontend_timeout'  => ['min' => 30, 'max' => 300],
        'panel_width'       => ['min' => 300, 'max' => 600],
        'panel_height'      => ['min' => 400, 'max' => 800],
        'rate_limit_per_min' => ['min' => 1, 'max' => 100],
        'rate_limit_per_hour' => ['min' => 10, 'max' => 1000],
        'max_input_length'  => ['min' => 100, 'max' => 10000],
    ];
    foreach ($num_fields as $f => $range) {
        $val = Input::postIntVar($f, $config[$f] ?? 0);
        $config[$f] = max($range['min'], min($range['max'], $val));
    }

    // 字符串字段
    $str_fields = ['disabled_tools', 'admin_only_tool', 'img_dir', 'img_search_api_key', 'img_search_provider', 'img_search_unsplash_key', 'img_search_pixabay_key', 'img_mode', 'error_log_path'];
    foreach ($str_fields as $f) {
        $config[$f] = Input::postStrVar($f, $config[$f] ?? '');
    }

    // 开关字段
    $int_fields = ['error_log_enabled', 'debug_log_enabled', 'content_filter'];
    foreach ($int_fields as $f) {
        $config[$f] = Input::postIntVar($f, $config[$f] ?? 0);
    }

    // 角色权限（多选）
    $publish_roles = Input::postStrArray('publish_roles');
    $config['publish_roles'] = !empty($publish_roles) ? $publish_roles : ['admin'];

    ai_agent_save_config($config);
    Output::ok();
}

function ai_agent_run_cron()
{
    require_once AI_AGENT_DIR . '/cron.php';
    $results = ai_agent_cron_run();
    $msg = implode('，', $results ?: ['无任务执行']);
    header('Location: ./plugin.php?plugin=ai_agent&tab=logs&succ=' . urlencode($msg));
    exit;
}
