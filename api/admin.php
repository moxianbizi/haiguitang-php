<?php
/** 管理员后台 API */

function handle_admin(array $segments) {
    $admin = require_admin();
    $action = $segments[1] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($action === 'stats' && $method === 'GET') admin_stats();
    elseif ($action === 'users' && $method === 'GET') admin_users_list();
    elseif ($action === 'users' && $method === 'POST') admin_users_create();
    elseif ($action === 'soups' && $method === 'GET') admin_soups_list();
    elseif ($action === 'soups' && $method === 'POST') admin_soups_create();
    elseif ($action === 'soups' && $segments[2] === 'import' && $method === 'POST') admin_soups_import();
    elseif ($action === 'soups' && $segments[2] === 'reimport' && $method === 'POST') admin_soups_reimport();
    elseif ($action === 'soups' && isset($segments[2]) && ctype_digit($segments[2]) && $method === 'PUT') admin_soups_update((int)$segments[2]);
    elseif ($action === 'soups' && isset($segments[2]) && ctype_digit($segments[2]) && $method === 'DELETE') admin_soups_delete((int)$segments[2]);
    elseif ($action === 'rooms' && $method === 'GET') admin_rooms_list();
    elseif ($action === 'rooms' && isset($segments[2]) && ctype_digit($segments[2]) && $method === 'DELETE') admin_rooms_delete((int)$segments[2]);
    elseif ($action === 'rooms' && isset($segments[2]) && ctype_digit($segments[2]) && $segments[3] === 'status' && $method === 'PUT') admin_rooms_set_status((int)$segments[2]);
    elseif ($action === 'rooms' && isset($segments[2]) && ctype_digit($segments[2]) && $segments[3] === 'messages' && $method === 'GET') admin_room_messages((int)$segments[2]);
    elseif ($action === 'messages' && isset($segments[2]) && ctype_digit($segments[2]) && $method === 'DELETE') admin_messages_delete((int)$segments[2]);
    elseif ($action === 'users' && isset($segments[2]) && ctype_digit($segments[2]) && $method === 'PUT') admin_users_update((int)$segments[2]);
    elseif ($action === 'users' && isset($segments[2]) && ctype_digit($segments[2]) && $segments[3] === 'password' && $method === 'PUT') admin_users_reset_password((int)$segments[2]);
    elseif ($action === 'users' && isset($segments[2]) && ctype_digit($segments[2]) && $method === 'DELETE') admin_users_delete((int)$segments[2]);
    elseif ($action === 'settings' && $method === 'GET') admin_settings_get();
    elseif ($action === 'settings' && $method === 'PUT') admin_settings_update();
    elseif ($action === 'logs' && $method === 'GET') admin_logs();
    elseif ($action === 'backup' && $method === 'GET') admin_backup();
    elseif ($action === 'system' && $method === 'GET') admin_system();
    else json_error('Not Found', 404);
}

