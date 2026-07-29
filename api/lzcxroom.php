<?php
/**
 * 灵之残响专属房间 API
 *
 * 与普通 rooms.php 的区别：
 *   1. 强制 season='灵之残响' 的汤
 *   2. rooms.state 存状态机（碎片/触发/任务/理智/提问计数）
 *   3. room_members 表存角色分配，AI 按角色视角回答
 *   4. AI 调用走 ask_ai_lzcx()，支持多轮上下文 + 状态注入
 *   5. 房主有手动控制接口：释放碎片/触发规则/完成任务/调理智
 *
 * 路由前缀：/api/lzcxroom/*
 */

// 复用 rooms.php 的辅助函数（save_message / message_to_dict / count_room_members 等）
require_once __DIR__ . '/rooms.php';

/**
 * 灵之残响固定角色池（灵渊司成员）
 * 注意：此处的「角色」是玩家分配的灵渊司身份，不是汤中的「幻灵角色视角」。
 */
const LZCX_CHARACTERS = [
    ['name' => '减',      'dept' => '纠察处·灵探', 'ability' => '排除：消耗2理智，提出结论并进行排除'],
    ['name' => '许复元',  'dept' => '纠察处·灵探', 'ability' => '破局：提出推理，主持人回答是/不是；若回答「不是」消耗4理智'],
    ['name' => '辛笙',    'dept' => '纠察处·灵探', 'ability' => '心声：消耗2理智，提出两个结论，主持人告知其中绝对正确的数量'],
    ['name' => '意马',    'dept' => '镇压所·灵契', 'ability' => '以意化灵：登场时初始理智+20%；羁绊：不与孙沐阳同场'],
    ['name' => '柳双鱼',  'dept' => '镇压所·灵契', 'ability' => '滞时：登场时初始理智-10%；拷贝：获得碎片时额外+1'],
    ['name' => '柳千渊',  'dept' => '重现署·灵者', 'ability' => '现！：消耗4理智获得一块碎片，不可连续发动'],
    ['name' => '孙沐阳',  'dept' => '重现署·灵者', 'ability' => '以心为眼：每减少15理智获得一块碎片；羁绊：不与意马同场'],
];

function handle_lzcxroom(array $segments) {
    $action = $segments[1] ?? '';
    if ($action === '') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') lzcx_list();
        elseif ($_SERVER['REQUEST_METHOD'] === 'POST') lzcx_create();
        else json_error('Method Not Allowed', 405);
        return;
    }

    $code = strtoupper($action);
    $sub = $segments[2] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    // 房间级
    if ($method === 'GET' && $sub === '') { require_login(); lzcx_get($code); }
    elseif ($method === 'DELETE' && $sub === '') lzcx_dissolve($code);
    // 成员
    elseif ($sub === 'join' && $method === 'POST') lzcx_join($code);
    elseif ($sub === 'assign-character' && $method === 'POST') lzcx_assign_character($code);
    elseif ($sub === 'members' && $method === 'GET') { require_login(); lzcx_members($code); }
    // 消息
    elseif ($sub === 'messages' && $method === 'POST') lzcx_send_message($code);
    elseif ($sub === 'messages' && $method === 'GET') { require_login(); lzcx_poll_messages($code); }
    // AI 提问
    elseif ($sub === 'ask' && $method === 'POST') lzcx_ask($code);
    // 房主绑定/更新 AI Key（房间全员共用）
    elseif ($sub === 'ai-key' && $method === 'POST') lzcx_set_ai_key($code);
    // 房主状态机控制
    elseif ($sub === 'release-fragment' && $method === 'POST') lzcx_release_fragment($code);
    elseif ($sub === 'trigger' && $method === 'POST') lzcx_trigger($code);
    elseif ($sub === 'complete-task' && $method === 'POST') lzcx_complete_task($code);
    elseif ($sub === 'sanity' && $method === 'PUT') lzcx_set_sanity($code);
    elseif ($sub === 'reset-state' && $method === 'POST') lzcx_reset_state($code);
    else json_error('Not Found', 404);
}

// ===================== 汤源解析（从汤面/手册提取状态机参数） =====================

