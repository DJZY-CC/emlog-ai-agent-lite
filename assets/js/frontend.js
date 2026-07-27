/**
 * AI Agent 智能助手 - 前台对话 JS v2（数据库存储 + Markdown + 图片预览）
 */
(function() {
    'use strict';

    var panel, toggle, closeBtn, sendBtn, input, messages;
    var isLoading = false;
    var sessionId = localStorage.getItem('ai_agent_session_id') || '';

    // 初始化DOM引用
    function initDOM() {
        panel = document.getElementById('ai-agent-panel');
        toggle = document.getElementById('ai-agent-toggle');
        closeBtn = document.getElementById('ai-agent-close');
        sendBtn = document.getElementById('ai-agent-send');
        input = document.getElementById('ai-agent-input');
        messages = document.getElementById('ai-agent-messages');
    }

    // 初始化
    function init() {
        initDOM();
        if (!toggle) return;

        if (!sessionId) newSession();
        else loadHistory();

        toggle.addEventListener('click', function() {
            panel.style.display = panel.style.display === 'none' ? 'flex' : 'none';
            if (panel.style.display === 'flex') {
                input.focus();
                messages.scrollTop = messages.scrollHeight;
                bindMobileKeyboard();
            } else {
                unbindMobileKeyboard();
            }
        });

        closeBtn.addEventListener('click', function() { panel.style.display = 'none'; unbindMobileKeyboard(); });

        sendBtn.addEventListener('click', sendMessage);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        });

        // 自动增高textarea
        input.addEventListener('input', autoResizeInput);

        // 新对话按钮
        var newBtn = document.getElementById('ai-agent-newchat');
        if (newBtn) {
            newBtn.addEventListener('click', function() {
                showConfirm('开始新对话？当前记录不会丢失。', function() {
                    localStorage.removeItem('ai_agent_session_id');
                    messages.innerHTML = '';
                    sessionId = '';
                    newSession();
                });
            });
        }
    }

    // 自动增高输入框
    function autoResizeInput() {
        input.style.height = 'auto';
        var maxH = window.innerWidth <= 480 ? 80 : 80;
        input.style.height = Math.min(input.scrollHeight, maxH) + 'px';
    }

    // 移动端键盘弹出/收起监听
    var viewportHandler = null;
    function bindMobileKeyboard() {
        if (!('visualViewport' in window)) return;
        if (viewportHandler) return;
        var vv = window.visualViewport;
        viewportHandler = function() {
            var keyboardHeight = window.innerHeight - vv.height;
            if (keyboardHeight > 120) {
                panel.classList.add('keyboard-open');
                panel.style.height = (vv.height - 80) + 'px';
                messages.scrollTop = messages.scrollHeight;
            } else {
                panel.classList.remove('keyboard-open');
                panel.style.height = '';
            }
        };
        vv.addEventListener('resize', viewportHandler);
    }
    function unbindMobileKeyboard() {
        if (!('visualViewport' in window) || !viewportHandler) return;
        window.visualViewport.removeEventListener('resize', viewportHandler);
        viewportHandler = null;
        panel.classList.remove('keyboard-open');
        panel.style.height = '';
    }

    // 自定义确认弹窗（替代 confirm()，移动端友好）
    function showConfirm(msg, onOk) {
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:100001;display:flex;align-items:center;justify-content:center;animation:ai-fade-in 0.2s';
        var box = document.createElement('div');
        box.style.cssText = 'background:#fff;border-radius:14px;padding:24px;max-width:300px;width:85%;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,0.2);font-size:15px;line-height:1.6';
        box.innerHTML = '<p style="margin:0 0 20px;color:#333">' + escapeHtml(msg).replace(/<br>/g, '') + '</p>';
        var btns = document.createElement('div');
        btns.style.cssText = 'display:flex;gap:10px;justify-content:center';
        var cancelBtn = document.createElement('button');
        cancelBtn.textContent = '取消';
        cancelBtn.style.cssText = 'flex:1;padding:10px 0;border:1.5px solid #ddd;border-radius:10px;background:#fff;font-size:14px;cursor:pointer;min-height:44px';
        var okBtn = document.createElement('button');
        okBtn.textContent = '确定';
        okBtn.style.cssText = 'flex:1;padding:10px 0;border:none;border-radius:10px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:14px;font-weight:500;cursor:pointer;min-height:44px';
        cancelBtn.onclick = function() { document.body.removeChild(overlay); };
        okBtn.onclick = function() { document.body.removeChild(overlay); onOk(); };
        btns.appendChild(cancelBtn);
        btns.appendChild(okBtn);
        box.appendChild(btns);
        overlay.appendChild(box);
        overlay.addEventListener('click', function(e) { if (e.target === overlay) document.body.removeChild(overlay); });
        document.body.appendChild(overlay);
    }

    function newSession() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', (window.AI_AGENT_API || '?plugin=ai_agent&action=') + 'new_session', true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.code === 0) {
                        sessionId = data.data.session_id;
                        localStorage.setItem('ai_agent_session_id', sessionId);
                    }
                } catch(e) {}
            }
        };
        xhr.send();
    }

    function loadHistory() {
        if (!sessionId || !messages) return;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', (window.AI_AGENT_API || '?plugin=ai_agent&action=') + 'history&session_id=' + encodeURIComponent(sessionId), true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.code === 0 && data.data.history && data.data.history.length > 0) {
                        messages.innerHTML = '';
                        data.data.history.forEach(function(item) {
                            if (item.message) appendMessage('user', item.message, false);
                            if (item.response) appendMessage('assistant', item.response, false);
                            if (item.tool_calls && item.tool_calls.length > 0) {
                                var toolInfo = item.tool_calls.map(function(t) {
                                    var s = t.result && t.result.success ? '✅' : '❌';
                                    return s + ' ' + t.tool;
                                }).join(' | ');
                                appendMessage('status', toolInfo, false);
                            }
                        });
                        messages.scrollTop = messages.scrollHeight;
                    }
                } catch(e) {}
            }
        };
        xhr.send();
    }

    function sendMessage() {
        var text = input.value.trim();
        if (!text || isLoading) return;
        if (!sessionId) newSession();

        isLoading = true;
        input.value = '';
        input.style.height = 'auto'; // 重置输入框高度
        sendBtn.disabled = true;

        appendMessage('user', text);
        var loadingEl = appendMessage('loading', '正在思考中');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', (window.AI_AGENT_API || '?plugin=ai_agent&action=') + 'chat', true);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = parseInt(document.getElementById('ai-agent-widget').dataset.timeout || '120') * 1000;

        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                isLoading = false;
                sendBtn.disabled = false;
                if (loadingEl && loadingEl.parentNode) loadingEl.parentNode.removeChild(loadingEl);

                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.code === 0) {
                            if (data.data.session_id) {
                                sessionId = data.data.session_id;
                                localStorage.setItem('ai_agent_session_id', sessionId);
                            }
                            if (data.data.tool_calls && data.data.tool_calls.length > 0) {
                                var toolInfo = data.data.tool_calls.map(function(t) {
                                    var s = t.result && t.result.success ? '✅' : '❌';
                                    return s + ' ' + t.tool;
                                }).join(' | ');
                                appendMessage('status', toolInfo);
                                data.data.tool_calls.forEach(function(t) {
                                    if (t.result && !t.result.success) {
                                        appendMessage('error', '工具 ' + t.tool + ' 失败: ' + (t.result.error || '未知错误'));
                                    }
                                });
                            }
                            appendMessage('assistant', data.data.response);
                        } else {
                            appendMessage('error', data.msg || '请求失败');
                        }
                    } catch(e) {
                        appendMessage('error', '响应解析失败');
                    }
                } else if (xhr.status === 0) {
                    appendMessage('error', '请求超时或网络中断');
                } else {
                    appendMessage('error', '网络错误: HTTP ' + xhr.status);
                }
            }
        };

        xhr.ontimeout = function() {
            isLoading = false;
            sendBtn.disabled = false;
            if (loadingEl && loadingEl.parentNode) loadingEl.parentNode.removeChild(loadingEl);
            appendMessage('error', '请求超时');
        };

        xhr.send('message=' + encodeURIComponent(text) + '&session_id=' + encodeURIComponent(sessionId));
    }

    // 添加消息
    function appendMessage(type, content) {
        var div = document.createElement('div');
        div.className = 'ai-msg ai-msg-' + type;

        var label = '';
        switch(type) {
            case 'user': label = '你'; break;
            case 'assistant': label = '🤖 AI'; break;
            case 'loading': label = '⏳'; break;
            case 'error': label = '❌ 错误'; break;
            case 'status': label = '📋'; break;
        }

        var contentHtml = '';
        if (type === 'assistant') {
            contentHtml = renderMarkdown(content);
        } else if (type === 'user') {
            contentHtml = escapeHtml(content);
        } else {
            contentHtml = escapeHtml(content);
        }

        div.innerHTML = '<div class="ai-msg-label">' + label + '</div><div class="ai-msg-content">' + contentHtml + '</div>';

        // 绑定图片灯箱
        var imgs = div.querySelectorAll('.ai-img-preview');
        imgs.forEach(function(imgWrap) {
            imgWrap.addEventListener('click', function() {
                var src = imgWrap.querySelector('img').src;
                openLightbox(src);
            });
        });

        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }

    // 简易 Markdown 渲染
    function renderMarkdown(text) {
        if (!text) return '';

        // 安全校验URL白名单
        function safeUrl(url) {
            if (!url) return '';
            url = url.trim();
            if (/^https?:\/\//i.test(url)) return url;
            return '';
        }

        // 提取图片URL并转为预览（仅允许http/https）
        text = text.replace(/!\[([^\]]*)\]\((https?:\/\/[^\)]+)\)/g, function(match, alt, url) {
            var safe = safeUrl(url);
            if (!safe) return '';
            return '<div class="ai-img-preview"><img src="' + safe + '" alt="' + escapeHtml(alt) + '" loading="lazy" decoding="async"><div class="ai-img-label"><span>' + escapeHtml(alt || '配图') + '</span><a href="' + safe + '" target="_blank">查看原图 ↗</a></div></div>';
        });

        // 独立图片URL（非markdown格式，排除已有HTML标签内的URL）
        text = text.replace(/(^|[^"'<=])(https?:\/\/[^\s"'<>]+\.(?:png|jpg|jpeg|gif|webp)(?:\?[^\s"']*)?)($|[^"'<>])/gi, function(match, pre, url, post) {
            if (match.indexOf('<img') !== -1 || match.indexOf('ai-img') !== -1) return match;
            var safe = safeUrl(url);
            if (!safe) return match;
            return pre + '<div class="ai-img-preview"><img src="' + safe + '" loading="lazy" decoding="async"><div class="ai-img-label"><span>配图</span><a href="' + safe + '" target="_blank">查看原图 ↗</a></div></div>' + post;
        });

        // 代码块
        text = text.replace(/```(\w*)\n([\s\S]*?)```/g, function(m, lang, code) {
            return '<pre><code>' + escapeHtml(code.trim()) + '</code></pre>';
        });

        // 行内代码
        text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

        // 标题
        text = text.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        text = text.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        text = text.replace(/^# (.+)$/gm, '<h1>$1</h1>');

        // 粗体、斜体
        text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*(.+?)\*/g, '<em>$1</em>');

        // 链接
        text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/g, '<a href="$2" target="_blank">$1</a>');

        // 引用
        text = text.replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>');

        // 列表
        text = text.replace(/^- (.+)$/gm, '<li>$1</li>');
        text = text.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');

        // 有序列表
        text = text.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');

        // 分割线
        text = text.replace(/^---$/gm, '<hr>');

        // 段落（双换行）
        text = text.replace(/\n\n/g, '</p><p>');
        // 单换行
        text = text.replace(/\n/g, '<br>');

        // 清理空段落
        text = text.replace(/<p><\/p>/g, '');
        text = text.replace(/<br>$/g, '');

        return text;
    }

    // 图片灯箱
    function openLightbox(src) {
        if (!/^https?:\/\//i.test(src)) return; // 安全校验
        var lb = document.createElement('div');
        lb.className = 'ai-img-lightbox';
        lb.innerHTML = '<img src="' + src + '">';
        lb.addEventListener('click', function() { document.body.removeChild(lb); });
        // ESC关闭
        function escHandler(e) { if (e.key === 'Escape') { document.body.removeChild(lb); document.removeEventListener('keydown', escHandler); } }
        document.addEventListener('keydown', escHandler);
        document.body.appendChild(lb);
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML.replace(/\n/g, '<br>');
    }

    // PJAX 兼容
    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('pjax:complete', function() {
        initDOM();
        if (messages) loadHistory();
    });

    // 移动端：下滑关闭面板
    var touchStartY = 0;
    document.addEventListener('touchstart', function(e) {
        if (panel && panel.style.display === 'flex') {
            touchStartY = e.touches[0].clientY;
        }
    }, { passive: true });
    document.addEventListener('touchmove', function(e) {
        if (!panel || panel.style.display !== 'flex') return;
        var header = panel.querySelector('.ai-agent-header');
        if (!header) return;
        var dy = e.touches[0].clientY - touchStartY;
        if (dy > 80 && touchStartY < header.getBoundingClientRect().bottom) {
            panel.style.display = 'none';
            unbindMobileKeyboard();
        }
    }, { passive: true });
})();
