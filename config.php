<?php
/**
 * 海龟汤馆 · 配置
 * 部署时改这里，或通过环境变量覆盖
 */
class Config {
    /** 应用密钥（session/验证码签名） */
    public static $SECRET_KEY = 'change-me-please-use-a-long-random-string';

    /** 数据库文件路径 */
    public static $DB_PATH = __DIR__ . '/data/haiguitang.db';

    /** 汤源目录（MD 文件） */
    public static $SOUPS_DIR = __DIR__ . '/data/soups';

    /** DeepSeek API —— 仅公开的接入地址与模型，密钥由前端用户自填 */
    public static $DEEPSEEK_BASE_URL = 'https://api.deepseek.com/v1';
    public static $DEEPSEEK_MODEL = 'deepseek-chat';

    /** SMTP 邮件配置（不配则验证码直接返回在响应中，仅开发模式） */
    public static $MAIL_SMTP_HOST = '';
    public static $MAIL_SMTP_PORT = 465;
    public static $MAIL_SMTP_USER = '';
    public static $MAIL_SMTP_PASS = '';
    public static $MAIL_FROM = '';

    /** 是否允许投稿（登录后任意用户） */
    public static $ALLOW_SUBMIT = true;

    /** 验证码有效期（秒） */
    public static $CODE_TTL = 600;

    /** 轮询消息间隔（毫秒，前端参考） */
    public static $POLL_INTERVAL = 1500;

    /** 房间消息保留条数（0=全部） */
    public static $ROOM_MSG_LIMIT = 200;

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
    }
}
Config::load();