/**
 * 从汤的字段中解析灵之残响专属参数：
 *   - total_fragments: 总碎片数（"碎片数量：4"）
 *   - initial_sanity: 初始理智（"初始理智：60"）
 *   - characters: 角色列表（从"幻灵角色视角"段提取"1. 老板娘"等）
 *   - tasks: 任务列表（"任务1：..."）
 *   - rules: 隐藏规则触发条件（"规则六：...（触发条件：XXX）"）
 */
function lzcx_parse_meta(string $surface, string $hostManual, string $extra): array {
    $meta = [
        'total_fragments'  => 0,
        'initial_sanity'   => 0,
        'characters'       => [],
        'tasks'            => [],
        'hidden_rules'     => [], // ['name'=>'规则六', 'condition'=>'推理出主角为人鱼']
        'key_nodes'        => [], // 作者预定义的关键节点名列表（空=让 AI 自行拆分）
    ];

    $blob = $surface . "\n" . $hostManual . "\n" . $extra;

    // 碎片数量
    if (preg_match('/碎片数量[：:]\s*(\d+)/u', $blob, $m)) {
        $meta['total_fragments'] = (int)$m[1];
    }
    // 初始理智
    if (preg_match('/初始理智[：:]\s*(\d+)/u', $blob, $m)) {
        $meta['initial_sanity'] = (int)$m[1];
    }

    // 关键节点：作者可在 extra/host_manual 中用「【关键节点】」段预定义
    // 格式：
    //   【关键节点】
    //   1. 主角是人鱼
    //   2. 死因是溺水
    //   ...
    // 也兼容「关键节点：」「关键节点:」作标题。每行一个节点。
    if (preg_match('/【?关键节点】?\s*[：:]\s*\n([\s\S]*?)(?=\n【|\n关键节点|\n规则|\n任务|\n幻灵|\n残响|\n收容|\Z)/u', $blob, $km)) {
        $block = $km[1];
        $lines = explode("\n", $block);
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;
            // 去掉行首序号 "1." "1、" "1)" "* " "- "
            $t = preg_replace('/^[*\-]?\s*\d+[.、)]\s*/u', '', $t);
            $t = trim($t, " *　");
            if ($t !== '' && mb_strlen($t) <= 60) {
                $meta['key_nodes'][] = $t;
            }
        }
        $meta['key_nodes'] = array_values(array_unique($meta['key_nodes']));
    }

    // 角色：灵之残响采用固定灵渊司角色池，不按汤中「幻灵角色视角」分配。
    // 汤中幻灵角色（老板娘、客人等）由灵者使用「幻灵」能力后临时扮演。
    $meta['characters'] = array_column(LZCX_CHARACTERS, 'name');
    $meta['characters_info'] = LZCX_CHARACTERS;

    // 任务：「任务1：XXX」「任务 1：XXX」「最终任务：XXX」
    if (preg_match_all('/(?:最终任务|任务\s*(\d))[：:]\s*([^\n]+)/u', $blob, $tm, PREG_SET_ORDER)) {
        foreach ($tm as $t) {
            $num = $t[1] !== '' ? (int)$t[1] : 999; // 最终任务用 999
            $meta['tasks'][] = ['num' => $num, 'desc' => trim($t[2])];
        }
    }

    // 隐藏规则触发条件：「规则六：...（触发条件：XXX）」
    if (preg_match_all('/规则([一二三四五六七八九十]+|\d+)[：:][^\n]*?（触发条件(?:\d+)?[：:]([^）]+)）/u', $blob, $rm, PREG_SET_ORDER)) {
        foreach ($rm as $r) {
            $meta['hidden_rules'][] = [
                'name'      => '规则' . $r[1],
                'condition' => trim($r[2]),
            ];
        }
    }

    return $meta;
}

/**
 * 初始化灵之残响房间的状态机
 * key_nodes:
 *   - 作者预定义时：[['name'=>str,'hit'=>false], ...]
 *   - 作者未定义时：[]（空数组，启用机制但让 AI 首次回答自行拆分）
 *   - 注意：[] 与"未启用"不同。未启用由调用方决定（lzcx 默认启用）。
 */
