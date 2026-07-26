<?php
// M-006 fix: add security check
if (!defined('EMLOG_ROOT')) {
    $emlog_root = dirname(dirname(dirname(__DIR__)));
    require_once $emlog_root . '/init.php';
}
/**
 * AI Agent 智能助手 - 设置导出/导入 API
 */

// 加载插件配置函数
require_once __DIR__ . '/ai_agent.php';

$action = Input::getStrVar('do', '');

if ($action === 'export') {
    $config = ai_agent_get_config();
    $export = $config;
    $export['llm_api_key'] = !empty($export['llm_api_key']) ? '***已配置***' : '';
    $export['img_api_key'] = !empty($export['img_api_key']) ? '***已配置***' : '';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="ai_agent_config_' . date('Ymd') . '.json"');
    echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if (!$data || !is_array($data)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 1, 'msg' => '无效的 JSON 数据'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $config = ai_agent_get_config();
    foreach (['llm_api_key', 'img_api_key'] as $key) {
        if (isset($data[$key]) && strpos($data[$key], '***') !== false) {
            unset($data[$key]);
        }
    }
    foreach ($data as $key => $value) {
        $config[$key] = $value;
    }
    ai_agent_save_config($config);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 0, 'msg' => '导入成功'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['code' => 1, 'msg' => '未知操作'], JSON_UNESCAPED_UNICODE);
