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
    json_error('注册暂未开放，如需账号请前往交流群寻找管理员');
}

function auth_register() {
    json_error('注册暂未开放，如需账号请前往交流群寻找管理员');
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