function lzcx_init_state(array $meta): array {
    $keyNodes = [];
    foreach (($meta['key_nodes'] ?? []) as $name) {
        $keyNodes[] = ['name' => $name, 'hit' => false];
    }
    return [
        'released_fragments' => 0,
        'total_fragments'    => $meta['total_fragments'] ?? 0,
        'initial_sanity'     => $meta['initial_sanity'] ?? 0,
        'sanity'             => $meta['initial_sanity'] ?? 0,
        'triggered_rules'    => [],
        'completed_tasks'    => [],
        'ask_count'          => 0,
        'characters_meta'    => $meta['characters'] ?? [],
        'characters_info'    => $meta['characters_info'] ?? [],
        'tasks_meta'         => $meta['tasks'] ?? [],
        'hidden_rules_meta'  => $meta['hidden_rules'] ?? [],
        // 关键节点：空数组=已启用机制但待 AI 自拆；非空=作者预定义
        'key_nodes'          => $keyNodes,
        'cleared'            => false, // 是否已通关（命中≥85%）
    ];
}

/** 安全读取房间 state */
function lzcx_load_state(array $room): array {
    $s = json_decode($room['state'] ?? '{}', true);
    if (!is_array($s)) $s = [];
    // 兜底字段
    $s += [
        'released_fragments' => 0,
        'total_fragments'    => 0,
        'initial_sanity'     => 0,
        'sanity'             => 0,
        'triggered_rules'    => [],
        'completed_tasks'    => [],
        'ask_count'          => 0,
        'characters_meta'    => [],
        'characters_info'    => [],
        'key_nodes'          => [],
        'cleared'            => false,
    ];
    // 角色池是全局固定的，每次加载都刷新，避免旧房间保留过期角色
    $s['characters_meta'] = array_column(LZCX_CHARACTERS, 'name');
    $s['characters_info'] = LZCX_CHARACTERS;
    return $s;
}

/** 写回 state */
function lzcx_save_state(int $roomId, array $state): void {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('UPDATE rooms SET state = ? WHERE id = ?');
    $stmt->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $roomId]);
}

/** 取房间（必须是 lzcx 类型） */
function lzcx_require_room(string $code): array {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE code = ? AND room_type = 'lzcx'");
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('灵之残响房间不存在', 404);
    return $r;
}

/** 验证汤是灵之残响系列 */
function lzcx_require_soup(int $soupId): array {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id, filename, season, episode, title, surface, base, host_manual, extra FROM soups WHERE id = ? AND status = 'approved'");
    $stmt->execute([$soupId]);
    $s = $stmt->fetch();
    if (!$s) json_error('海龟汤不存在或未通过审核', 400);
    if ($s['season'] !== '灵之残响') {
        json_error('灵之残响专属房间只能选择「灵之残响」系列的汤');
    }
    return $s;
}

// ===================== 房间 CRUD =====================

function lzcx_create() {
    $user = require_login();
    if (!rate_limit("lzcx_room_create_user_{$user['id']}", Config::$RATE_LIMIT_ROOM_CREATE, 60)) {
        json_error('创建房间过于频繁，请稍后再试', 429);
    }
    $data = body_json();
    $soup_id = (int)($data['soup_id'] ?? 0);
    if ($soup_id <= 0) json_error('灵之残响房间必须选汤');
    $soup = lzcx_require_soup($soup_id);

    $ai_enabled = $data['ai_enabled'] ?? true;
    $ai_question_limit = max(0, (int)($data['ai_question_limit'] ?? 0));
    $member_limit = max(0, (int)($data['member_limit'] ?? 0));
    if ($member_limit > 0 && $member_limit < 2) $member_limit = 2;

    // 解析汤的灵之残响参数，初始化状态机
    $meta = lzcx_parse_meta($soup['surface'] ?? '', $soup['host_manual'] ?? '', $soup['extra'] ?? '');
    $state = lzcx_init_state($meta);

    $pdo = DB::pdo();
    $code = gen_room_code();
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM rooms WHERE code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) break;
        $code = gen_room_code();
    }

    $stmt = $pdo->prepare("INSERT INTO rooms (code, host_id, soup_id, ai_enabled, ai_question_limit, member_limit, status, room_type, state) VALUES (?, ?, ?, ?, ?, ?, 'playing', 'lzcx', ?)");
    $stmt->execute([$code, $user['id'], $soup_id, $ai_enabled ? 1 : 0, $ai_question_limit, $member_limit, json_encode($state, JSON_UNESCAPED_UNICODE)]);
    $id = (int)$pdo->lastInsertId();

    // 房主自动加入成员表，角色=host
    $stmt = $pdo->prepare('INSERT INTO room_members (room_id, user_id, role) VALUES (?, ?, ?)');
    $stmt->execute([$id, $user['id'], 'host']);

    // 系统消息
    $stmt = $pdo->prepare('INSERT INTO messages (room_id, msg_type, content) VALUES (?, ?, ?)');
    $stmt->execute([$id, 'system', '灵之残响房间已创建，房主可在控制面板释放碎片/触发规则/分配角色']);

    lzcx_get($code, 201);
}

