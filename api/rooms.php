<?php
/** 房间 API：创建/列表/详情/关闭/换汤/发送消息/AI提问 */

function handle_rooms(array $segments) {
    $action = $segments[1] ?? '';
    if ($action === '') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') rooms_list();
        elseif ($_SERVER['REQUEST_METHOD'] === 'POST') rooms_create();
        else json_error('Method Not Allowed', 405);
        return;
    }

    $code = strtoupper($action);
    $sub = $segments[2] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $sub === '') { require_login(); rooms_get($code); }
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $sub === '') rooms_close($code);
    elseif ($sub === 'dissolve' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_dissolve($code);
    elseif ($sub === 'select-soup' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_select_soup($code);
    elseif ($sub === 'messages' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_send_message($code);
    elseif ($sub === 'ai-question' && $_SERVER['REQUEST_METHOD'] === 'POST') rooms_ai_question($code);
    elseif ($sub === 'messages' && $_SERVER['REQUEST_METHOD'] === 'GET') { require_login(); rooms_poll_messages($code); }
    else json_error('Not Found', 404);
}

function rooms_create() {
    $user = require_login();
    if (!rate_limit("room_create_user_{$user['id']}", Config::$RATE_LIMIT_ROOM_CREATE, 60)) {
        json_error('创建房间过于频繁，请稍后再试', 429);
    }
    $data = body_json();
    $soup_id = $data['soup_id'] ?? null;
    $ai_enabled = $data['ai_enabled'] ?? true;
    $ai_question_limit = max(0, (int)($data['ai_question_limit'] ?? 0));
    $member_limit = max(0, (int)($data['member_limit'] ?? 0));
    if ($member_limit > 0 && $member_limit < 2) $member_limit = 2;

    $pdo = DB::pdo();
    $code = gen_room_code();
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM rooms WHERE code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) break;
        $code = gen_room_code();
    }

    $stmt = $pdo->prepare('INSERT INTO rooms (code, host_id, soup_id, ai_enabled, ai_question_limit, member_limit, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$code, $user['id'], $soup_id ? (int)$soup_id : null, $ai_enabled ? 1 : 0, $ai_question_limit, $member_limit, 'playing']);
    $id = (int)$pdo->lastInsertId();

    // 系统消息
    $stmt = $pdo->prepare('INSERT INTO messages (room_id, msg_type, content) VALUES (?, ?, ?)');
    $stmt->execute([$id, 'system', '房间已创建，开始游戏吧！']);

    rooms_get($code, 201);
}

function rooms_list() {
    require_login();
    $pdo = DB::pdo();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $pdo->prepare("SELECT r.id, r.code, r.host_id, r.soup_id, r.status, r.ai_enabled, r.ai_question_limit, r.ai_question_count, r.member_limit, r.created_at, u.username AS host_name, CASE WHEN f.follower_id IS NOT NULL THEN 0 ELSE 1 END AS sort_key FROM rooms r LEFT JOIN users u ON r.host_id = u.id LEFT JOIN follows f ON f.following_id = r.host_id AND f.follower_id = ? WHERE r.status = 'playing' ORDER BY sort_key, r.created_at DESC LIMIT 50");
    $stmt->execute([$userId]);
    $rooms = $stmt->fetchAll();
    json_ok(['rooms' => array_map('room_to_dict', $rooms)]);
}

function rooms_get(string $code, int $status = 200) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT r.*, u.username AS host_name FROM rooms r LEFT JOIN users u ON r.host_id = u.id WHERE r.code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);

    $room = room_to_dict($r);

    $soup = null;
    if ($r['soup_id']) {
        $stmt = $pdo->prepare('SELECT id, filename, season, episode, title, surface FROM soups WHERE id = ?');
        $stmt->execute([$r['soup_id']]);
        $soup = $stmt->fetch();
    }

    $limit = Config::$ROOM_MSG_LIMIT;
    if ($limit > 0) {
        $sql = 'SELECT id, user_id, username, msg_type, content, strftime("%H:%M:%S", created_at) AS created_at FROM messages WHERE room_id = ? ORDER BY id DESC LIMIT ?';
        $params = [$r['id'], $limit];
    } else {
        $sql = 'SELECT id, user_id, username, msg_type, content, strftime("%H:%M:%S", created_at) AS created_at FROM messages WHERE room_id = ? ORDER BY id ASC';
        $params = [$r['id']];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $msgs = $stmt->fetchAll();
    if ($limit > 0) $msgs = array_reverse($msgs);

    json_ok([
        'room' => $room,
        'soup' => $soup,
        'messages' => array_map('message_to_dict', $msgs),
    ], $status);
}

