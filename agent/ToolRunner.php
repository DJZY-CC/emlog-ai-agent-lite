<?php
/**
 * AI Agent - 工具执行器
 * 负责路由工具调用、权限校验、结果格式化
 */

defined('EMLOG_ROOT') || exit('access denied!');

class AIAgent_ToolRunner
{
    /**
     * M-007: Escape LIKE wildcards for SQL safety
     */
    private function escapeLike($str)
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $str);
    }

    private $config;
    private $uid;
    private $role; // admin / user / guest

    public function __construct($config, $uid = 0, $role = 'guest')
    {
        $this->config = $config;
        $this->uid = $uid;
        $this->role = $role;
    }

    /**
     * 执行工具调用
     * @param string $tool_name 工具名称
     * @param array $params 工具参数
     * @return array ['success' => bool, 'data' => mixed, 'error' => string]
     */
    public function run($tool_name, $params = [])
    {
        // 工具禁用检查
        $disabled = array_filter(array_map('trim', explode(',', $this->config['disabled_tools'] ?? '')));
        if (in_array($tool_name, $disabled)) {
            return ['success' => false, 'data' => null, 'error' => "工具 {$tool_name} 已被管理员禁用"];
        }

        // 管理员专属工具检查
        $admin_only = array_filter(array_map('trim', explode(',', $this->config['admin_only_tool'] ?? '')));
        if (in_array($tool_name, $admin_only) && $this->role !== 'admin') {
            return ['success' => false, 'data' => null, 'error' => "工具 {$tool_name} 仅管理员可用"];
        }

        // 权限检查
        $perm_check = $this->checkPermission($tool_name);
        if (!$perm_check['allowed']) {
            return ['success' => false, 'data' => null, 'error' => $perm_check['message']];
        }

        // 高危操作拦截
        if ($this->config['danger_block'] && $this->isDangerous($tool_name, $params)) {
            return ['success' => false, 'data' => null, 'error' => '安全拦截：该操作被管理员禁止'];
        }

        // 工具路由
        $method = 'tool_' . $tool_name;
        if (!method_exists($this, $method)) {
            return ['success' => false, 'data' => null, 'error' => "未知工具：{$tool_name}"];
        }

        try {
            $result = $this->$method($params);
            return ['success' => true, 'data' => $result, 'error' => ''];
        } catch (Exception $e) {
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * 权限检查
     */
    private function checkPermission($tool_name)
    {
        // 写操作工具列表
        $write_tools = ['publish_article', 'update_article', 'generate_image', 'delete_article', 'search_images'];

        if (in_array($tool_name, $write_tools)) {
            // 发文权限
            if ($this->role === 'guest') {
                return ['allowed' => false, 'message' => '访客无权执行写操作，请先登录'];
            }
            if ($tool_name === 'publish_article') {
                $publish_roles = $this->config['publish_roles'] ?? ['admin'];
                if (!in_array($this->role, $publish_roles)) {
                    return ['allowed' => false, 'message' => '您的角色无权通过 AI 发布文章'];
                }
            }
        }

        return ['allowed' => true, 'message' => ''];
    }

    /**
     * 高危操作检测
     */
    private function isDangerous($tool_name, $params)
    {
        // 删除文章：未确认时拦截，要求二次确认
        if ($tool_name === 'delete_article') {
            $confirm = isset($params['confirm']) ? $params['confirm'] : false;
            if (!$confirm) {
                return true;
            }
        }
        return false;
    }

    // ========== 工具实现 ==========

    /**
     * 获取全站分类
     */
    private function tool_get_categories($params)
    {
        $db = MySql::getInstance();
        $result = [];
        $query = $db->query("SELECT sid, sortname, description FROM " . DB_PREFIX . "sort ORDER BY sid ASC");
        while ($row = $db->fetch_array($query)) {
            $result[] = [
                'id'          => intval($row['sid']),
                'name'        => $row['sortname'],
                'description' => $row['description'],
            ];
        }
        return $result;
    }

    /**
     * 获取标签列表
     */
    private function tool_get_tags($params)
    {
        $keyword = isset($params['keyword']) ? addslashes($params['keyword']) : '';
        $keyword = $this->escapeLike($keyword);
        $db = MySql::getInstance();
        $result = [];

        $where = '';
        if (!empty($keyword)) {
            $where = " WHERE tagname LIKE '%{$keyword}%'";
        }

        $query = $db->query("SELECT tid, tagname FROM " . DB_PREFIX . "tag{$where} ORDER BY tid DESC LIMIT " . max(10, intval($this->config['tag_query_limit'] ?? 50)));
        while ($row = $db->fetch_array($query)) {
            $result[] = [
                'id'      => intval($row['tid']),
                'name'    => $row['tagname'],
            ];
        }
        return $result;
    }

    /**
     * 搜索文章
     */
    private function tool_search_articles($params)
    {
        $keyword = isset($params['keyword']) ? addslashes($params['keyword']) : '';
        $keyword = $this->escapeLike($keyword);  // M-007: escape LIKE wildcards
        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $limit = isset($params['limit']) ? max(1, min(50, intval($params['limit']))) : 10;
        $offset = ($page - 1) * $limit;

        if (empty($keyword)) {
            return ['articles' => [], 'total' => 0];
        }

        $db = MySql::getInstance();

        // 统计总数
        $count_row = $db->once_fetch_array(
            "SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "blog WHERE hide='n' AND (title LIKE '%{$keyword}%' OR content LIKE '%{$keyword}%')"
        );
        $total = intval($count_row['cnt']);

        // 查询文章
        $result = [];
        $query = $db->query(
            "SELECT gid, title, sortid, views, comnum, date, cover FROM " . DB_PREFIX . "blog 
             WHERE hide='n' AND (title LIKE '%{$keyword}%' OR content LIKE '%{$keyword}%') 
             ORDER BY date DESC LIMIT {$offset}, {$limit}"
        );
        while ($row = $db->fetch_array($query)) {
            $result[] = [
                'aid'      => intval($row['gid']),
                'title'    => $row['title'],
                'sortid'   => intval($row['sortid']),
                'views'    => intval($row['views']),
                'comments' => intval($row['comnum']),
                'date'     => $row['date'],
                'cover'    => $row['cover'] ?? '',
            'url'      => Url::log(intval($row['gid'])),
            ];
        }

        return ['articles' => $result, 'total' => $total, 'page' => $page];
    }

    /**
     * 获取文章列表
     */
    private function tool_get_articles($params)
    {
        $sortid = isset($params['sortid']) ? intval($params['sortid']) : 0;
        $order = isset($params['order']) ? $params['order'] : 'new';
        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $limit = isset($params['limit']) ? max(1, min(50, intval($params['limit']))) : 10;
        $offset = ($page - 1) * $limit;

        $db = MySql::getInstance();
        $where = "WHERE hide='n'";
        if ($sortid > 0) {
            $where .= " AND sortid=" . $sortid;
        }

        $order_sql = 'ORDER BY date DESC';
        if ($order === 'old') {
            $order_sql = 'ORDER BY date ASC';
        } elseif ($order === 'hot') {
            $order_sql = 'ORDER BY views DESC';
        }

        $result = [];
        $query = $db->query(
            "SELECT gid, title, sortid, views, comnum, date, cover FROM " . DB_PREFIX . "blog 
             {$where} {$order_sql} LIMIT {$offset}, {$limit}"
        );
        while ($row = $db->fetch_array($query)) {
            $result[] = [
                'aid'      => intval($row['gid']),
                'title'    => $row['title'],
                'sortid'   => intval($row['sortid']),
                'views'    => intval($row['views']),
                'comments' => intval($row['comnum']),
                'date'     => $row['date'],
                'cover'    => $row['cover'] ?? '',
            'url'      => Url::log(intval($row['gid'])),
            ];
        }

        return $result;
    }

    /**
     * 获取文章详情
     */
    private function tool_get_article($params)
    {
        $aid = isset($params['aid']) ? intval($params['aid']) : 0;
        if ($aid <= 0) {
            throw new Exception('无效的文章ID');
        }

        $db = MySql::getInstance();
        $row = $db->once_fetch_array(
            "SELECT gid, title, content, sortid, views, comnum, date, tags, cover, excerpt, hide FROM " . DB_PREFIX . "blog WHERE gid={$aid}"
        );

        if (!$row) {
            throw new Exception("文章 #{$aid} 不存在");
        }

        // 将标签ID转换为标签名称
        $tag_names = [];
        if (!empty($row['tags'])) {
            $tag_ids = explode(',', $row['tags']);
            foreach ($tag_ids as $tid) {
                $tid = intval($tid);
                if ($tid > 0) {
                    $tag_row = $db->once_fetch_array("SELECT tagname FROM " . DB_PREFIX . "tag WHERE tid={$tid}");
                    if ($tag_row) $tag_names[] = $tag_row['tagname'];
                }
            }
        }

        return [
            'aid'      => intval($row['gid']),
            'title'    => $row['title'],
            'content'  => $row['content'],
            'sortid'   => intval($row['sortid']),
            'views'    => intval($row['views']),
            'comments' => intval($row['comnum']),
            'date'     => $row['date'],
            'tags'     => implode(',', $tag_names),
            'cover'    => $row['cover'] ?? '',
            'excerpt'  => $row['excerpt'] ?? '',
            'hide'     => $row['hide'] ?? 'n',
            'url'      => Url::log(intval($row['gid'])),
        ];
    }

    /**
     * 发布文章
     */
    private function tool_publish_article($params)
    {
        $title = isset($params['title']) ? trim($params['title']) : '';
        $content = isset($params['content']) ? $params['content'] : '';
        $sortid = isset($params['sortid']) ? intval($params['sortid']) : 0;
        $tags = isset($params['tags']) ? trim($params['tags']) : '';
        $top = isset($params['top']) ? intval($params['top']) : 0;
        $allow_comment = isset($params['allow_comment']) ? intval($params['allow_comment']) : 1;

        if (empty($title)) {
            throw new Exception('文章标题不能为空');
        }
        if (empty($content)) {
            throw new Exception('文章内容不能为空');
        }

        // 检查分类是否存在
        if ($sortid > 0) {
            $db = MySql::getInstance();
            $sort = $db->once_fetch_array("SELECT sid FROM " . DB_PREFIX . "sort WHERE sid={$sortid}");
            if (!$sort) {
                throw new Exception("分类 #{$sortid} 不存在，请先调用 get_categories 查看可用分类");
            }
        }

        // 自动生成摘要
        $excerpt = function_exists('subContent') ? subContent($content, 150, 1) : mb_substr(strip_tags($content), 0, 150);

        // 构建文章数据
        $logData = [
            'title'         => $title,
            'content'       => $content,
            'excerpt'       => $excerpt,
            'sortid'        => $sortid,
            'tags'          => '',
            'cover'         => '',
            'alias'         => '',
            'top'           => $top ? 'y' : 'n',
            'sortop'        => 'n',
            'allow_remark'  => $allow_comment ? 'y' : 'n',
            'author'        => $this->uid,
            'date'          => time(),
            'hide'          => $this->config['draft_default'] ? 'y' : 'n',
            'checked'       => 'y',
            'type'          => 'blog',
        ];

        // 使用 emlog 的 Log_Model 保存文章
        $log_model = new Log_Model();
        $blogid = $log_model->addlog($logData);

        if ($blogid > 0) {
            // 使用 Tag_Model 正确处理标签（存入标签ID而非名称）
            if (!empty($tags)) {
                $tag_model = new Tag_Model();
                $tag_model->updateTag($tags, $blogid);
            }
            return [
                'aid'     => $blogid,
                'title'   => $title,
                'status'  => $this->config['draft_default'] ? 'draft' : 'published',
                'message' => $this->config['draft_default'] ? '文章已保存为草稿，等待审核' : '文章已成功发布',
            ];
        } else {
            throw new Exception('文章保存失败，请检查数据库连接');
        }
    }

    /**
     * 修改文章
     */
    private function tool_update_article($params)
    {
        $aid = isset($params['aid']) ? intval($params['aid']) : 0;
        if ($aid <= 0) {
            throw new Exception('无效的文章ID');
        }

        $db = MySql::getInstance();

        // 检查文章是否存在
        $existing = $db->once_fetch_array("SELECT gid, author, title, content, sortid FROM " . DB_PREFIX . "blog WHERE gid={$aid}");
        if (!$existing) {
            throw new Exception("文章 #{$aid} 不存在");
        }

        // 权限检查：普通用户只能修改自己的文章
        if ($this->role !== 'admin' && $existing['author'] != $this->uid) {
            throw new Exception('无权修改他人的文章');
        }

        $update_fields = [];

        if (isset($params['title']) && $params['title'] !== $existing['title']) {
            $update_fields[] = "title='" . addslashes($params['title']) . "'";
        }
        if (isset($params['content']) && $params['content'] !== $existing['content']) {
            $update_fields[] = "content='" . addslashes($params['content']) . "'";
        }
        if (isset($params['sortid']) && intval($params['sortid']) !== intval($existing['sortid'])) {
            $sortid = intval($params['sortid']);
            $update_fields[] = "sortid={$sortid}";
        }
        if (isset($params['tags'])) {
            // 使用 Tag_Model 正确处理标签
            $tag_model = new Tag_Model();
            $tag_model->updateTag($params['tags'], $aid);
        }

        if (!empty($update_fields)) {
            $db->query("UPDATE " . DB_PREFIX . "blog SET " . implode(',', $update_fields) . " WHERE gid={$aid}");
        } elseif (!isset($params['tags'])) {
            return [
                'aid'     => $aid,
                'updated' => false,
                'message' => "文章 #{$aid} 内容无变化，无需更新",
            ];
        }

        return [
            'aid'     => $aid,
            'updated' => true,
            'message' => "文章 #{$aid} 已更新" . (count($update_fields) > 0 ? '（' . count($update_fields) . '个字段）' : '（仅标签）'),
        ];
    }

    /**
     * AI 生成图片
     */
    private function tool_generate_image($params)
    {
        $prompt = isset($params['prompt']) ? trim($params['prompt']) : '';
        $size = isset($params['size']) ? $params['size'] : $this->config['img_size'];
        $aid = isset($params['aid']) ? intval($params['aid']) : 0;

        if (empty($prompt)) {
            throw new Exception('图片描述不能为空');
        }

        // 直接调用图像生成API
        $api_url = $this->config['img_api_url'] ?: Option::get('ai_img_api_url');
        $api_key = $this->config['img_api_key'] ?: Option::get('ai_img_api_key');
        $model = $this->config['img_model'] ?: Option::get('ai_img_model') ?: 'Kwai-Kolors/Kolors';

        if (empty($api_url) || empty($api_key)) {
            throw new Exception('图像生成模型未配置，请在插件设置或后台系统设置中配置');
        }

        $post_data = [
            'model'       => $model,
            'prompt'      => $prompt,
            'image_size'  => $size,
            'batch_size'  => 1,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('图像生成请求失败: ' . $curl_error);
        }

        // 检查 HTTP 状态码，提供友好错误提示
        if ($http_code === 401) {
            throw new Exception('生成配图 API Key 无效（需更新配置），请在插件设置中更新配图 API 密钥');
        }
        if ($http_code === 403) {
            throw new Exception('生成配图 API 权限不足，请检查 API Key 权限或账户余额');
        }
        if ($http_code === 429) {
            throw new Exception('生成配图 API 请求频率超限，请稍后再试');
        }
        if ($http_code >= 400) {
            throw new Exception('生成配图 API 请求失败 (HTTP ' . $http_code . ')，请检查 API 配置');
        }

        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception('图像生成响应解析失败');
        }

        if (isset($data['error'])) {
            $err_msg = $data['error']['message'] ?? json_encode($data['error']);
            if (stripos($err_msg, 'invalid') !== false || stripos($err_msg, 'unauthorized') !== false) {
                throw new Exception('生成配图 API Key 无效（需更新配置），请在插件设置中更新配图 API 密钥');
            }
            throw new Exception('图像生成失败: ' . $err_msg);
        }
        if (isset($data['code']) && $data['code'] !== 0 && $data['code'] !== 200) {
            throw new Exception('图像生成失败: ' . ($data['message'] ?? '未知错误') . ' (code:' . $data['code'] . ')');
        }

        // 提取图片URL
        $image_url = null;
        if (isset($data['images'][0]['url'])) {
            $image_url = $data['images'][0]['url'];
        } elseif (isset($data['data'][0]['url'])) {
            $image_url = $data['data'][0]['url'];
        } elseif (isset($data['url'])) {
            $image_url = $data['url'];
        }

        if (empty($image_url)) {
            throw new Exception('图像生成成功但未返回图片URL');
        }

        // 下载图片并保存到博客
        $local_path = $this->downloadAndSaveImage($image_url, $aid);

        $result = [
            'url'       => $image_url,
            'local_url' => $local_path,
            'prompt'    => $prompt,
            'size'      => $size,
            'model'     => $model,
        ];

        // 如果指定了文章ID，设置为封面
        if ($aid > 0 && !empty($local_path)) {
            $this->setArticleCover($aid, $local_path);
            $result['cover_set'] = true;
            $result['aid'] = $aid;
        }

        return $result;
    }

    /**
     * 下载远程图片并保存到博客上传目录
     */
    /**
     * 压缩图片
     */
    private function compressImage($filepath)
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return; // GD库不可用
        }

        $quality = intval($this->config['img_compress_quality'] ?? 80);
        $max_width = intval($this->config['img_compress_max_width'] ?? 1920);

        $image_info = getimagesize($filepath);
        if (!$image_info) return;

        $mime = $image_info['mime'];
        $orig_w = $image_info[0];
        $orig_h = $image_info[1];

        // 创建图像资源
        switch ($mime) {
            case 'image/jpeg':
                $src = imagecreatefromjpeg($filepath);
                break;
            case 'image/png':
                $src = imagecreatefrompng($filepath);
                break;
            case 'image/webp':
                $src = imagecreatefromwebp($filepath);
                break;
            default:
                return;
        }
        if (!$src) return;

        // 计算缩放
        $new_w = $orig_w;
        $new_h = $orig_h;
        if ($max_width > 0 && $orig_w > $max_width) {
            $new_w = $max_width;
            $new_h = intval($orig_h * ($max_width / $orig_w));
        }

        // 创建新图像
        $dst = imagecreatetruecolor($new_w, $new_h);
        if ($mime === 'image/png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            // 填充白色背景（PNG透明转JPEG时不会变黑）
            $white = imagecolorallocatealpha($dst, 255, 255, 255, 0);
            imagefill($dst, 0, 0, $white);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);

        // 保存为JPEG（压缩率最高）
        $jpg_path = preg_replace('/\.png$/i', '.jpg', $filepath);
        imagejpeg($dst, $jpg_path, $quality);

        imagedestroy($src);
        imagedestroy($dst);

        // 如果JPEG更小，替换原文件
        if (file_exists($jpg_path) && filesize($jpg_path) < filesize($filepath)) {
            unlink($filepath);
            rename($jpg_path, $filepath);
        } elseif (file_exists($jpg_path)) {
            unlink($jpg_path);
        }
    }

    private function downloadAndSaveImage($remote_url, $aid = 0)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $remote_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $image_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $data_size = strlen($image_data ?: '');
        curl_close($ch);

        // Debug日志
        if (!empty($this->config['debug_log_enabled'])) {
            $log_path = !empty($this->config['error_log_path']) ? $this->config['error_log_path'] : AI_AGENT_DIR . '/ai/logs/img_download.log';
            file_put_contents($log_path, date('Y-m-d H:i:s') . " url={$remote_url} http={$http_code} size={$data_size} error={$curl_error}\n", FILE_APPEND);
        }

        if ($image_data === false || $http_code !== 200) {
            return '';
        }

        // 创建上传目录
        $upload_dir = EMLOG_ROOT . '/content/upload/ai_images/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // 生成文件名
        $filename = 'ai_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 6) . '.png';
        $filepath = $upload_dir . $filename;

        // S-003: Validate image data before saving (skip if finfo not available)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_buffer($finfo, $image_data);
            finfo_close($finfo);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                file_put_contents(AI_AGENT_DIR . '/ai/logs/img_download.log', date('Y-m-d H:i:s') . " INVALID MIME: {$mime} url={$remote_url}\n", FILE_APPEND);
                return '';
            }
        }
        $saved = file_put_contents($filepath, $image_data);
        if ($saved === false) {
            file_put_contents(AI_AGENT_DIR . '/ai/logs/img_download.log', date('Y-m-d H:i:s') . " SAVE FAILED: {$filepath}\n", FILE_APPEND);
            return '';
        }
        $orig_size = $saved;

        // 压缩图片
        if (!empty($this->config['img_compress_enabled'])) {
            $this->compressImage($filepath);
            $compressed_size = filesize($filepath);
            if (!empty($this->config['debug_log_enabled'])) {
                $log_path = !empty($this->config['error_log_path']) ? $this->config['error_log_path'] : AI_AGENT_DIR . '/ai/logs/img_download.log';
                file_put_contents($log_path, date('Y-m-d H:i:s') . " COMPRESSED: {$orig_size} -> {$compressed_size} bytes\n", FILE_APPEND);
            }
        }

        file_put_contents(AI_AGENT_DIR . '/ai/logs/img_download.log', date('Y-m-d H:i:s') . " SAVED: {$filepath} (" . filesize($filepath) . " bytes)\n", FILE_APPEND);

        // 返回相对URL
        $blog_url = Option::get('blogurl');
        return rtrim($blog_url, '/') . '/content/upload/ai_images/' . $filename;
    }

    /**
     * 设置文章封面图
     */
    private function setArticleCover($aid, $cover_url)
    {
        $db = MySql::getInstance();
        $aid = intval($aid);
        $cover = addslashes($cover_url);
        $db->query("UPDATE " . DB_PREFIX . "blog SET cover='{$cover}' WHERE gid={$aid}");
    }

    /**
     * 获取站点统计
     */
    private function tool_get_stats($params)
    {
        $db = MySql::getInstance();

        $articles = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "blog WHERE hide='n'");
        $drafts = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "blog WHERE hide='y'");
        $categories = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "sort");
        $comments = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "comment");
        $tags = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "tag");
        $views = $db->once_fetch_array("SELECT SUM(views) AS total FROM " . DB_PREFIX . "blog WHERE hide='n'");

        return [
            'articles'     => intval($articles['cnt']),
            'drafts'       => intval($drafts['cnt']),
            'categories'   => intval($categories['cnt']),
            'comments'     => intval($comments['cnt']),
            'tags'         => intval($tags['cnt']),
            'total_views'  => intval($views['total'] ?: 0),
        ];
    }

    /**
     * 批量为无封面文章生成配图
     */
    private function tool_batch_generate_covers($params)
    {
        $sortid = isset($params['sortid']) ? intval($params['sortid']) : 0;
        $limit = isset($params['limit']) ? max(1, min(intval($this->config['img_batch_limit'] ?? 5), intval($params['limit']))) : max(1, intval($this->config['img_batch_limit'] ?? 5));

        $db = MySql::getInstance();
        $where = "hide='n' AND (cover='' OR cover IS NULL)";
        if ($sortid > 0) {
            $where .= " AND sortid={$sortid}";
        }

        $result = [];
        $count = 0;
        $query = $db->query("SELECT gid, title FROM " . DB_PREFIX . "blog WHERE {$where} ORDER BY gid DESC LIMIT {$limit}");

        while ($row = $db->fetch_array($query)) {
            $aid = intval($row['gid']);
            $title = $row['title'];

            // 生成配图
            $prompt = "cover image for article: " . mb_substr($title, 0, 50);
            try {
                $img_result = $this->tool_generate_image(['prompt' => $prompt, 'aid' => $aid]);
                $result[] = ['aid' => $aid, 'title' => $title, 'success' => true];
                $count++;
            } catch (Exception $e) {
                $result[] = ['aid' => $aid, 'title' => $title, 'success' => false, 'error' => $e->getMessage()];
            }

            // 避免API限流
            $interval = max(0, intval($this->config['img_batch_interval'] ?? 1));
            if ($interval > 0 && $count < $limit) {
                usleep($interval * 500000); // 用 usleep 替代 sleep，缩短为0.5x间隔
            }
        }

        return [
            'processed' => count($result),
            'success'   => $count,
            'results'   => $result,
        ];
    }

    /**
     * 搜索网络图片并保存到本地
     * 支持 Pexels / Unsplash / Pixabay 三个免费图片源
     */
    private function tool_search_images($params)
    {
        $query = isset($params['keyword']) ? trim($params['keyword']) : '';
        $aid = isset($params['aid']) ? intval($params['aid']) : 0;
        $orientation = isset($params['orientation']) ? $params['orientation'] : 'landscape';
        $limit = isset($params['limit']) ? min(5, max(1, intval($params['limit']))) : 1;
        $provider = isset($params['provider']) ? $params['provider'] : ($this->config['img_search_provider'] ?? 'pexels');

        if (empty($query)) {
            throw new Exception('搜索关键词不能为空');
        }

        // 尝试多个图片源（按优先级）
        $providers = array_filter(array_map('trim', explode(',', $provider)));
        if (empty($providers)) $providers = ['pexels'];

        $last_error = '';
        foreach ($providers as $prov) {
            try {
                $result = $this->searchImagesFromProvider($prov, $query, $orientation, $limit, $aid);
                if ($result) return $result;
            } catch (Exception $e) {
                $last_error = $e->getMessage();
                continue;
            }
        }

        throw new Exception($last_error ?: '所有图片源均未找到结果，换个关键词试试');
    }

    /**
     * 从指定图片源搜索图片
     */
    private function searchImagesFromProvider($provider, $query, $orientation, $limit, $aid)
    {
        switch ($provider) {
            case 'pexels':
                return $this->searchPexels($query, $orientation, $limit, $aid);
            case 'unsplash':
                return $this->searchUnsplash($query, $orientation, $limit, $aid);
            case 'pixabay':
                return $this->searchPixabay($query, $orientation, $limit, $aid);
            default:
                throw new Exception("不支持的图片源: {$provider}");
        }
    }

    /**
     * Pexels 图片搜索
     */
    private function searchPexels($query, $orientation, $limit, $aid)
    {
        $api_key = $this->config['img_search_api_key'] ?? '';
        if (empty($api_key)) throw new Exception('Pexels API Key 未配置');

        $search_url = 'https://api.pexels.com/v1/search?' . http_build_query([
            'query' => $query,
            'per_page' => max(1, min(15, $limit * 3)),
            'orientation' => in_array($orientation, ['landscape', 'portrait', 'square']) ? $orientation : 'landscape',
            'size' => 'medium',
        ]);

        $response = $this->httpGet($search_url, ['Authorization: ' . $api_key]);
        if (!$response) throw new Exception('Pexels 请求失败');

        $data = json_decode($response['body'], true);
        if (!$data || empty($data['photos'])) throw new Exception('Pexels 未找到相关图片');

        $photo = $data['photos'][0];
        $image_url = $photo['src']['large'] ?? $photo['src']['original'] ?? $photo['src']['medium'] ?? '';
        if (empty($image_url)) throw new Exception('Pexels 图片URL获取失败');

        $local_path = $this->downloadAndSaveImage($image_url, $aid);
        if (empty($local_path)) throw new Exception('图片下载失败');

        $result = [
            'source' => 'pexels', 'photo_id' => $photo['id'] ?? 0,
            'photographer' => $photo['photographer'] ?? '',
            'photo_page' => $photo['url'] ?? '',
            'image_url' => $image_url, 'local_url' => $local_path,
            'query' => $query, 'total_found' => $data['total_results'] ?? 0,
        ];
        if ($aid > 0) { $this->setArticleCover($aid, $local_path); $result['cover_set'] = true; $result['aid'] = $aid; }
        return $result;
    }

    /**
     * Unsplash 图片搜索
     */
    private function searchUnsplash($query, $orientation, $limit, $aid)
    {
        $api_key = $this->config['img_search_unsplash_key'] ?? '';
        if (empty($api_key)) throw new Exception('Unsplash API Key 未配置');

        $orient_map = ['landscape' => 'landscape', 'portrait' => 'portrait', 'square' => 'squarish'];
        $search_url = 'https://api.unsplash.com/search/photos?' . http_build_query([
            'query' => $query,
            'per_page' => max(1, min(15, $limit * 3)),
            'orientation' => $orient_map[$orientation] ?? 'landscape',
        ]);

        $response = $this->httpGet($search_url, ['Authorization: Client-ID ' . $api_key]);
        if (!$response) throw new Exception('Unsplash 请求失败');

        $data = json_decode($response['body'], true);
        if (!$data || empty($data['results'])) throw new Exception('Unsplash 未找到相关图片');

        $photo = $data['results'][0];
        $image_url = $photo['urls']['regular'] ?? $photo['urls']['full'] ?? $photo['urls']['small'] ?? '';
        if (empty($image_url)) throw new Exception('Unsplash 图片URL获取失败');

        $local_path = $this->downloadAndSaveImage($image_url, $aid);
        if (empty($local_path)) throw new Exception('图片下载失败');

        $result = [
            'source' => 'unsplash', 'photo_id' => $photo['id'] ?? '',
            'photographer' => $photo['user']['name'] ?? '',
            'photo_page' => $photo['links']['html'] ?? '',
            'image_url' => $image_url, 'local_url' => $local_path,
            'query' => $query, 'total_found' => $data['total'] ?? 0,
        ];
        if ($aid > 0) { $this->setArticleCover($aid, $local_path); $result['cover_set'] = true; $result['aid'] = $aid; }
        return $result;
    }

    /**
     * Pixabay 图片搜索
     */
    private function searchPixabay($query, $orientation, $limit, $aid)
    {
        $api_key = $this->config['img_search_pixabay_key'] ?? '';
        if (empty($api_key)) throw new Exception('Pixabay API Key 未配置');

        $orient_map = ['landscape' => 'horizontal', 'portrait' => 'vertical', 'square' => 'horizontal'];
        $search_url = 'https://pixabay.com/api/?' . http_build_query([
            'key' => $api_key,
            'q' => $query,
            'per_page' => max(3, min(20, $limit * 3)),
            'orientation' => $orient_map[$orientation] ?? 'horizontal',
            'image_type' => 'photo',
            'safesearch' => 'true',
        ]);

        $response = $this->httpGet($search_url);
        if (!$response) throw new Exception('Pixabay 请求失败');

        $data = json_decode($response['body'], true);
        if (!$data || empty($data['hits'])) throw new Exception('Pixabay 未找到相关图片');

        $photo = $data['hits'][0];
        $image_url = $photo['largeImageURL'] ?? $photo['webformatURL'] ?? '';
        if (empty($image_url)) throw new Exception('Pixabay 图片URL获取失败');

        $local_path = $this->downloadAndSaveImage($image_url, $aid);
        if (empty($local_path)) throw new Exception('图片下载失败');

        $result = [
            'source' => 'pixabay', 'photo_id' => $photo['id'] ?? 0,
            'photographer' => $photo['user'] ?? '',
            'photo_page' => $photo['pageURL'] ?? '',
            'image_url' => $image_url, 'local_url' => $local_path,
            'query' => $query, 'total_found' => $data['totalHits'] ?? 0,
        ];
        if ($aid > 0) { $this->setArticleCover($aid, $local_path); $result['cover_set'] = true; $result['aid'] = $aid; }
        return $result;
    }

    /**
     * 通用 HTTP GET 请求
     */
    private function httpGet($url, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code !== 200) return null;
        return ['body' => $body, 'code' => $code];
    }

    /**
     * 搜索音乐
     */

    /**
     * 搜索音乐
     */
    private function tool_search_music($params)
    {
        $keyword = trim($params['keyword'] ?? '');
        $platform = trim($params['platform'] ?? 'netease');

        if (empty($keyword)) {
            throw new Exception('歌曲名不能为空');
        }

        // 使用网易云音乐搜索API
        if ($platform === 'netease') {
            $search_url = 'https://music.163.com/api/search/get?s=' . urlencode($keyword) . '&type=1&limit=5';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $search_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Referer: https://music.163.com/']);
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);
            $songs = $data['result']['songs'] ?? [];

            if (empty($songs)) {
                return ['success' => false, 'message' => '未找到相关歌曲'];
            }

            $results = [];
            foreach (array_slice($songs, 0, 3) as $song) {
                $song_id = $song['id'];
                $song_name = $song['name'];
                $artist = implode('/', array_map(function($a) { return $a['name']; }, $song['artists'] ?? []));
                $embed_url = "https://music.163.com/outchain/player?type=2&id={$song_id}&auto=0&height=66";
                $results[] = [
                    'id' => $song_id,
                    'name' => $song_name,
                    'artist' => $artist,
                    'embed_url' => $embed_url,
                    'embed_html' => '<iframe frameborder="no" border="0" marginwidth="0" marginheight="0" width="330" height="86" src="' . $embed_url . '"></iframe>',
                ];
            }

            return ['success' => true, 'songs' => $results, 'message' => "找到 " . count($results) . " 首歌曲"];
        }

        return ['success' => false, 'message' => '不支持的音乐平台'];
    }

    /**
     * 搜索视频
     */
    private function tool_search_video($params)
    {
        $keyword = trim($params['keyword'] ?? '');
        $platform = trim($params['platform'] ?? 'bilibili');

        if (empty($keyword)) {
            throw new Exception('视频关键词不能为空');
        }

        // B站搜索
        if ($platform === 'bilibili') {
            $search_url = 'https://api.bilibili.com/x/web-interface/search/type?search_type=video&keyword=' . urlencode($keyword) . '&page=1&pagesize=3';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $search_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Mozilla/5.0']);
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);
            $videos = $data['data']['result'] ?? [];

            if (empty($videos)) {
                return ['success' => false, 'message' => '未找到相关视频'];
            }

            $results = [];
            foreach (array_slice($videos, 0, 3) as $video) {
                $bvid = $video['bvid'];
                $title = strip_tags($video['title']);
                $author = $video['author'] ?? '';
                $play = $video['play'] ?? 0;
                $duration = $video['duration'] ?? '';
                $embed_url = "https://player.bilibili.com/player.html?bvid={$bvid}&high_quality=1";
                $results[] = [
                    'bvid' => $bvid,
                    'title' => $title,
                    'author' => $author,
                    'play' => $play,
                    'duration' => $duration,
                    'embed_url' => $embed_url,
                    'embed_html' => '<iframe src="' . $embed_url . '" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true" width="100%" height="500"></iframe>',
                ];
            }

            return ['success' => true, 'videos' => $results, 'message' => "找到 " . count($results) . " 个视频"];
        }

        return ['success' => false, 'message' => '不支持的视频平台'];
    }

    /**
     * 发布多媒体文章
     */
    private function tool_publish_media_post($params)
    {
        $title = trim($params['title'] ?? '');
        $content = trim($params['content'] ?? '');
        $media_type = trim($params['media_type'] ?? 'music');
        $media_url = trim($params['media_url'] ?? '');
        $sortid = intval($params['sortid'] ?? 0);
        $tags = trim($params['tags'] ?? '');

        if (empty($title) || empty($content)) {
            throw new Exception('标题和内容不能为空');
        }

        // 构建嵌入代码
        $embed_html = '';
        if ($media_type === 'music') {
            $embed_html = '<div class="music-player"><iframe frameborder="no" border="0" marginwidth="0" marginheight="0" width="330" height="86" src="' . $media_url . '"></iframe></div>';
        } elseif ($media_type === 'video') {
            $embed_html = '<div class="video-player"><iframe src="' . $media_url . '" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true" width="100%" height="500"></iframe></div>';
        }

        // 组合内容
        $full_content = $embed_html . "\n\n" . $content;

        // 调用发布文章
        return $this->tool_publish_article([
            'title' => $title,
            'content' => $full_content,
            'sortid' => $sortid,
            'tags' => $tags,
        ]);
    }

    /**
     * 批量添加标签
     */
    private function tool_batch_add_tags($params)
    {
        $tags = isset($params['tags']) ? trim($params['tags']) : '';
        $sortid = isset($params['sortid']) ? intval($params['sortid']) : 0;
        $gids_str = isset($params['gids']) ? trim($params['gids']) : '';

        if (empty($tags)) {
            throw new Exception('标签不能为空');
        }

        $db = MySql::getInstance();
        $gid_list = [];

        if (!empty($gids_str)) {
            $gid_list = array_map('intval', explode(',', $gids_str));
        } elseif ($sortid > 0) {
            $query = $db->query("SELECT gid FROM " . DB_PREFIX . "blog WHERE hide='n' AND sortid={$sortid}");
            while ($row = $db->fetch_array($query)) {
                $gid_list[] = intval($row['gid']);
            }
        } else {
            throw new Exception('请指定分类ID或文章ID列表');
        }

        $tag_model = new Tag_Model();
        $count = 0;
        foreach ($gid_list as $aid) {
            $tag_model->updateTag($tags, $aid);
            $count++;
        }

        return [
            'processed' => $count,
            'tags'      => $tags,
            'message'   => "已为 {$count} 篇文章添加标签: {$tags}",
        ];
    }

    /**
     * delete article (needs confirmation)
     */
    private function tool_delete_article($params)
    {
        $aid = isset($params['aid']) ? intval($params['aid']) : 0;
        $confirm = isset($params['confirm']) ? $params['confirm'] : false;
        if ($aid <= 0) throw new Exception('invalid article id');
        $db = MySql::getInstance();
        $existing = $db->once_fetch_array("SELECT gid, title, author FROM " . DB_PREFIX . "blog WHERE gid={$aid}");
        if (!$existing) throw new Exception("article #{$aid} not found");
        if ($this->role !== 'admin' && $existing['author'] != $this->uid) throw new Exception('no permission');
        if (!$confirm) {
            return ['aid' => $aid, 'title' => $existing['title'], 'deleted' => false, 'message' => "Confirm delete article #{$aid}: {$existing['title']}? Set confirm=true to proceed."];
        }
        $db->query("DELETE FROM " . DB_PREFIX . "blog WHERE gid={$aid}");
        $db->query("DELETE FROM " . DB_PREFIX . "tag WHERE gid={$aid}");
        $db->query("DELETE FROM " . DB_PREFIX . "comment WHERE gid={$aid}");
        return ['aid' => $aid, 'title' => $existing['title'], 'deleted' => true, 'message' => "Article #{$aid} deleted (including comments)"];
    }

    /**
     * get comments list
     */
    private function tool_get_comments($params)
    {
        $aid = isset($params['aid']) ? intval($params['aid']) : 0;
        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $limit = isset($params['limit']) ? max(1, min(50, intval($params['limit']))) : 10;
        $status = isset($params['status']) ? $params['status'] : 'all';
        $offset = ($page - 1) * $limit;
        $db = MySql::getInstance();
        $where = "1=1";
        if ($aid > 0) $where .= " AND c.gid={$aid}";
        if ($status === 'approved') $where .= " AND c.hide='n'";
        elseif ($status === 'pending') $where .= " AND c.hide='y'";
        $count_row = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "comment c WHERE {$where}");
        $total = intval($count_row['cnt']);
        $result = [];
        $query = $db->query("SELECT c.cid, c.gid, c.pid, c.author, c.content, c.mail, c.ip, c.date, c.hide, b.title AS article_title FROM " . DB_PREFIX . "comment c LEFT JOIN " . DB_PREFIX . "blog b ON c.gid = b.gid WHERE {$where} ORDER BY c.date DESC LIMIT {$offset}, {$limit}");
        while ($row = $db->fetch_array($query)) {
            $result[] = ['cid' => intval($row['cid']), 'aid' => intval($row['gid']), 'pid' => intval($row['pid']), 'author' => $row['author'], 'content' => $row['content'], 'mail' => $row['mail'], 'ip' => $row['ip'], 'date' => $row['date'], 'status' => $row['hide'] === 'n' ? 'approved' : 'pending', 'article_title' => $row['article_title'] ?? ''];
        }
        return ['comments' => $result, 'total' => $total, 'page' => $page];
    }

    /**
     * reply to comment as AI
     */
    private function tool_reply_comment($params)
    {
        $cid = isset($params['cid']) ? intval($params['cid']) : 0;
        $reply_content = isset($params['reply']) ? trim($params['reply']) : '';
        if ($cid <= 0) throw new Exception('invalid comment id');
        if (empty($reply_content)) throw new Exception('reply content empty');
        $db = MySql::getInstance();
        $comment = $db->once_fetch_array("SELECT cid, gid, author, content FROM " . DB_PREFIX . "comment WHERE cid={$cid}");
        if (!$comment) throw new Exception("comment #{$cid} not found");
        $article = $db->once_fetch_array("SELECT gid, title FROM " . DB_PREFIX . "blog WHERE gid=" . intval($comment['gid']));
        if (!$article) throw new Exception("article not found");
        $now = date('Y-m-d H:i:s');
        $author = $this->config['reply_author_name'] ?? 'AI Assistant';
        $gid = intval($comment['gid']);
        $pid = $cid;
        $db->query("INSERT INTO " . DB_PREFIX . "comment (gid, pid, author, content, mail, ip, date, hide) VALUES ({$gid}, {$pid}, '{$author}', '" . addslashes($reply_content) . "', '', '" . getIp() . "', '{$now}', 'n')");
        $reply_cid = $db->insert_id();
        $db->query("UPDATE " . DB_PREFIX . "blog SET comnum=comnum+1 WHERE gid={$gid}");
        return ['reply_cid' => $reply_cid, 'parent_cid' => $cid, 'aid' => $gid, 'article_title' => $article['title'], 'reply_author' => $author, 'reply_content' => $reply_content, 'message' => "Replied to comment #{$cid}"];
    }
}