function lzcx_list() {
    require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT r.id, r.code, r.host_id, r.soup_id, r.status, r.ai_enabled, r.ai_question_limit, r.ai_question_count, r.member_limit, r.created_at, u.username AS host_name, s.title AS soup_title FROM rooms r LEFT JOIN users u ON r.host_id = u.id LEFT JOIN soups s ON r.soup_id = s.id WHERE r.room_type = 'lzcx' AND r.status = 'playing' ORDER BY r.created_at DESC LIMIT 50");
    $stmt->execute();
    $rooms = $stmt->fetchAll();
    json_ok(['rooms' => array_map('lzcx_room_to_dict', $rooms)]);
}

function lzcx_get(string $code, int $status = 200) {
    $pdo = DB::pdo();
    $user = current_user();
    $stmt = $pdo->prepare("SELECT r.*, u.username AS host_name, s.title AS soup_title, s.surface, s.season, s.base, s.host_manual, s.extra FROM rooms r LEFT JOIN users u ON r.host_id = u.id LEFT JOIN soups s ON r.soup_id = s.id WHERE r.code = ? AND r.room_type = 'lzcx'");
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('灵之残响房间不存在', 404);

    $room = lzcx_room_to_dict($r);
    $room['state'] = lzcx_load_state($r);
    // 房主 key 绑定状态（仅返回布尔，不暴露 key 本身）
    $room['has_host_key'] = !empty($r['ai_key_encrypted']);

    // 成员列表（含角色）
    $stmt = $pdo->prepare('SELECT rm.user_id, rm.role, rm.character_name, rm.joined_at, u.username FROM room_members rm JOIN users u ON rm.user_id = u.id WHERE rm.room_id = ? ORDER BY rm.joined_at');
    $stmt->execute([$r['id']]);
    $room['members'] = $stmt->fetchAll();

    // 汤：玩家只看汤面；房主额外看汤底/手册/补充内容
    $isHost = $user && (int)$r['host_id'] === (int)$user['id'];
    $soup = null;
    if ($r['soup_id']) {
        $soup = [
            'id' => (int)$r['soup_id'],
            'title' => $r['soup_title'],
            'season' => $r['season'],
            'surface' => $r['surface'],
        ];
        if ($isHost) {
            $soup['base'] = $r['base'] ?? '';
            $soup['host_manual'] = $r['host_manual'] ?? '';
            $soup['extra'] = $r['extra'] ?? '';
        }
    }

    // 最近消息
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

function lzcx_dissolve(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以解散房间', 403);

    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // room_members / messages 走 ON DELETE CASCADE，删 rooms 即可
        $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
        $stmt->execute([(int)$r['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('解散房间失败：' . $e->getMessage(), 500);
    }
    json_ok(['msg' => '房间已解散']);
}

// ===================== 成员管理 =====================

function lzcx_join(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ($r['status'] !== 'playing') json_error('房间已结束');

    // 人数上限（房主不占名额）
    if ((int)$r['member_limit'] > 0) {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM room_members WHERE room_id = ? AND role != 'host'");
        $stmt->execute([$r['id']]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt >= (int)$r['member_limit']) json_error('房间人数已达上限', 403);
    }

    $pdo = DB::pdo();
    // 已在则不重复加
    $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$r['id'], $user['id']]);
    if ($stmt->fetch()) json_ok(['msg' => '已加入']);

    $stmt = $pdo->prepare("INSERT INTO room_members (room_id, user_id, role) VALUES (?, ?, 'player')");
    $stmt->execute([$r['id'], $user['id']]);

    save_message($r['id'], $user, 'system', "{$user['username']} 加入了房间");
    json_ok(['msg' => '已加入']);
}

function lzcx_assign_character(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以分配角色', 403);

    $data = body_json();
    $targetUserId = (int)($data['user_id'] ?? 0);
    $character = trim($data['character'] ?? '');
    if ($targetUserId <= 0) json_error('缺少 user_id');
    // character 为空表示取消角色
    if (mb_strlen($character) > 50) json_error('角色名过长');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$r['id'], $targetUserId]);
    if (!$stmt->fetch()) json_error('目标用户不在房间内', 404);

    $stmt = $pdo->prepare('UPDATE room_members SET character_name = ? WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$character !== '' ? $character : null, $r['id'], $targetUserId]);

    // 取目标用户名
    $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
    $stmt->execute([$targetUserId]);
    $tu = $stmt->fetch();

    $msg = $character !== ''
        ? "房主分配 {$tu['username']} 扮演角色「{$character}」"
        : "房主取消了 {$tu['username']} 的角色";
    save_message($r['id'], $user, 'system', $msg);

    json_ok(['msg' => '已分配']);
}

