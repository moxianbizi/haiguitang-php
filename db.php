<?php
/**
 * 数据库连接 + 初始化
 * SQLite，单文件，便于部署
 */
require_once __DIR__ . '/config.php';

class DB {
    private static $pdo = null;

    public static function pdo() {
        if (self::$pdo === null) {
            $path = Config::$DB_PATH;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            try {
                $dsn = 'sqlite:' . $path;
                self::$pdo = new PDO($dsn);
            } catch (PDOException $e) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'error' => '数据库连接失败',
                    'detail' => $e->getMessage(),
                    'db_path' => $path,
                    'dir_writable' => is_writable($dir) ? 'yes' : 'no',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::init_schema();
        }
        return self::$pdo;
    }

    private static function init_schema() {
        $pdo = self::$pdo;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
            CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);

            CREATE TABLE IF NOT EXISTS soups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL UNIQUE,
                season TEXT,
                episode TEXT,
                title TEXT NOT NULL,
                surface TEXT,
                base TEXT,
                author_id INTEGER,
                created_at TEXT DEFAULT (datetime('now')),
                sort_order INTEGER DEFAULT 0,
                FOREIGN KEY (author_id) REFERENCES users(id)
            );
            CREATE INDEX IF NOT EXISTS idx_soups_season ON soups(season);

            CREATE TABLE IF NOT EXISTS rooms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE,
                host_id INTEGER NOT NULL,
                soup_id INTEGER,
                status TEXT DEFAULT 'playing',
                ai_enabled INTEGER DEFAULT 1,
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (host_id) REFERENCES users(id),
                FOREIGN KEY (soup_id) REFERENCES soups(id)
            );
            CREATE INDEX IF NOT EXISTS idx_rooms_code ON rooms(code);
            CREATE INDEX IF NOT EXISTS idx_rooms_status ON rooms(status);

            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                room_id INTEGER NOT NULL,
                user_id INTEGER,
                username TEXT,
                msg_type TEXT NOT NULL,
                content TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );
            CREATE INDEX IF NOT EXISTS idx_messages_room ON messages(room_id, id);
        ");
    }

    /** 导入 soups 目录的 MD 文件（仅当表为空时） */
    public static function import_soups_if_empty() {
        $pdo = self::pdo();
        $count = (int)$pdo->query('SELECT COUNT(*) FROM soups')->fetchColumn();
        if ($count > 0) return;

        $dir = Config::$SOUPS_DIR;
        if (!is_dir($dir)) {
            // 兼容：尝试 ../soups
            $alt = __DIR__ . '/data/soups';
            if (is_dir($alt)) $dir = $alt;
            else return;
        }

        require_once __DIR__ . '/lib/md.php';
        $files = array_filter(scandir($dir), fn($f) => str_ends_with($f, '.md'));
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        $stmt = $pdo->prepare('INSERT INTO soups (filename, season, episode, title, surface, base, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $pdo->beginTransaction();
        foreach ($files as $idx => $f) {
            $content = file_get_contents($dir . '/' . $f);
            $p = parse_md($f, $content);
            $stmt->execute([$p['filename'], $p['season'], $p['episode'], $p['title'], $p['surface'], $p['base'], $idx]);
        }
        $pdo->commit();
    }
}
