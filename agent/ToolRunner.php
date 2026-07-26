<?php
/**
 * AI Agent - 工具执行器（Lite 精简版）
 * 仅包含 7 个只读工具
 */

defined('EMLOG_ROOT') || exit('access denied!');

class AIAgent_ToolRunner
{
    private $config;
    private $uid;
    private $role;

    public function __construct($config, $uid = 0, $role = 'guest')
    {
        $this->config = $config;
        $this->uid = $uid;
        $this->role = $role;
    }

    public function run($tool_name, $params = [])
    {
        $method = 'tool_' . $tool_name;
        if (!method_exists($this, $method)) {
            return ['success' => false, 'data' => null, 'error' => "未知工具：{$tool_name}（Lite 版仅支持只读工具）"];
        }
        try {
            $result = $this->$method($params);
            return ['success' => true, 'data' => $result, 'error' => ''];
        } catch (Exception $e) {
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    private function tool_get_categories($params)
    {
        $db = MySql::getInstance();
        $result = [];
        $query = $db->query("SELECT sid, sortname, description FROM " . DB_PREFIX . "sort ORDER BY sid ASC");
        while ($row = $db->fetch_array($query)) {
            $result[] = ['id' => intval($row['sid']), 'name' => $row['sortname'], 'description' => $row['description']];
        }
        return $result;
    }

    private function tool_get_tags($params)
    {
        $keyword = isset($params['keyword']) ? addslashes(trim($params['keyword'])) : '';
        $limit = min(200, max(10, intval($this->config['tag_query_limit'] ?? 50)));
        $db = MySql::getInstance();
        $result = [];
        $where = '';
        if (!empty($keyword)) {
            $kw = $this->escapeLike($keyword);
            $where = " WHERE tagname LIKE '%{$kw}%'";
        }
        $query = $db->query("SELECT tid, tagname, usenum FROM " . DB_PREFIX . "tag{$where} ORDER BY usenum DESC LIMIT {$limit}");
        while ($row = $db->fetch_array($query)) {
            $result[] = ['id' => intval($row['tid']), 'name' => $row['tagname'], 'count' => intval($row['usenum'])];
        }
        return $result;
    }

    private function tool_search_articles($params)
    {
        $keyword = isset($params['keyword']) ? trim($params['keyword']) : '';
        if (empty($keyword)) throw new Exception('搜索关键词不能为空');
        $keyword = addslashes($keyword);
        $keyword = $this->escapeLike($keyword);
        $page = max(1, intval($params['page'] ?? 1));
        $limit = min(50, max(1, intval($params['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        $db = MySql::getInstance();
        $blogUrl = rtrim(Option::get('blogurl'), '/');
        $result = [];
        $count = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "blog WHERE hide='n' AND (title LIKE '%{$keyword}%' OR content LIKE '%{$keyword}%')");
        $query = $db->query("SELECT gid, title, sortid, views, created FROM " . DB_PREFIX . "blog WHERE hide='n' AND (title LIKE '%{$keyword}%' OR content LIKE '%{$keyword}%') ORDER BY gid DESC LIMIT {$offset}, {$limit}");
        while ($row = $db->fetch_array($query)) {
            $result[] = ['id' => intval($row['gid']), 'title' => $row['title'], 'sortid' => intval($row['sortid']), 'views' => intval($row['views']), 'created' => $row['created'], 'url' => $blogUrl . '/?post=' . $row['gid']];
        }
        return ['total' => intval($count['cnt']), 'page' => $page, 'limit' => $limit, 'articles' => $result];
    }

    private function tool_get_articles($params)
    {
        $sortid = isset($params['sortid']) ? intval($params['sortid']) : 0;
        $order = in_array($params['order'] ?? '', ['old', 'hot']) ? $params['order'] : 'new';
        $limit = min(50, max(1, intval($params['limit'] ?? 10)));
        $page = max(1, intval($params['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $orderBy = $order === 'hot' ? 'views DESC' : ($order === 'old' ? 'gid ASC' : 'gid DESC');
        $where = "hide='n'" . ($sortid > 0 ? " AND sortid={$sortid}" : '');
        $db = MySql::getInstance();
        $blogUrl = rtrim(Option::get('blogurl'), '/');
        $result = [];
        $count = $db->once_fetch_array("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "blog WHERE {$where}");
        $query = $db->query("SELECT gid, title, sortid, views, created FROM " . DB_PREFIX . "blog WHERE {$where} ORDER BY {$orderBy} LIMIT {$offset}, {$limit}");
        while ($row = $db->fetch_array($query)) {
            $result[] = ['id' => intval($row['gid']), 'title' => $row['title'], 'sortid' => intval($row['sortid']), 'views' => intval($row['views']), 'created' => $row['created'], 'url' => $blogUrl . '/?post=' . $row['gid']];
        }
        return ['total' => intval($count['cnt']), 'page' => $page, 'limit' => $limit, 'articles' => $result];
    }

    private function tool_get_article($params)
    {
        $aid = intval($params['aid'] ?? 0);
        if ($aid <= 0) throw new Exception('文章ID不能为空');
        $db = MySql::getInstance();
        $blogUrl = rtrim(Option::get('blogurl'), '/');
        $row = $db->once_fetch_array("SELECT gid, title, content, sortid, views, created, excerpt, cover FROM " . DB_PREFIX . "blog WHERE gid={$aid}");
        if (!$row) throw new Exception("文章 #{$aid} 不存在");
        $tags = [];
        $tq = $db->query("SELECT t.tagname FROM " . DB_PREFIX . "tag t INNER JOIN " . DB_PREFIX . "tag_blog tb ON t.tid=tb.tid WHERE tb.gid={$aid}");
        while ($tr = $db->fetch_array($tq)) $tags[] = $tr['tagname'];
        return [
            'id' => intval($row['gid']), 'title' => $row['title'], 'content' => $row['content'],
            'sortid' => intval($row['sortid']), 'views' => intval($row['views']),
            'created' => $row['created'], 'excerpt' => $row['excerpt'],
            'cover' => $row['cover'], 'tags' => $tags,
            'url' => $blogUrl . '/?post=' . $row['gid'],
        ];
    }

    private function tool_get_stats($params)
    {
        $db = MySql::getInstance();
        $a = $db->once_fetch_array("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "blog WHERE hide='n'");
        $d = $db->once_fetch_array("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "blog WHERE hide='y'");
        $s = $db->once_fetch_array("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "sort");
        $c = $db->once_fetch_array("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "comment");
        $t = $db->once_fetch_array("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "tag");
        $v = $db->once_fetch_array("SELECT SUM(views) AS c FROM " . DB_PREFIX . "blog WHERE hide='n'");
        return ['articles' => intval($a['c']), 'drafts' => intval($d['c']), 'categories' => intval($s['c']), 'comments' => intval($c['c']), 'tags' => intval($t['c']), 'total_views' => intval($v['c'] ?: 0)];
    }

    private function tool_get_comments($params)
    {
        $limit = min(50, max(1, intval($params['limit'] ?? 10)));
        $status = $params['status'] ?? 'all';
        $db = MySql::getInstance();
        $where = '';
        if ($status === 'pending') $where = " WHERE c.hide='y'";
        elseif ($status === 'approved') $where = " WHERE c.hide='n'";
        $result = [];
        $query = $db->query("SELECT c.cid, c.gid, c.author, c.content, c.mail, c.date, c.hide, b.title FROM " . DB_PREFIX . "comment c LEFT JOIN " . DB_PREFIX . "blog b ON c.gid=b.gid{$where} ORDER BY c.cid DESC LIMIT {$limit}");
        while ($row = $db->fetch_array($query)) {
            $result[] = ['id' => intval($row['cid']), 'article_id' => intval($row['gid']), 'article_title' => $row['title'] ?? '', 'author' => $row['author'], 'content' => mb_substr($row['content'], 0, 200), 'email' => $row['mail'], 'date' => $row['date'], 'status' => $row['hide'] === 'n' ? 'approved' : 'pending'];
        }
        return $result;
    }

    private function escapeLike($str)
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $str);
    }
}