function lzcx_members(string $code) {
    $r = lzcx_require_room($code);
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT rm.user_id, rm.role, rm.character_name, rm.joined_at, u.username FROM room_members rm JOIN users u ON rm.user_id = u.id WHERE rm.room_id = ? ORDER BY rm.joined_at');
    $stmt->execute([$r['id']]);
    json_ok(['members' => $stmt->fetchAll()]);
}

// ===================== 消息 =====================

function lzcx_send_message(string $code) {
    $user = require_login();
    $data = body_json();
    $content = trim($data['content'] ?? '');
    if ($content === '') json_error('内容不能为空');
    validate_length($content, 2000, '消息内容');

    $r = lzcx_require_room($code);
    if ($r['status'] !== 'playing') json_error('房间已结束');

    // 必须是房间成员
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$r['id'], $user['id']]);
    if (!$stmt->fetch()) json_error('您不是该房间成员', 403);

    if (!rate_limit("lzcx_msg_room_{$r['id']}_user_{$user['id']}", Config::$RATE_LIMIT_MSG_SEND, 60)) {
        json_error('发送消息过于频繁，请稍后再试', 429);
    }

    $msg = save_message($r['id'], $user, 'chat', $content);
    json_ok(['message' => message_to_dict($msg)]);
}

function lzcx_poll_messages(string $code) {
    $r = lzcx_require_room($code);
    $since = (int)($_GET['since'] ?? 0);
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, user_id, username, msg_type, content, strftime("%H:%M:%S", created_at) AS created_at FROM messages WHERE room_id = ? AND id > ? ORDER BY id');
    $stmt->execute([$r['id'], $since]);
    $msgs = $stmt->fetchAll();
    json_ok(['messages' => array_map('message_to_dict', $msgs), 'last_id' => end($msgs) ? (int)end($msgs)['id'] : $since]);
}

// ===================== AI 提问（核心） =====================

