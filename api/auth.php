<?php
/** 认证 API：send-code / register / login / logout / me */
function handle_auth(array $segments) {
    $action = $segments[1] ?? '';
    switch ($action) {
        case 'send-code': auth_send_code(); break;
        case 'register':  auth_register(); break;
        case 'login':     auth_login(); break;
        case 'logout':    auth_logout(); break;
        case 'me':        auth_me(); break;
        default: json_error('Not Found', 404);
    }
}

function auth_send_code() {
    $data = body_json();
    $email = strtolower(trim($data['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('邮箱格式不正确');
    }

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_error('该邮箱已注册', 409);
    }

    [$ok, $msg, $token] = send_verification_code($email);
    json_ok(['msg' => $msg, 'token' => $token]);
}

function auth_register() {
    $data = body_json();
    $username = trim($data['username'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $code = trim($data['code'] ?? '');
    $token = $data['token'] ?? '';

    if (mb_strlen($username) < 2) json_error('用户名至少 2 个字符');
    if (strlen($password) < 6) json_error('密码至少 6 个字符');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('邮箱格式不正确');
    if ($code === '' || $token === '') json_error('请输入验证码');

    if (!verify_signed_code($email, $token, $code)) {
        json_error('验证码错误或已过期');
    }

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) json_error('用户名或邮箱已存在', 409);

    $hash = hash_password($password);
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
    $stmt->execute([$username, $email, $hash]);
    $uid = (int)$pdo->lastInsertId();

    $_SESSION['user_id'] = $uid;
    json_ok(['user' => ['id' => $uid, 'username' => $username, 'email' => $email]]);
}

function auth_login() {
    $data = body_json();
    $account = trim($data['account'] ?? '');
    $password = (string)($data['password'] ?? '');
    if ($account === '' || $password === '') json_error('账号或密码不能为空');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$account, strtolower($account)]);
    $u = $stmt->fetch();

    if (!$u || !verify_password($password, $u['password_hash'])) {
        json_error('账号或密码错误', 401);
    }
    $_SESSION['user_id'] = (int)$u['id'];
    json_ok(['user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email']]]);
}

function auth_logout() {
    $_SESSION = [];
    session_destroy();
    json_ok(['msg' => '已退出']);
}

function auth_me() {
    $u = current_user();
    if (!$u) {
        http_response_code(401);
        echo json_encode(['user' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }
    json_ok(['user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email']]]);
}
