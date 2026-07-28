<?php
/**
 * 海龟汤馆 · 配置
 * 部署时改这里，或通过环境变量覆盖
 */
class Config {
    /** 应用密钥（session/验证码签名） */
    public static $SECRET_KEY = '';

    /** 数据库文件路径 */
    public static $DB_PATH = __DIR__ . '/data/haiguitang.db';

    /** 汤源目录（MD 文件） */
    public static $SOUPS_DIR = __DIR__ . '/data/soups';

    /** DeepSeek API —— 仅公开的接入地址与模型，密钥由前端用户自填 */
    public static $DEEPSEEK_BASE_URL = 'https://api.deepseek.com/v1';
    public static $DEEPSEEK_MODEL = 'deepseek-v4-flash';

    /** SMTP 邮件配置（不配则验证码直接返回在响应中，仅开发模式） */
    public static $MAIL_SMTP_HOST = '';
    public static $MAIL_SMTP_PORT = 465;
    public static $MAIL_SMTP_USER = '';
    public static $MAIL_SMTP_PASS = '';
    public static $MAIL_FROM = '';

    /** 是否允许投稿（登录后任意用户） */
    public static $ALLOW_SUBMIT = true;

    /** 是否允许公开注册（关闭后只能由管理员后台建号） */
    public static $ALLOW_REGISTER = true;

    /** 发件人显示名称（邮件 From 头展示用） */
    public static $MAIL_FROM_NAME = '海龟汤馆';

    /** 验证码有效期（秒） */
    public static $CODE_TTL = 600;

    /** 轮询消息间隔（毫秒，前端参考） */
    public static $POLL_INTERVAL = 1500;

    /** 房间消息保留条数（0=全部） */
    public static $ROOM_MSG_LIMIT = 200;

    /** 运维工具 Token（留空则只用管理员 session，设置后支持 ?token=xxx 免登录访问 tool.php） */
    public static $TOOL_TOKEN = '';

    /** 后台 API Token（留空则只能用管理员 session；设置后可用 X-Admin-Token 头免登录调用 /api/admin/*） */
    public static $ADMIN_API_TOKEN = '';

    /** Session 超时（秒，0 表示不限制；默认 30 天，与 cookie lifetime 一致） */
    public static $SESSION_TIMEOUT = 2592000;

    /** 频率限制：AI 提问每分钟最大次数 */
    public static $RATE_LIMIT_AI_ASK = 10;

    /** 频率限制：房间创建每分钟最大次数 */
    public static $RATE_LIMIT_ROOM_CREATE = 5;

    /** 频率限制：消息发送每房间每分钟最大次数 */
    public static $RATE_LIMIT_MSG_SEND = 30;

    /** 频率限制：自动清理过期记录的概率（0-1） */
    public static $RATE_LIMIT_CLEANUP_PROBABILITY = 0.01;

    /** 初始化时从环境变量覆盖 */
    public static function load() {
        $env = function($key, $default) {
            $v = getenv($key);
            return $v === false ? $default : $v;
        };
        self::$SECRET_KEY = $env('SECRET_KEY', self::$SECRET_KEY);
        self::$DB_PATH    = $env('DB_PATH', self::$DB_PATH);
        self::$SOUPS_DIR  = $env('SOUPS_DIR', self::$SOUPS_DIR);
        self::$DEEPSEEK_BASE_URL = $env('DEEPSEEK_BASE_URL', self::$DEEPSEEK_BASE_URL);
        self::$DEEPSEEK_MODEL   = $env('DEEPSEEK_MODEL', self::$DEEPSEEK_MODEL);
        self::$MAIL_SMTP_HOST = $env('MAIL_SMTP_HOST', self::$MAIL_SMTP_HOST);
        self::$MAIL_SMTP_PORT = (int)$env('MAIL_SMTP_PORT', self::$MAIL_SMTP_PORT);
        self::$MAIL_SMTP_USER = $env('MAIL_SMTP_USER', self::$MAIL_SMTP_USER);
        self::$MAIL_SMTP_PASS = $env('MAIL_SMTP_PASS', self::$MAIL_SMTP_PASS);
        self::$MAIL_FROM = $env('MAIL_FROM', self::$MAIL_FROM);
        self::$MAIL_FROM_NAME = $env('MAIL_FROM_NAME', self::$MAIL_FROM_NAME);
        self::$TOOL_TOKEN = $env('TOOL_TOKEN', self::$TOOL_TOKEN);
        self::$ADMIN_API_TOKEN = $env('ADMIN_API_TOKEN', self::$ADMIN_API_TOKEN);
        self::$ALLOW_REGISTER = $env('ALLOW_REGISTER', self::$ALLOW_REGISTER ? '1' : '0') === '1';
        self::$RATE_LIMIT_AI_ASK = (int)$env('RATE_LIMIT_AI_ASK', self::$RATE_LIMIT_AI_ASK);
        self::$RATE_LIMIT_ROOM_CREATE = (int)$env('RATE_LIMIT_ROOM_CREATE', self::$RATE_LIMIT_ROOM_CREATE);
        self::$RATE_LIMIT_MSG_SEND = (int)$env('RATE_LIMIT_MSG_SEND', self::$RATE_LIMIT_MSG_SEND);

        if (self::$SECRET_KEY === '') {
            self::$SECRET_KEY = bin2hex(random_bytes(32));
        }
    }

    /** 从 settings 表加载持久化配置（覆盖默认值与环境变量，由 DB 初始化后调用） */
    public static function load_from_db() {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->query('SELECT key, value FROM settings');
            if (!$stmt) return;
            foreach ($stmt->fetchAll() as $r) {
                $k = $r['key'];
                $v = $r['value'];
                if ($k === 'allow_submit') self::$ALLOW_SUBMIT = ($v === '1');
                elseif ($k === 'allow_register') self::$ALLOW_REGISTER = ($v === '1');
                elseif ($k === 'room_msg_limit') self::$ROOM_MSG_LIMIT = (int)$v;
            }
        } catch (Throwable $e) {
            // 表不存在或数据库未初始化时忽略
        }
    }
}
Config::load();