function lzcx_ask(string $code) {
    $user = require_login();
    $data = body_json();
    $content = trim($data['content'] ?? '');
    $api_key = (string)($data['api_key'] ?? '');
    $provider = (string)($data['provider'] ?? 'deepseek');
    $ai_base_url = (string)($data['base_url'] ?? '');
    $ai_model = (string)($data['model'] ?? '');
    if ($content === '') json_error('问题不能为空');
    validate_length($content, 500, '问题内容');

    $r = lzcx_require_room($code);
    if ($r['status'] !== 'playing') json_error('房间已结束');
    if (!$r['soup_id']) json_error('房间里还没有选汤');
    if (!$r['ai_enabled']) json_error('AI 未启用');

    // 必须是房间成员
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT role, character_name FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$r['id'], $user['id']]);
    $membership = $stmt->fetch();
    if (!$membership) json_error('您不是该房间成员', 403);

    if (!rate_limit("lzcx_ai_ask_user_{$user['id']}", Config::$RATE_LIMIT_AI_ASK, 60)) {
        json_error('AI 提问过于频繁，请稍后再试', 429);
    }
    if ((int)$r['ai_question_limit'] > 0 && (int)$r['ai_question_count'] >= (int)$r['ai_question_limit']) {
        json_error('AI 提问次数已达上限（' . (int)$r['ai_question_limit'] . '次）');
    }

    // ===== 房主 key 共享：优先用房间绑定的加密 key bundle，没有才用前端传的 key =====
    [$hostKey, $hostProvider, $hostBaseUrl, $hostModel] = lzcx_decode_host_key($r['ai_key_encrypted'] ?? null);
    if ($hostKey !== '') {
        $api_key = $hostKey;
        // 房主绑定时一并存的 provider 配置优先（保证全员用同一套调用方式）
        $provider = $hostProvider ?: $provider;
        $ai_base_url = $hostBaseUrl ?: $ai_base_url;
        $ai_model = $hostModel ?: $ai_model;
    }
    if ($api_key === '') {
        json_error('本房间未绑定 AI Key，请房主在房间内绑定后再提问', 'missing_key');
    }

    // 取汤（含主持人手册/其他内容，仅审核汤）
    $stmt = $pdo->prepare("SELECT surface, base, host_manual, extra FROM soups WHERE id = ? AND status = 'approved'");
    $stmt->execute([$r['soup_id']]);
    $soup = $stmt->fetch();
    if (!$soup || empty($soup['base'])) {
        $a_msg = save_message($r['id'], null, 'ai_answer', '该汤没有汤底，无法提问');
        json_ok(['answer' => message_to_dict($a_msg), 'error' => '该汤没有汤底，无法提问']);
    }

    // 加载状态机
    $state = lzcx_load_state($r);

    // 取最近 N 条 ai_question + ai_answer 作为多轮上下文
    $stmt = $pdo->prepare("SELECT msg_type, username, content FROM messages WHERE room_id = ? AND msg_type IN ('ai_question','ai_answer') ORDER BY id DESC LIMIT 40");
    $stmt->execute([$r['id']]);
    $rows = array_reverse($stmt->fetchAll());
    $history = [];
    foreach ($rows as $row) {
        if ($row['msg_type'] === 'ai_question') {
            $history[] = ['role' => 'user', 'name' => $row['username'] ?? '', 'content' => $row['content']];
        } else { // ai_answer
            $history[] = ['role' => 'assistant', 'name' => '', 'content' => $row['content']];
        }
    }

    // 保存提问
    $q_msg = save_message($r['id'], $user, 'ai_question', $content);

    // 提问者角色：房主=host（全知），player 用 character_name
    $askerCharacter = '';
    if ($membership['role'] !== 'host' && !empty($membership['character_name'])) {
        $askerCharacter = $membership['character_name'];
    }

    // 关键节点状态：state.key_nodes 存在则启用机制
    // - null：旧房间未启用（向后兼容，理论上 lzcx_init_state 已默认 []）
    // - []：启用但待 AI 自拆
    // - [{name,hit}, ...]：已有节点列表
    $keyNodes = array_key_exists('key_nodes', $state) ? $state['key_nodes'] : null;

    try {
        $answer = ask_ai_lzcx(
            $soup['surface'] ?: '',
            $soup['base'],
            $soup['host_manual'] ?? '',
            $soup['extra'] ?? '',
            $history,
            $state,
            $content,
            $askerCharacter,
            $user['username'],
            $api_key,
            $provider,
            $ai_base_url,
            $ai_model,
            $keyNodes
        );
    } catch (AIError $e) {
        json_ok([
            'question' => message_to_dict($q_msg),
            'answer' => null,
            'error' => $e->getMessage(),
            'code' => $e->aiCode,
        ]);
    }

    // ===== 剥离 AI 回答中的元信息标记 + 更新关键节点状态 =====
    $justCleared = false;
    $newHits = [];
    if ($keyNodes !== null) {
        // 1) 处理 NODES 标记（AI 自拆节点，仅在 key_nodes 为空时接收）
        if (empty($state['key_nodes']) && preg_match('/<<<NODES:([^>]+?)>>>/u', $answer, $nm)) {
            $names = array_filter(array_map('trim', explode('|', $nm[1])));
            $names = array_values(array_unique($names));
            if (count($names) >= 3) { // 至少 3 个才采纳，防 AI 乱输出
                $state['key_nodes'] = array_map(fn($n) => ['name' => $n, 'hit' => false], $names);
            }
            $answer = str_replace($nm[0], '', $answer);
        }

        // 2) 处理 HIT 标记
        if (preg_match('/<<<HIT:([^>]+?)>>>/u', $answer, $hm)) {
            $hitName = trim($hm[1]);
            foreach (($state['key_nodes'] ?? []) as &$node) {
                if (!$node['hit'] && $node['name'] === $hitName) {
                    $node['hit'] = true;
                    $newHits[] = $hitName;
                    break;
                }
            }
            unset($node);
            $answer = str_replace($hm[0], '', $answer);
            // 兜底：剥离可能残留的其它 HIT 标记
            $answer = preg_replace('/<<<HIT:[^>]*?>>>/u', '', $answer);
        }
        $answer = trim($answer);

        // 3) 通关判定：命中节点数 / 总节点数 ≥ 85%
        $nodes = $state['key_nodes'] ?? [];
        if (!empty($nodes) && empty($state['cleared'])) {
            $total = count($nodes);
            $hitCount = count(array_filter($nodes, fn($n) => !empty($n['hit'])));
            if ($total > 0 && ($hitCount / $total) >= 0.85) {
                $state['cleared'] = true;
                $justCleared = true;
            }
        }
    }

    // 成功后递增 ask_count 和房间 ai_question_count
    $state['ask_count'] = (int)($state['ask_count'] ?? 0) + 1;
    lzcx_save_state((int)$r['id'], $state);
    $pdo->exec('UPDATE rooms SET ai_question_count = ai_question_count + 1 WHERE id = ' . (int)$r['id']);

    $a_msg = save_message($r['id'], null, 'ai_answer', $answer);

    // 命中节点系统提示
    if (!empty($newHits)) {
        save_message($r['id'], null, 'system', '🎯 命中关键节点：' . implode('、', $newHits));
    }
    // 通关系统提示
    if ($justCleared) {
        $nodes = $state['key_nodes'] ?? [];
        $total = count($nodes);
        $hitCount = count(array_filter($nodes, fn($n) => !empty($n['hit'])));
        save_message($r['id'], null, 'system', "🏆 通关！已盘出 {$hitCount}/{$total} 个关键节点（≥85%），真相大白！");
    }

    json_ok([
        'question' => message_to_dict($q_msg),
        'answer' => message_to_dict($a_msg),
        'state' => $state,
    ]);
}

