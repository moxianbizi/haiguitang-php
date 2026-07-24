<?php
/** 海龟汤 CRUD API */

function handle_soups(array $segments) {
    $action = $segments[1] ?? '';
    if ($action === '') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') soups_list();
        elseif ($_SERVER['REQUEST_METHOD'] === 'POST') soups_create();
        else json_error('Method Not Allowed', 405);
        return;
    }
    if ($action === 'seasons') { soups_seasons(); return; }

    $id = (int)$action;
    if ($id <= 0) json_error('Not Found', 404);
    $sub = $segments[2] ?? '';

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && $sub === '') soups_detail($id);
    elseif (($method === 'GET' || $method === 'HEAD') && $sub === 'download') soups_download($id);
    elseif ($method === 'PUT' && $sub === '') soups_update($id);
    elseif ($method === 'DELETE' && $sub === '') soups_delete($id);
    else json_error('Not Found', 404);
}

function soups_list() {
    $pdo = DB::pdo();
    $q = trim($_GET['q'] ?? '');
    $season = trim($_GET['season'] ?? '');

    $sql = 'SELECT id, filename, season, episode, title, surface, substr(surface, 1, 80) AS excerpt FROM soups WHERE 1=1';
    $params = [];
    if ($season !== '') { $sql .= ' AND season = ?'; $params[] = $season; }
    if ($q !== '') {
        $sql .= ' AND (title LIKE ? OR surface LIKE ? OR season LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    $sql .= ' ORDER BY sort_order, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $soups = $stmt->fetchAll();

    $seasons = $pdo->query('SELECT DISTINCT season FROM soups ORDER BY season')->fetchAll(PDO::FETCH_COLUMN);

    json_ok(['count' => count($soups), 'seasons' => $seasons, 'soups' => $soups]);
}

function soups_seasons() {
    $pdo = DB::pdo();
    $seasons = $pdo->query('SELECT DISTINCT season FROM soups ORDER BY season')->fetchAll(PDO::FETCH_COLUMN);
    json_ok(['seasons' => $seasons]);
}

function soups_detail(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, filename, season, episode, title, surface, base FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);
    json_ok($s);
}

function soups_download(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);

    // 文件名用 RFC 5987 编码，兼容中文
    $safeName = str_replace(["\r", "\n", '"', '\\'], '', $s['filename']);
    $encodedName = rawurlencode($safeName);

    header('Content-Type: text/markdown; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$encodedName}\"; filename*=UTF-8''{$encodedName}");

    // UTF-8 BOM，确保 Windows 记事本正确识别编码
    $bom = "\xEF\xBB\xBF";

    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

    $soupsDir = realpath(Config::$SOUPS_DIR);
    $file = Config::$SOUPS_DIR . '/' . $s['filename'];
    $realFile = realpath($file);
    if ($realFile !== false && $soupsDir !== false && str_starts_with($realFile, $soupsDir) && is_file($realFile)) {
        echo $bom;
        readfile($realFile);
    } else {
        // 动态生成
        $md = "# {$s['title']}\n\n";
        if ($s['season']) $md .= "**季：**{$s['season']}\n\n";
        if ($s['episode']) $md .= "**集：**{$s['episode']}\n\n";
        $md .= "## 汤面\n\n{$s['surface']}\n\n## 汤底\n\n{$s['base']}\n";
        echo $bom . $md;
    }
    exit;
}

function soups_create() {
    $user = require_login();
    if (!Config::$ALLOW_SUBMIT) json_error('暂未开放投稿');

    $data = body_json();
    $title = trim($data['title'] ?? '');
    $surface = trim($data['surface'] ?? '');
    $base = trim($data['base'] ?? '');
    $season = trim($data['season'] ?? '');
    $episode = trim($data['episode'] ?? '');
    if ($title === '' || $surface === '' || $base === '') json_error('标题、汤面、汤底不能为空');

    $filename = trim($data['filename'] ?? '');
    if ($filename === '') {
        $baseName = $season ? "{$season}{$episode}_{$title}" : $title;
    } else {
        $baseName = preg_replace('/\.md$/i', '', $filename);
    }
    $baseName = sanitize_filename($baseName);
    $filename = $baseName . '.md';

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id FROM soups WHERE filename = ?');
    $stmt->execute([$filename]);
    if ($stmt->fetch()) json_error('文件名已存在', 409);

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM soups');
    $stmt->execute();
    $order = (int)$stmt->fetchColumn() + 1;

    $stmt = $pdo->prepare('INSERT INTO soups (filename, season, episode, title, surface, base, author_id, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$filename, $season, $episode, $title, $surface, $base, $user['id'], $order]);
    $id = (int)$pdo->lastInsertId();

    // 写 MD
    @mkdir(Config::$SOUPS_DIR, 0755, true);
    $md = "# {$title}\n\n";
    if ($season) $md .= "**季：**{$season}\n\n";
    if ($episode) $md .= "**集：**{$episode}\n\n";
    $md .= "## 汤面\n\n{$surface}\n\n## 汤底\n\n{$base}\n";
    @file_put_contents(Config::$SOUPS_DIR . '/' . $filename, $md);

    $stmt = $pdo->prepare('SELECT id, filename, season, episode, title, surface, base FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    json_ok($stmt->fetch(), 201);
}

function soups_update(int $id) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);

    $data = body_json();
    foreach (['title', 'surface', 'base', 'season', 'episode'] as $f) {
        if (array_key_exists($f, $data)) $s[$f] = trim((string)$data[$f]);
    }
    if ($s['title'] === '') json_error('标题不能为空');
    validate_length($s['surface'], 50000, '汤面');
    validate_length($s['base'], 50000, '汤底');

    $stmt = $pdo->prepare('UPDATE soups SET title=?, surface=?, base=?, season=?, episode=? WHERE id=?');
    $stmt->execute([$s['title'], $s['surface'], $s['base'], $s['season'], $s['episode'], $id]);

    // 同步 MD
    $md = "# {$s['title']}\n\n";
    if ($s['season']) $md .= "**季：**{$s['season']}\n\n";
    if ($s['episode']) $md .= "**集：**{$s['episode']}\n\n";
    $md .= "## 汤面\n\n{$s['surface']}\n\n## 汤底\n\n{$s['base']}\n";
    $soupsDir = realpath(Config::$SOUPS_DIR);
    $filePath = Config::$SOUPS_DIR . '/' . $s['filename'];
    if ($soupsDir !== false && str_starts_with(realpath(dirname($filePath) ?: $filePath) ?: '', $soupsDir)) {
        @file_put_contents($filePath, $md);
    }

    $stmt = $pdo->prepare('SELECT id, filename, season, episode, title, surface, base FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    json_ok($stmt->fetch());
}

function soups_delete(int $id) {
    $user = require_login();
    if (!Config::$ALLOW_SUBMIT) json_error('暂未开放删除');
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT filename FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);

    $file = Config::$SOUPS_DIR . '/' . $s['filename'];
    if (is_file($file)) @unlink($file);

    $stmt = $pdo->prepare('DELETE FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    json_ok(['msg' => '已删除']);
}