// ===================== 仪表盘统计 =====================
function admin_stats() {
    $pdo = DB::pdo();
    $users_total = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $users_admin = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    $users_banned = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_banned = 1')->fetchColumn();
    $soups_total = (int)$pdo->query('SELECT COUNT(*) FROM soups')->fetchColumn();
    $rooms_total = (int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
    $rooms_playing = (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'playing'")->fetchColumn();
    $rooms_ended = (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'ended'")->fetchColumn();
    $messages_total = (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    $today = date('Y-m-d');
    $new_users_today = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= '{$today}'")->fetchColumn();
    $new_rooms_today = (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE created_at >= '{$today}'")->fetchColumn();
    $messages_today = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE created_at >= '{$today}'")->fetchColumn();

    // 最近 7 天趋势
    $trend = $pdo->query("SELECT date(created_at) AS d, COUNT(*) AS c FROM users GROUP BY date(created_at) ORDER BY d DESC LIMIT 7")->fetchAll();

    // 最新用户
    $recent_users = $pdo->query('SELECT id, username, email, is_admin, is_banned, created_at FROM users ORDER BY id DESC LIMIT 10')->fetchAll();

    // 最新房间
    $recent_rooms = $pdo->query("SELECT r.id, r.code, r.status, r.created_at, u.username AS host_name FROM rooms r LEFT JOIN users u ON r.host_id = u.id ORDER BY r.id DESC LIMIT 10")->fetchAll();

    json_ok([
        'users_total' => $users_total,
        'users_admin' => $users_admin,
        'users_banned' => $users_banned,
        'soups_total' => $soups_total,
        'rooms_total' => $rooms_total,
        'rooms_playing' => $rooms_playing,
        'rooms_ended' => $rooms_ended,
        'messages_total' => $messages_total,
        'new_users_today' => $new_users_today,
        'new_rooms_today' => $new_rooms_today,
        'messages_today' => $messages_today,
        'trend' => array_reverse($trend),
        'recent_users' => $recent_users,
        'recent_rooms' => $recent_rooms,
        'db_size' => filesize(Config::$DB_PATH),
        'php_version' => PHP_VERSION,
    ]);
}

// ===================== 用户管理 =====================
function admin_users_list() {
    $pdo = DB::pdo();
    $q = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));

    $sql = 'FROM users WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (username LIKE ? OR email LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) $sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // prepare needed because of LIKE params
    $stmt = $pdo->prepare("SELECT id, username, email, is_admin, is_banned, banned_reason, created_at $sql ORDER BY id DESC LIMIT :offset, :limit");
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    foreach ($params as $i => $p) {
        $stmt->bindValue($i + 3, $p);
    }
    $stmt->execute();
    $users = $stmt->fetchAll();

    json_ok([
        'users' => $users,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function admin_users_create() {
    $data = body_json();
    $username = trim($data['username'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $is_admin = !empty($data['is_admin']) ? 1 : 0;
    if ($username === '' || $email === '' || strlen($password) < 8) json_error('用户名、邮箱不能为空，密码至少 8 位');
    // 用户名字符白名单：中英文/数字/下划线，2-32 位，防止 XSS/注入
    if (!preg_match('/^[\w\x{4e00}-\x{9fa5}]{2,32}$/u', $username)) {
        json_error('用户名只能含中英文/数字/下划线，2-32 位');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('邮箱格式不正确');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) json_error('用户名或邮箱已存在', 409);

    $hash = hash_password($password);
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)');
    $stmt->execute([$username, $email, $hash, $is_admin]);
    $id = (int)$pdo->lastInsertId();
    log_admin_action('user_create', "user #$id", "$username / $email");
    json_ok(['id' => $id, 'msg' => '用户创建成功'], 201);
}

function admin_users_update(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) json_error('用户不存在', 404);

    $data = body_json();
    $changes = [];

    if (array_key_exists('is_admin', $data)) {
        $newAdmin = !empty($data['is_admin']) ? 1 : 0;
        if ((int)$u['id'] === $id && $newAdmin === 0) {
            $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
            if ($adminCount <= 1) json_error('不能取消最后一个管理员的权限');
        }
        $stmt = $pdo->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
        $stmt->execute([$newAdmin, $id]);
        $changes[] = "is_admin=$newAdmin";
    }

    if (array_key_exists('is_banned', $data)) {
        $banned = !empty($data['is_banned']) ? 1 : 0;
        $reason = trim($data['banned_reason'] ?? '');
        $stmt = $pdo->prepare('UPDATE users SET is_banned = ?, banned_reason = ? WHERE id = ?');
        $stmt->execute([$banned, $reason, $id]);
        $changes[] = $banned ? "banned($reason)" : 'unbanned';
    }

    if (array_key_exists('username', $data)) {
        $newName = trim($data['username']);
        if ($newName !== '' && $newName !== $u['username']) {
            if (!preg_match('/^[\w\x{4e00}-\x{9fa5}]{2,32}$/u', $newName)) {
                json_error('用户名只能含中英文/数字/下划线，2-32 位');
            }
            $stmt = $pdo->prepare('UPDATE users SET username = ? WHERE id = ?');
            $stmt->execute([$newName, $id]);
            $changes[] = "username: {$u['username']} -> $newName";
        }
    }

    log_admin_action('user_update', "user #$id", implode(', ', $changes));
    json_ok(['msg' => '已更新', 'changes' => $changes]);
}

function admin_users_reset_password(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) json_error('用户不存在', 404);

    $data = body_json();
    $password = (string)($data['password'] ?? '');
    if (strlen($password) < 8) json_error('密码至少 8 位');

    $hash = hash_password($password);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$hash, $id]);
    log_admin_action('user_reset_password', "user #$id", $u['username']);
    json_ok(['msg' => '密码已重置']);
}

function admin_users_delete(int $id) {
    $admin = current_user();
    if ((int)$admin['id'] === $id) json_error('不能删除自己');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) json_error('用户不存在', 404);

    // 检查是否是最后一个管理员
    $isLastAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn() <= 1;
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $targetIsAdmin = (int)$stmt->fetchColumn();
    if ($isLastAdmin && $targetIsAdmin) json_error('不能删除最后一个管理员');

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    log_admin_action('user_delete', "user #$id", $u['username']);
    json_ok(['msg' => '已删除']);
}

// ===================== 汤管理 =====================
function admin_soups_list() {
    $pdo = DB::pdo();
    $q = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));

    $sql = 'FROM soups WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (title LIKE ? OR season LIKE ? OR filename LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) $sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * $sql ORDER BY sort_order, id DESC LIMIT :offset, :limit");
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    foreach ($params as $i => $p) {
        $stmt->bindValue($i + 3, $p);
    }
    $stmt->execute();
    $soups = $stmt->fetchAll();

    json_ok([
        'soups' => $soups,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function admin_soups_create() {
    $data = body_json();
    $title = trim($data['title'] ?? '');
    $surface = trim($data['surface'] ?? '');
    $base = trim($data['base'] ?? '');
    $hostManual = trim($data['host_manual'] ?? '');
    $extra = trim($data['extra'] ?? '');
    $season = trim($data['season'] ?? '');
    $episode = trim($data['episode'] ?? '');
    $filename = trim($data['filename'] ?? '');
    if ($title === '' || $surface === '' || $base === '') json_error('标题、汤面、汤底不能为空');

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

    $admin = current_user();
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM soups');
    $stmt->execute();
    $order = (int)$stmt->fetchColumn() + 1;

    $stmt = $pdo->prepare('INSERT INTO soups (filename, season, episode, title, surface, base, host_manual, extra, author_id, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$filename, $season, $episode, $title, $surface, $base, $hostManual, $extra, $admin['id'], $order]);
    $id = (int)$pdo->lastInsertId();

    @mkdir(Config::$SOUPS_DIR, 0755, true);
    $s = compact('title', 'season', 'episode', 'surface', 'base') + ['host_manual' => $hostManual, 'extra' => $extra];
    @file_put_contents(Config::$SOUPS_DIR . '/' . $filename, soups_build_md($s));

    log_admin_action('soup_create', "soup #$id", $title);
    json_ok(['id' => $id, 'msg' => '汤创建成功'], 201);
}

function admin_soups_update(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);

    $data = body_json();
    foreach (['title', 'surface', 'base', 'host_manual', 'extra', 'season', 'episode'] as $f) {
        if (array_key_exists($f, $data)) $s[$f] = trim((string)$data[$f]);
    }
    if ($s['title'] === '') json_error('标题不能为空');

    $stmt = $pdo->prepare('UPDATE soups SET title=?, surface=?, base=?, host_manual=?, extra=?, season=?, episode=? WHERE id=?');
    $stmt->execute([$s['title'], $s['surface'], $s['base'], $s['host_manual'], $s['extra'], $s['season'], $s['episode'], $id]);

    $soupsDir = realpath(Config::$SOUPS_DIR);
    $filePath = Config::$SOUPS_DIR . '/' . $s['filename'];
    if ($soupsDir !== false && str_starts_with(realpath(dirname($filePath) ?: $filePath) ?: '', $soupsDir)) {
        @file_put_contents($filePath, soups_build_md($s));
    }

    log_admin_action('soup_update', "soup #$id", $s['title']);
    json_ok(['msg' => '已更新']);
}

function admin_soups_delete(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT filename, title FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_error('未找到', 404);

    $soupsDir = realpath(Config::$SOUPS_DIR);
    $filePath = Config::$SOUPS_DIR . '/' . $s['filename'];
    if (is_file($filePath) && $soupsDir !== false && str_starts_with(realpath($filePath) ?: '', $soupsDir)) {
        @unlink($filePath);
    }

    $stmt = $pdo->prepare('DELETE FROM soups WHERE id = ?');
    $stmt->execute([$id]);
    log_admin_action('soup_delete', "soup #$id", $s['title']);
    json_ok(['msg' => '已删除']);
}

function admin_soups_import() {
    $dir = Config::$SOUPS_DIR;
    if (!is_dir($dir)) json_error('汤源目录不存在');

    require_once __DIR__ . '/../lib/md.php';
    $files = array_filter(scandir($dir), fn($f) => str_ends_with($f, '.md'));
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    $pdo = DB::pdo();
    $imported = 0;
    $skipped = 0;
    foreach ($files as $f) {
        $stmt = $pdo->prepare('SELECT id FROM soups WHERE filename = ?');
        $stmt->execute([$f]);
        if ($stmt->fetch()) { $skipped++; continue; }

        $content = file_get_contents($dir . '/' . $f);
        $p = parse_md($f, $content);
        $stmt = $pdo->prepare('INSERT INTO soups (filename, season, episode, title, surface, base, host_manual, extra, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$p['filename'], $p['season'], $p['episode'], $p['title'], $p['surface'], $p['base'], $p['host_manual'], $p['extra'], 0]);
        $imported++;
    }
    log_admin_action('soup_import', '', "imported=$imported, skipped=$skipped");
    json_ok(['msg' => "导入 $imported 碗，跳过 $skipped 碗（已存在）", 'imported' => $imported, 'skipped' => $skipped]);
}

/** 重新解析所有 MD 文件，刷新已有汤的字段（用于 parse_md 升级后） */
function admin_soups_reimport() {
    $result = DB::reimport_all();
    log_admin_action('soup_reimport', '', json_encode($result, JSON_UNESCAPED_UNICODE));
    $msg = "已重新解析 {$result['updated']} 碗";
    if (!empty($result['skipped'])) $msg .= "，跳过 {$result['skipped']} 碗（文件不存在）";
    if (!empty($result['error'])) $msg .= "，错误：{$result['error']}";
    json_ok(['msg' => $msg] + $result);
}

// ===================== 房间管理 =====================
function admin_rooms_list() {
    $pdo = DB::pdo();
    $q = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));

    $sql = "FROM rooms r LEFT JOIN users u ON r.host_id = u.id LEFT JOIN soups s ON r.soup_id = s.id WHERE 1=1";
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (r.code LIKE ? OR u.username LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($status !== '') {
        $sql .= ' AND r.status = ?';
        $params[] = $status;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) $sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT r.id, r.code, r.host_id, r.soup_id, r.status, r.ai_enabled, r.created_at, u.username AS host_name, s.title AS soup_title $sql ORDER BY r.id DESC LIMIT :offset, :limit");
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    foreach ($params as $i => $p) {
        $stmt->bindValue($i + 3, $p);
    }
    $stmt->execute();
    $rooms = $stmt->fetchAll();

    json_ok([
        'rooms' => $rooms,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function admin_rooms_delete(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT code FROM rooms WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);

    $stmt = $pdo->prepare('DELETE FROM messages WHERE room_id = ?');
    $stmt->execute([$id]);
    $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
    $stmt->execute([$id]);
    log_admin_action('room_delete', "room #$id", $r['code']);
    json_ok(['msg' => '已删除']);
}

function admin_rooms_set_status(int $id) {
    $data = body_json();
    $status = trim($data['status'] ?? '');
    if (!in_array($status, ['playing', 'ended'])) json_error('状态只能是 playing 或 ended');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('UPDATE rooms SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
    log_admin_action('room_status', "room #$id", $status);
    json_ok(['msg' => '已更新']);
}

function admin_room_messages(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, user_id, username, msg_type, content, created_at FROM messages WHERE room_id = ? ORDER BY id DESC LIMIT 200');
    $stmt->execute([$id]);
    $msgs = $stmt->fetchAll();
    json_ok(['messages' => array_reverse($msgs)]);
}

function admin_messages_delete(int $id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT content FROM messages WHERE id = ?');
    $stmt->execute([$id]);
    $m = $stmt->fetch();
    if (!$m) json_error('消息不存在', 404);

    $stmt = $pdo->prepare('DELETE FROM messages WHERE id = ?');
    $stmt->execute([$id]);
    log_admin_action('message_delete', "msg #$id", mb_substr($m['content'], 0, 50));
    json_ok(['msg' => '已删除']);
}

// ===================== 系统设置 =====================
function admin_settings_get() {
    $pdo = DB::pdo();
    $rows = $pdo->query('SELECT * FROM settings')->fetchAll();
    $settings = [];
    foreach ($rows as $r) $settings[$r['key']] = $r['value'];

    json_ok([
        'settings' => $settings,
        'config' => [
            'ALLOW_SUBMIT' => Config::$ALLOW_SUBMIT,
            'DEEPSEEK_MODEL' => Config::$DEEPSEEK_MODEL,
            'ROOM_MSG_LIMIT' => Config::$ROOM_MSG_LIMIT,
            'POLL_INTERVAL' => Config::$POLL_INTERVAL,
            'CODE_TTL' => Config::$CODE_TTL,
        ],
    ]);
}

function admin_settings_update() {
    $data = body_json();
    $pdo = DB::pdo();
    $updated = [];
    foreach ($data as $k => $v) {
        if ($k === 'allow_submit') {
            Config::$ALLOW_SUBMIT = !empty($v);
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime(\'now\'))');
            $stmt->execute(['allow_submit', Config::$ALLOW_SUBMIT ? '1' : '0']);
            $updated[] = 'allow_submit';
        }
        if ($k === 'room_msg_limit') {
            $limit = (int)$v;
            // 0=不限；否则限定在 [10, 1000] 防止过大值导致 DoS
            if ($limit !== 0 && ($limit < 10 || $limit > 1000)) {
                json_error('room_msg_limit 只能为 0（不限）或 10-1000');
            }
            Config::$ROOM_MSG_LIMIT = $limit;
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime(\'now\'))');
            $stmt->execute(['room_msg_limit', (string)Config::$ROOM_MSG_LIMIT]);
            $updated[] = 'room_msg_limit';
        }
    }
    log_admin_action('settings_update', '', implode(', ', $updated));
    json_ok(['msg' => '已保存', 'updated' => $updated]);
}

// ===================== 操作日志 =====================
function admin_logs() {
    $pdo = DB::pdo();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 30)));
    $offset = ($page - 1) * $perPage;

    $total = (int)$pdo->query('SELECT COUNT(*) FROM admin_logs')->fetchColumn();
    $stmt = $pdo->prepare('SELECT * FROM admin_logs ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->execute([$perPage, $offset]);
    $logs = $stmt->fetchAll();

    json_ok([
        'logs' => $logs,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ===================== 数据备份 =====================
function admin_backup() {
    $dbPath = Config::$DB_PATH;
    if (!is_file($dbPath)) json_error('数据库文件不存在', 404);

    $pdo = DB::pdo();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    $filename = 'haiguitang_backup_' . date('Y-m-d_His') . '.db';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($dbPath));
    readfile($dbPath);
    log_admin_action('backup_download', '', $filename);
    exit;
}

// ===================== 系统信息 =====================
function admin_system() {
    $pdo = DB::pdo();

    // 表大小
    $tableSizes = [];
    foreach (['users', 'soups', 'rooms', 'messages', 'admin_logs'] as $t) {
        $tableSizes[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }

    // PHP 扩展
    $extensions = [
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'curl' => extension_loaded('curl'),
        'mbstring' => extension_loaded('mbstring'),
        'openssl' => extension_loaded('openssl'),
        'gd' => extension_loaded('gd'),
        'fileinfo' => extension_loaded('fileinfo'),
    ];

    $diskFree = disk_free_space(__DIR__);
    $diskTotal = disk_total_space(__DIR__);

    json_ok([
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'php_os' => PHP_OS,
        'db_size' => filesize(Config::$DB_PATH),
        'db_path' => Config::$DB_PATH,
        'soups_dir' => Config::$SOUPS_DIR,
        'soups_dir_exists' => is_dir(Config::$SOUPS_DIR),
        'table_sizes' => $tableSizes,
        'extensions' => $extensions,
        'disk_free' => $diskFree,
        'disk_total' => $diskTotal,
        'server_time' => date('c'),
        'timezone' => date_default_timezone_get(),
        'max_upload' => ini_get('upload_max_filesize'),
        'max_post' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
    ]);
}