// ===================== 房主状态机控制 =====================

function lzcx_release_fragment(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以释放碎片', 403);

    $state = lzcx_load_state($r);
    $total = (int)($state['total_fragments'] ?? 0);
    $released = (int)($state['released_fragments'] ?? 0);
    if ($total > 0 && $released >= $total) json_error('所有碎片已释放完毕');

    $state['released_fragments'] = $released + 1;
    lzcx_save_state((int)$r['id'], $state);

    $msg = "房主释放了第 " . $state['released_fragments'] . " 片残响碎片（共 {$total}）";
    save_message($r['id'], $user, 'system', $msg);

    json_ok(['msg' => '已释放', 'state' => $state]);
}

function lzcx_trigger(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以触发规则', 403);

    $data = body_json();
    $ruleName = trim($data['rule'] ?? '');
    if ($ruleName === '') json_error('请填写规则名（如 规则六）');

    $state = lzcx_load_state($r);
    if (in_array($ruleName, $state['triggered_rules'] ?? [], true)) {
        json_error('该规则已触发');
    }
    $state['triggered_rules'][] = $ruleName;
    lzcx_save_state((int)$r['id'], $state);

    save_message($r['id'], $user, 'system', "房主触发了 {$ruleName}，对应真相已可向玩家揭示");

    json_ok(['msg' => '已触发', 'state' => $state]);
}

function lzcx_complete_task(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以标记任务完成', 403);

    $data = body_json();
    $taskNum = (int)($data['task'] ?? 0);
    if ($taskNum <= 0) json_error('请填写任务编号');

    $state = lzcx_load_state($r);
    if (in_array($taskNum, $state['completed_tasks'] ?? [], true)) {
        json_error('该任务已完成');
    }
    $state['completed_tasks'][] = $taskNum;
    // 任务按序排序
    sort($state['completed_tasks']);
    lzcx_save_state((int)$r['id'], $state);

    save_message($r['id'], $user, 'system', "房主标记任务 {$taskNum} 已完成");

    json_ok(['msg' => '已标记', 'state' => $state]);
}

