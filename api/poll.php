<?php
/** 兼容前端轮询入口：/api/poll/<code> 等同于 /api/rooms/<code>/messages?since=N */

function handle_poll(array $segments) {
    $code = strtoupper($segments[1] ?? '');
    if ($code === '') json_error('Not Found', 404);
    rooms_poll_messages($code);
}
