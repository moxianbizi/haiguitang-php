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
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function require_login() {
    $u = current_user();
    if (!$u) json_error('请先登录', 401);
    return $u;
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
    $arr = explode('|', $data);
    if (count($arr) !== 3) return false;
    [$e, $c, $expire] = $arr;
    if (time() > (int)$expire) return false;
    return hash_equals(strtolower($e), strtolower($email)) && hash_equals($c, $code);
}