function lzcx_set_sanity(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以调整理智', 403);

    $data = body_json();
    $sanity = (int)($data['sanity'] ?? -1);
    if ($sanity < 0) json_error('理智值必须 ≥ 0');

    $state = lzcx_load_state($r);
    $state['sanity'] = $sanity;
    lzcx_save_state((int)$r['id'], $state);

    save_message($r['id'], $user, 'system', "房主调整剩余理智为 {$sanity}");

    json_ok(['msg' => '已调整', 'state' => $state]);
}

function lzcx_reset_state(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以重置状态', 403);

    // 重新解析汤源，重建初始状态机
    $stmt = DB::pdo()->prepare("SELECT surface, host_manual, extra FROM soups WHERE id = ?");
    $stmt->execute([$r['soup_id']]);
    $soup = $stmt->fetch();
    $meta = lzcx_parse_meta($soup['surface'] ?? '', $soup['host_manual'] ?? '', $soup['extra'] ?? '');
    $state = lzcx_init_state($meta);
    lzcx_save_state((int)$r['id'], $state);

    save_message($r['id'], $user, 'system', '房主重置了房间状态机（碎片/触发/任务/理智）');

    json_ok(['msg' => '已重置', 'state' => $state]);
}

/**
 * 房主绑定/更新 AI Key（加密存储到 rooms.ai_key_encrypted，房间全员共用）
 * 传 api_key 为空表示解绑（清空）。
 */
function lzcx_set_ai_key(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以绑定 AI Key', 403);

    $data = body_json();
    $key = trim((string)($data['api_key'] ?? ''));
    $provider = trim((string)($data['provider'] ?? 'deepseek'));
    $baseUrl = trim((string)($data['base_url'] ?? ''));
    $model = trim((string)($data['model'] ?? ''));

    if ($key === '') {
        // 解绑
        $stmt = DB::pdo()->prepare('UPDATE rooms SET ai_key_encrypted = NULL WHERE id = ?');
        $stmt->execute([(int)$r['id']]);
        save_message($r['id'], $user, 'system', '房主解绑了房间 AI Key');
        json_ok(['msg' => '已解绑', 'has_key' => false]);
    }

    if (strlen($key) > 200) json_error('AI Key 过长');

    // 把 key + provider 配置一并加密存（用 JSON 包一起）
    $bundle = json_encode([
        'key' => $key,
        'provider' => $provider,
        'base_url' => $baseUrl,
        'model' => $model,
    ], JSON_UNESCAPED_UNICODE);
    $encBundle = encrypt_secret($bundle);
    if ($encBundle === null) json_error('加密失败，请稍后重试', 500);

    $stmt = DB::pdo()->prepare('UPDATE rooms SET ai_key_encrypted = ? WHERE id = ?');
    $stmt->execute([$encBundle, (int)$r['id']]);

    save_message($r['id'], $user, 'system', '房主绑定了 AI Key，房间全员可共用');
    json_ok(['msg' => '已绑定', 'has_key' => true]);
}

/**
 * 解密房间绑定的 AI Key bundle，返回 [key, provider, base_url, model]
 * 兼容旧版只加密 key 的格式。
 */
function lzcx_decode_host_key(?string $cipher): array {
    $raw = decrypt_secret($cipher);
    if ($raw === '') return ['', 'deepseek', '', ''];
    $j = json_decode($raw, true);
    if (is_array($j) && isset($j['key'])) {
        return [
            (string)$j['key'],
            (string)($j['provider'] ?? 'deepseek'),
            (string)($j['base_url'] ?? ''),
            (string)($j['model'] ?? ''),
        ];
    }
    // 旧格式：直接是 key 明文
    return [$raw, 'deepseek', '', ''];
}

// ===================== 辅助 =====================

function lzcx_room_to_dict(array $r): array {
    return [
        'id' => (int)$r['id'],
        'code' => $r['code'],
        'host' => ['id' => (int)$r['host_id'], 'username' => $r['host_name'] ?? ''],
        'soup_id' => $r['soup_id'] ? (int)$r['soup_id'] : null,
        'soup_title' => $r['soup_title'] ?? null,
        'status' => $r['status'],
        'ai_enabled' => (bool)$r['ai_enabled'],
        'ai_question_limit' => (int)($r['ai_question_limit'] ?? 0),
        'ai_question_count' => (int)($r['ai_question_count'] ?? 0),
        'member_limit' => (int)($r['member_limit'] ?? 0),
        'room_type' => $r['room_type'] ?? 'lzcx',
    ];
}
