<?php
/**
 * PHP 内置开发服务器路由
 * 用法：php -S 127.0.0.1:8080 router.php
 *
 * 规则：如果请求的是 frontend 下存在的静态文件则直接返回，否则交给 index.php 处理
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/frontend' . $uri;

// 静态文件直接返回（让 PHP 内置 server 处理）
if ($uri !== '/' && $uri !== '' && is_file($file)) {
    return false;
}

// 其余请求（含 /api/* 和 /）交给 index.php
require __DIR__ . '/index.php';
