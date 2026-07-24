<?php
/** AI 单人模式 API：POST /api/ai/ask */

function handle_ai(array $segments) {
    $action = $segments[1] ?? '';
    if ($action === 'ask' && $_SERVER['REQUEST_METHOD'] === 'POST') ai_ask();
    else json_error('Not Found', 404);
}

function ai_ask() {
    require_login();
    $data = body_json();
    $soup_id = (int)($data['soup_id'] ?? 0);
    $question = trim($data['question'] ?? '');
    $api_key = (string)($data['api_key'] ?? '');

    if ($soup_id <= 0 || $question === '') json_error('缺少 soup_id 或 question');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT surface, base FROM soups WHERE id = ?');
    $stmt->execute([$soup_id]);
    $soup = $stmt->fetch();
    if (!$soup) json_error('海龟汤不存在', 404);
    if (empty($soup['base'])) json_ok(['error' => '该汤没有汤底，无法提问', 'code' => 'no_base']);

    try {
        $answer = ask_ai($soup['surface'] ?: '', $soup['base'], $question, $api_key);
        json_ok(['answer' => $answer]);
    } catch (AIError $e) {
        json_ok(['error' => $e->getMessage(), 'code' => $e->aiCode]);
    }
}
