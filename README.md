# AI Agent 智能助手（Pro 完整版）

> emlog 博客平台首款基于 **ReAct Agent + 工具调用 + 三层记忆系统** 的智能运维插件

## 核心功能

- **ReAct Agent 引擎** — 多轮工具调用循环，最多3轮
- **18 个内置工具** — 文章管理、配图、音乐、视频、评论、统计等
- **三层记忆系统** — SOUL.md 人格 + 用户长期记忆 + 站点上下文
- **AI 自动发文** — 伪Cron触发，智能话题生成
- **3 源配图搜索** — Pexels / Unsplash / Pixabay
- **五级权限控制** — admin / editor / writer / visitor / guest
- **前台对话窗口** — Markdown渲染、响应式、PJAX兼容
- **完整后台设置** — 7 个 Tab，涵盖所有配置项

## 版本说明

| 版本 | 仓库 | 功能 |
|------|------|------|
| **Pro（本仓库）** | [emlog-ai-agent-pro](https://github.com/DJZY-CC/emlog-ai-agent-pro) | 全部 18 个工具、自动发文、配图、评论管理 |
| **Lite（公开版）** | [emlog-ai-agent-lite](https://github.com/DJZY-CC/emlog-ai-agent-lite) | 7 个只读工具、对话窗口、基础记忆 |

## 安装

1. 下载 `ai_agent.zip`
2. emlog 后台 → 插件管理 → 安装插件 → 上传 zip
3. 启用插件 → 设置 → 配置 LLM API

## 环境要求

- emlog Pro 2.x
- PHP 7.4+ / 8.1
- MySQL 5.6+
- PHP 扩展：cURL、JSON、PDO

## 许可证

MIT License - Copyright (c) 2026 DJZY-CC