function rooms_close(string $code) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以关闭房间', 403);

    $stmt = $pdo->prepare("UPDATE rooms SET status = 'ended' WHERE code = ?");
    $stmt->execute([$code]);
    json_ok(['msg' => '已关闭']);
}

/**
 * 解散房间：房主永久删除房间及其所有消息（不可恢复）。
 * 与 rooms_close（仅标记 status='ended'，保留数据）的区别：
 *   - close: 软关闭，房间仍在列表/后台可见，可恢复
 *   - dissolve: 硬删除，房间和消息从数据库清除
 * messages 表 ON DELETE CASCADE 会自动清理消息。
 */
function rooms_dissolve(string $code) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以解散房间', 403);

    $pdo->beginTransaction();
    try {
        // messages 表有 ON DELETE CASCADE，删除 room 会自动删除其所有消息
        $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
        $stmt->execute([(int)$r['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('解散房间失败：' . $e->getMessage(), 500);
    }
    json_ok(['msg' => '房间已解散']);
}

function rooms_select_soup(string $code) {
    $user = require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以选汤', 403);

    $data = body_json();
    $soup_id = $data['soup_id'] ?? null;
    if ($soup_id) {
        $soup_id = (int)$soup_id;
        $stmt2 = $pdo->prepare("SELECT id FROM soups WHERE id = ? AND status = 'approved'");
        $stmt2->execute([$soup_id]);
        if (!$stmt2->fetch()) json_error('海龟汤不存在或未通过审核', 400);
    }
    $stmt = $pdo->prepare('UPDATE rooms SET soup_id = ? WHERE code = ?');
    $stmt->execute([$soup_id ?: null, $code]);

    // 系统消息
    $stmt = $pdo->prepare('INSERT INTO messages (room_id, msg_type, content) VALUES (?, ?, ?)');
    $stmt->execute([$r['id'], 'system', '房主选了一碗新汤，开始猜吧！']);

    rooms_get($code);
}

function rooms_send_message(string $code) {
    $user = require_login();
    $data = body_json();
    $content = trim($data['content'] ?? '');
    if ($content === '') json_error('内容不能为空');
    validate_length($content, 2000, '消息内容');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ($r['status'] !== 'playing') json_error('房间已结束');
    if (!is_room_member($r, $user)) {
        if ((int)$r['member_limit'] > 0) {
            $memberCount = count_room_members($r);
            if ($memberCount >= (int)$r['member_limit']) json_error('房间人数已达上限', 403);
        }
    }
    if (!rate_limit("msg_room_{$r['id']}_user_{$user['id']}", Config::$RATE_LIMIT_MSG_SEND, 60)) {
        json_error('发送消息过于频繁，请稍后再试', 429);
    }

    $msg = save_message($r['id'], $user, 'chat', $content);
    json_ok(['message' => message_to_dict($msg)]);
}

function rooms_ai_question(string $code) {
    $user = require_login();
    $data = body_json();
    $content = trim($data['content'] ?? '');
    $api_key = (string)($data['api_key'] ?? '');
    $provider = (string)($data['provider'] ?? 'deepseek');
    $ai_base_url = (string)($data['base_url'] ?? '');
    $ai_model = (string)($data['model'] ?? '');
    if ($content === '') json_error('问题不能为空');
    validate_length($content, 500, '问题内容');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);
    if ($r['status'] !== 'playing') json_error('房间已结束');
    if (!$r['soup_id']) json_error('房间里还没有选汤');
    if (!$r['ai_enabled']) json_error('AI 未启用');
    if (!is_room_member($r, $user)) json_error('您不是该房间成员', 403);
    if (!rate_limit("ai_ask_user_{$user['id']}", Config::$RATE_LIMIT_AI_ASK, 60)) {
        json_error('AI 提问过于频繁，请稍后再试', 429);
    }

    if ((int)$r['ai_question_limit'] > 0 && (int)$r['ai_question_count'] >= (int)$r['ai_question_limit']) {
        json_error('AI 提问次数已达上限（' . (int)$r['ai_question_limit'] . '次）');
    }

    // 保存问题
    $q_msg = save_message($r['id'], $user, 'ai_question', $content);

    // 取汤（含主持人手册/其他内容，仅查已审核汤避免越权）
    $stmt = $pdo->prepare("SELECT surface, base, host_manual, extra FROM soups WHERE id = ? AND status = 'approved'");
    $stmt->execute([$r['soup_id']]);
    $soup = $stmt->fetch();
    if (!$soup || empty($soup['base'])) {
        $a_msg = save_message($r['id'], null, 'ai_answer', '该汤没有汤底，无法提问');
        json_ok([
            'question' => message_to_dict($q_msg),
            'answer' => message_to_dict($a_msg),
            'error' => '该汤没有汤底，无法提问',
        ]);
    }

    try {
        $answer = ask_ai(
            $soup['surface'] ?: '',
            $soup['base'],
            $content,
            $api_key,
            $soup['host_manual'] ?? '',
            $soup['extra'] ?? '',
            $provider,
            $ai_base_url,
            $ai_model
        );
    } catch (AIError $e) {
        json_ok([
            'question' => message_to_dict($q_msg),
            'answer' => null,
            'error' => $e->getMessage(),
            'code' => $e->aiCode,
        ]);
    }
    // AI 调用成功后再递增提问计数（避免失败时白扣次数）
    $pdo->exec('UPDATE rooms SET ai_question_count = ai_question_count + 1 WHERE id = ' . (int)$r['id']);
    $a_msg = save_message($r['id'], null, 'ai_answer', $answer);
    json_ok([
        'question' => message_to_dict($q_msg),
        'answer' => message_to_dict($a_msg),
    ]);
}

function rooms_poll_messages(string $code) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在', 404);

    $since = (int)($_GET['since'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, user_id, username, msg_type, content, strftime("%H:%M:%S", created_at) AS created_at FROM messages WHERE room_id = ? AND id > ? ORDER BY id');
    $stmt->execute([$r['id'], $since]);
    $msgs = $stmt->fetchAll();
    json_ok(['messages' => array_map('message_to_dict', $msgs), 'last_id' => end($msgs) ? (int)end($msgs)['id'] : $since]);
}

// ===================== 辅助 =====================

function save_message(int $room_id, ?array $user, string $type, string $content): array {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('INSERT INTO messages (room_id, user_id, username, msg_type, content) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $room_id,
        $user ? (int)$user['id'] : null,
        $user ? $user['username'] : null,
        $type,
        $content,
    ]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT id, user_id, username, msg_type, content, strftime("%H:%M:%S", created_at) AS created_at FROM messages WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function count_room_members(array $room): int {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM messages WHERE room_id = ? AND user_id IS NOT NULL');
    $stmt->execute([$room['id']]);
    $chatters = (int)$stmt->fetchColumn();
    $hostId = (int)$room['host_id'];
    $stmt2 = $pdo->prepare('SELECT 1 FROM messages WHERE room_id = ? AND user_id = ? LIMIT 1');
    $stmt2->execute([$room['id'], $hostId]);
    if (!$stmt2->fetch()) $chatters++;
    return $chatters;
}

function room_to_dict(array $r): array {
    return [
        'id' => (int)$r['id'],
        'code' => $r['code'],
        'host' => ['id' => (int)$r['host_id'], 'username' => $r['host_name'] ?? ''],
        'soup_id' => $r['soup_id'] ? (int)$r['soup_id'] : null,
        'status' => $r['status'],
        'ai_enabled' => (bool)$r['ai_enabled'],
        'ai_question_limit' => (int)($r['ai_question_limit'] ?? 0),
        'ai_question_count' => (int)($r['ai_question_count'] ?? 0),
        'member_limit' => (int)($r['member_limit'] ?? 0),
        'member_count' => count_room_members($r),
    ];
}

function message_to_dict(array $m): array {
    return [
        'id' => (int)$m['id'],
        'username' => $m['username'] ?? '',
        'msg_type' => $m['msg_type'],
        'content' => $m['content'],
        'created_at' => $m['created_at'] ?? '',
    ];
}
