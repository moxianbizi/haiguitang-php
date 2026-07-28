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
                error_log("Database connection failed: {$e->getMessage()}");
                echo json_encode([
                    'error' => '数据库连接失败',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::init_schema();
            // 数据库初始化后从 settings 表加载持久化配置
            Config::load_from_db();
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
                created_at TEXT DEFAULT (datetime('now')),
                is_admin INTEGER DEFAULT 0,
                is_banned INTEGER DEFAULT 0,
                banned_reason TEXT
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
                host_manual TEXT,
                extra TEXT,
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

            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS admin_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER,
                admin_name TEXT,
                action TEXT NOT NULL,
                target TEXT,
                detail TEXT,
                ip TEXT,
                created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_admin_logs_created ON admin_logs(created_at);

            CREATE TABLE IF NOT EXISTS comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                soup_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                username TEXT NOT NULL,
                content TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now')),
                deleted_at TEXT,
                FOREIGN KEY (soup_id) REFERENCES soups(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );
            CREATE INDEX IF NOT EXISTS idx_comments_soup ON comments(soup_id, id);

            CREATE TABLE IF NOT EXISTS follows (
                follower_id INTEGER NOT NULL,
                following_id INTEGER NOT NULL,
                created_at TEXT DEFAULT (datetime('now')),
                PRIMARY KEY (follower_id, following_id),
                FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_follows_follower ON follows(follower_id);
            CREATE INDEX IF NOT EXISTS idx_follows_following ON follows(following_id);
        ");

        // 迁移：为旧数据库补列
        $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('is_admin', $cols)) $pdo->exec('ALTER TABLE users ADD COLUMN is_admin INTEGER DEFAULT 0');
        if (!in_array('is_banned', $cols)) $pdo->exec('ALTER TABLE users ADD COLUMN is_banned INTEGER DEFAULT 0');
        if (!in_array('banned_reason', $cols)) $pdo->exec('ALTER TABLE users ADD COLUMN banned_reason TEXT');

        // 迁移：soups 表补 host_manual / extra 列
        $soupCols = $pdo->query('PRAGMA table_info(soups)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('host_manual', $soupCols)) $pdo->exec('ALTER TABLE soups ADD COLUMN host_manual TEXT');
        if (!in_array('extra', $soupCols)) $pdo->exec('ALTER TABLE soups ADD COLUMN extra TEXT');

        // 迁移：rooms 表补 ai_question_limit / member_limit / ai_question_count
        $roomCols = $pdo->query('PRAGMA table_info(rooms)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('ai_question_limit', $roomCols)) $pdo->exec('ALTER TABLE rooms ADD COLUMN ai_question_limit INTEGER DEFAULT 0');
        if (!in_array('member_limit', $roomCols)) $pdo->exec('ALTER TABLE rooms ADD COLUMN member_limit INTEGER DEFAULT 0');
        if (!in_array('ai_question_count', $roomCols)) $pdo->exec('ALTER TABLE rooms ADD COLUMN ai_question_count INTEGER DEFAULT 0');

        // 迁移：soups 表补 status / reject_reason
        $soupCols2 = $pdo->query('PRAGMA table_info(soups)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('status', $soupCols2)) $pdo->exec("ALTER TABLE soups ADD COLUMN status TEXT DEFAULT 'approved'");
        if (!in_array('reject_reason', $soupCols2)) $pdo->exec('ALTER TABLE soups ADD COLUMN reject_reason TEXT DEFAULT NULL');

        // 迁移：soups 表补 images
        $soupCols3 = $pdo->query('PRAGMA table_info(soups)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('images', $soupCols3)) $pdo->exec("ALTER TABLE soups ADD COLUMN images TEXT DEFAULT '[]'");

        // 迁移：soups 表补 view_count / rooms 表补 ai_ask_count
        $soupCols4 = $pdo->query('PRAGMA table_info(soups)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('view_count', $soupCols4)) $pdo->exec('ALTER TABLE soups ADD COLUMN view_count INTEGER DEFAULT 0');
        $roomCols2 = $pdo->query('PRAGMA table_info(rooms)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('ai_ask_count', $roomCols2)) $pdo->exec('ALTER TABLE rooms ADD COLUMN ai_ask_count INTEGER DEFAULT 0');

        // 迁移：rooms 表补 room_type / state（灵之残响专属房间用）
        // room_type: normal=普通房间, lzcx=灵之残响专属房间
        // state: JSON 字符串，存碎片/触发/任务/理智等状态机
        $roomCols3 = $pdo->query('PRAGMA table_info(rooms)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('room_type', $roomCols3)) $pdo->exec("ALTER TABLE rooms ADD COLUMN room_type TEXT DEFAULT 'normal'");
        if (!in_array('state', $roomCols3)) $pdo->exec("ALTER TABLE rooms ADD COLUMN state TEXT DEFAULT '{}'");
        // 为灵之残响房间查询建索引
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rooms_type ON rooms(room_type) WHERE room_type = 'lzcx'");

        // 迁移：rooms 表补 ai_key_encrypted（房主绑定的 AI Key，加密存储，房间全员共用）
        $roomCols4 = $pdo->query('PRAGMA table_info(rooms)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('ai_key_encrypted', $roomCols4)) $pdo->exec("ALTER TABLE rooms ADD COLUMN ai_key_encrypted TEXT DEFAULT NULL");

        // 迁移：新建 room_members 表（灵之残响房间角色分配）
        // 复用 ON DELETE CASCADE 跟随 rooms 清理
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS room_members (
                room_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL DEFAULT 'player',
                character_name TEXT,
                joined_at TEXT DEFAULT (datetime('now')),
                PRIMARY KEY (room_id, user_id),
                FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_room_members_room ON room_members(room_id);
            CREATE INDEX IF NOT EXISTS idx_room_members_user ON room_members(user_id);
        ");

        // 第一个注册的用户自动设为管理员（如果还没有管理员）
        $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
        if ($adminCount === 0) {
            $firstUser = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetch();
            if ($firstUser) {
                $pdo->exec('UPDATE users SET is_admin = 1 WHERE id = ' . (int)$firstUser['id']);
            }
        }
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

        $stmt = $pdo->prepare('INSERT INTO soups (filename, season, episode, title, surface, base, host_manual, extra, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $pdo->beginTransaction();
        foreach ($files as $idx => $f) {
            $content = file_get_contents($dir . '/' . $f);
            $p = parse_md($f, $content);
            $stmt->execute([$p['filename'], $p['season'], $p['episode'], $p['title'], $p['surface'], $p['base'], $p['host_manual'], $p['extra'], $idx]);
        }
        $pdo->commit();
    }

    /**
     * 重新解析并更新所有汤的字段（保留 id/author_id/sort_order/created_at）
     * 用于 parse_md 升级后刷新已有数据。仅管理员可触发。
     * @return array {updated, skipped, total}
     */
    public static function reimport_all(): array {
        $pdo = self::pdo();
        $dir = Config::$SOUPS_DIR;
        if (!is_dir($dir)) {
            $alt = __DIR__ . '/data/soups';
            if (is_dir($alt)) $dir = $alt;
            else return ['updated' => 0, 'skipped' => 0, 'total' => 0, 'error' => 'soups 目录不存在'];
        }

        require_once __DIR__ . '/lib/md.php';

        // 1. 更新已存在的汤（源文件仍在的）
        $rows = $pdo->query('SELECT id, filename FROM soups')->fetchAll();
        $stmt = $pdo->prepare('UPDATE soups SET title=?, season=?, episode=?, surface=?, base=?, host_manual=?, extra=? WHERE id=?');
        $updated = 0; $skipped = 0; $deleted = 0;
        $pdo->beginTransaction();
        $existingFiles = []; // 数据库中已有但源文件不存在的汤 id
        foreach ($rows as $row) {
            $file = $dir . '/' . $row['filename'];
            if (!is_file($file)) { $existingFiles[] = (int)$row['id']; continue; }
            $content = file_get_contents($file);
            $p = parse_md($row['filename'], $content);
            $stmt->execute([$p['title'], $p['season'], $p['episode'], $p['surface'], $p['base'], $p['host_manual'], $p['extra'], $row['id']]);
            $updated++;
        }
        // 2. 删除源文件已不存在的汤（全量替换场景）
        if ($existingFiles) {
            $delStmt = $pdo->prepare('DELETE FROM soups WHERE id = ?');
            foreach ($existingFiles as $id) { $delStmt->execute([$id]); $deleted++; }
        }
        $pdo->commit();

        // 3. 导入新增的汤（源目录有但数据库没有的）
        $dbFiles = array_column($rows, 'filename');
        $dirFiles = array_filter(scandir($dir), fn($f) => str_ends_with($f, '.md'));
        $newFiles = array_diff($dirFiles, $dbFiles);
        $imported = 0;
        if ($newFiles) {
            sort($newFiles, SORT_NATURAL | SORT_FLAG_CASE);
            $insStmt = $pdo->prepare('INSERT INTO soups (filename, season, episode, title, surface, base, host_manual, extra, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)');
            $pdo->beginTransaction();
            foreach ($newFiles as $f) {
                $content = file_get_contents($dir . '/' . $f);
                $p = parse_md($f, $content);
                $insStmt->execute([$p['filename'], $p['season'], $p['episode'], $p['title'], $p['surface'], $p['base'], $p['host_manual'], $p['extra']]);
                $imported++;
            }
            $pdo->commit();
        }

        return [
            'updated'  => $updated,
            'skipped'  => $skipped,
            'deleted'  => $deleted,
            'imported' => $imported,
            'total'    => count($rows),
        ];
    }
}
