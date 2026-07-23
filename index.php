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

// 子目录部署支持：识别当前入口文件的目录前缀
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($uri, $scriptDir . '/')) {
    $uri = substr($uri, strlen($scriptDir));
} elseif ($scriptDir !== '' && $scriptDir !== '/' && $uri === $scriptDir) {
    $uri = '/';
}
if ($uri === '' || $uri === false) $uri = '/';

// 注意：PHP 内置 server 的 router 模式下，SCRIPT_NAME 会被设为请求路径，
// dirname 会变成请求路径的目录（例如 /api），若按上述逻辑会误剥离。
// 因此这里增加一个判断：如果 SCRIPT_NAME 对应的真实文件不存在，
// 说明是 router 模式，忽略它。
$scriptFile = $_SERVER['DOCUMENT_ROOT'] . ($_SERVER['SCRIPT_NAME'] ?? '');
if (!is_file($scriptFile)) {
    // router 模式：重新取 REQUEST_URI 并按环境变量 BASE_PATH（可选）剥离
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $base = rtrim(getenv('BASE_PATH') ?: '', '/');
    if ($base && $base !== '/' && str_starts_with($uri, $base . '/')) {
        $uri = substr($uri, strlen($base));
    }
    if ($uri === '' || $uri === false) $uri = '/';
}

// 调试（生产删除）
// if (getenv('HGT_DEBUG')) {
//     header('Content-Type: text/plain');
//     echo 'SCRIPT_NAME=' . ($_SERVER['SCRIPT_NAME'] ?? '') . "\n";
//     echo 'uri=' . $uri . "\n";
//     exit;
// }

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
