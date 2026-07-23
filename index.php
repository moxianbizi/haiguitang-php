<?php
/**
 * 海龟汤馆 · 入口路由
 * 所有 /api/* 走这里，其他路径回退到前端静态文件
 */
require_once __DIR__ . '/config.php';

// session 配置
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS'])) {
    ini_set('session.cookie_secure', 1);
}
session_name('hgt_sid');
session_start();

// CORS（同源通常不需要，部署到不同域名时打开）
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Credentials: true');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// 调试块已移除

// 注意：PHP 内置 server 的 router 模式下，SCRIPT_NAME 会被设为请求路径，
// 不能用它来推算部署子目录。直接用 REQUEST_URI 判断即可。
// 子目录部署时，前端资源路径会带前缀，这里不做剥离，靠 .htaccess/Nginx 处理。

// API 路由
if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    $path = substr($uri, 5); // 去掉 /api/
    route_api($path);
    return;
}

// 前端静态文件
serve_static($uri);

// ===================== API 路由 =====================
function route_api(string $path) {
    $segments = explode('/', trim($path, '/'));
    $module = $segments[0] ?? '';

    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/lib/util.php';
    require_once __DIR__ . '/lib/mail.php';
    require_once __DIR__ . '/lib/ai.php';

    // 首次访问自动导入汤
    try {
        DB::import_soups_if_empty();
    } catch (Throwable $e) {
        // 导入失败不阻断
    }

    switch ($module) {
        case 'auth':
            require_once __DIR__ . '/api/auth.php';
            handle_auth($segments);
            break;
        case 'soups':
            require_once __DIR__ . '/api/soups.php';
            handle_soups($segments);
            break;
        case 'rooms':
            require_once __DIR__ . '/api/rooms.php';
            handle_rooms($segments);
            break;
        case 'ai':
            require_once __DIR__ . '/api/ai.php';
            handle_ai($segments);
            break;
        case 'poll':
            require_once __DIR__ . '/api/poll.php';
            handle_poll($segments);
            break;
        case 'health':
            json_ok(['status' => 'ok', 'time' => date('c')]);
            break;
        default:
            json_error('Not Found', 404);
    }
}

// ===================== 静态文件 =====================
function serve_static(string $uri) {
    $frontend = __DIR__ . '/frontend';
    $clean = ltrim($uri, '/');

    if ($clean === '' || $clean === '/') {
        readfile_static($frontend . '/index.html');
        return;
    }

    $target = $frontend . '/' . $clean;
    // 防目录穿越
    $real = realpath($target);
    if ($real && str_starts_with($real, realpath($frontend))) {
        if (is_file($real)) {
            readfile_static($real);
            return;
        }
    }
    // SPA fallback
    readfile_static($frontend . '/index.html');
}

function readfile_static(string $file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=3600');
    readfile($file);
}
