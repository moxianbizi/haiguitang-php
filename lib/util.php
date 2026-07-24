<?php
/** 通用工具：JSON 响应、密码哈希、当前用户、转义 */

function json_ok($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($msg, $code = 400, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(['error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function body_json() {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function current_user() {
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) return null;
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, username, email, is_admin, is_banned, banned_reason FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function require_login() {
    $u = current_user();
    if (!$u) json_error('请先登录', 401);
    if ((int)$u['is_banned'] === 1) json_error('账号已被封禁：' . ($u['banned_reason'] ?: '无'), 403);
    return $u;
}

function require_admin() {
    $u = require_login();
    if ((int)$u['is_admin'] !== 1) json_error('需要管理员权限', 403);
    return $u;
}

function log_admin_action(string $action, string $target = '', string $detail = '') {
    $u = current_user();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('INSERT INTO admin_logs (admin_id, admin_name, action, target, detail, ip) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $u ? (int)$u['id'] : null,
        $u ? $u['username'] : '',
        $action,
        $target,
        $detail,
        $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}

/** 密码哈希（使用 PHP 内置 password_hash） */
function hash_password(string $pw): string {
    return password_hash($pw, PASSWORD_DEFAULT);
}

function verify_password(string $pw, string $hash): bool {
    return password_verify($pw, $hash);
}

/** 转义 */
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** 生成随机字符串 */
function random_str(int $len = 32): string {
    return bin2hex(random_bytes((int)ceil($len / 2)));
}

/** 生成房间码（去除易混淆字符） */
function gen_room_code(int $len = 6): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $len; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    return $code;
}

/** 验证码生成 */
function gen_code(int $len = 6): string {
    $code = '';
    for ($i = 0; $i < $len; $i++) $code .= random_int(0, 9);
    return $code;
}

/** 生成带签名的验证码 token（避免在服务端存验证码） */
function sign_code(string $email, string $code): string {
    $payload = base64_encode($email . '|' . $code . '|' . (time() + Config::$CODE_TTL));
    $sig = hash_hmac('sha256', $payload, Config::$SECRET_KEY);
    return $payload . '.' . $sig;
}

function verify_signed_code(string $email, string $token, string $code): bool {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return false;
    [$payload, $sig] = $parts;
    $expected = hash_hmac('sha256', $payload, Config::$SECRET_KEY);
    if (!hash_equals($expected, $sig)) return false;
    $data = base64_decode($payload);
    if ($data === false) return false;
    $arr = explode('|', $data);
    if (count($arr) !== 3) return false;
    [$e, $c, $expire] = $arr;
    if (time() > (int)$expire) return false;
    return hash_equals(strtolower($e), strtolower($email)) && hash_equals($c, $code);
}

/** 安全化文件名：去路径/控制字符、限制长度 */
function sanitize_filename(string $s): string {
    $s = preg_replace('/[\\/:*?"<>|]+/', '_', $s);
    $s = preg_replace('/[\x00-\x1f\x7f]+/', '', $s);
    $s = str_replace('..', '_', $s);
    $s = trim($s, ' ._-');
    if ($s === '') $s = 'untitled';
    return mb_substr($s, 0, 120);
}

/** CSRF Token 生成（session 启动后应立即调用一次以初始化） */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF Token 校验（非 GET/HEAD/OPTIONS 请求自动校验）
 * @param array $exempt 需要豁免的路径前缀（如登录前接口 ['auth/login','auth/send-code','auth/register']）
 */
function csrf_check(array $exempt = []): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) return;

    // 当前 API 路径（相对于 /api/）
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $path = ltrim(substr($uri, strpos($uri, '/api/') + 5), '/');
    foreach ($exempt as $p) {
        if ($path === $p || str_starts_with($path, rtrim($p, '/'))) return;
    }

    $expected = $_SESSION['csrf_token'] ?? '';
    // 已有 session 但未生成 token：生成一个（确保登录用户一定有 token）
    if ($expected === '') {
        $expected = csrf_token();
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if ($token === '' || !hash_equals($expected, $token)) {
        json_error('CSRF 校验失败，请刷新页面重试', 403);
    }
}

/** 输入长度校验 */
function validate_length(string $value, int $max, string $field = '输入'): void {
    if (mb_strlen($value) > $max) {
        json_error("{$field}不能超过 {$max} 个字符");
    }
}
