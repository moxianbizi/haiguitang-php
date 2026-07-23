# 海龟汤馆 · PHP 版部署文档

全栈单仓库：PHP 8 后端 + SQLite + 前端静态文件，零外部数据库依赖。

## 目录结构

```
haiguitang-php/
├── index.php          # 入口（API 路由 + 静态文件分发）
├── router.php         # PHP 内置开发服务器用
├── config.php         # 配置（密钥/SMTP/路径）
├── db.php             # SQLite 连接 + 表结构 + 汤源导入
├── .htaccess          # Apache 重写规则
├── api/               # 后端 API
│   ├── auth.php       # 注册/登录/验证码/me
│   ├── soups.php      # 汤 CRUD + 下载
│   ├── rooms.php      # 房间 + 消息 + AI 提问
│   ├── ai.php         # 单人模式 AI 提问
│   └── poll.php       # 兼容轮询入口
├── lib/
│   ├── util.php       # JSON 响应/密码哈希/会话
│   ├── md.php         # Markdown 解析（行内汤面/汤底 + ## 标记）
│   ├── mail.php       # SMTP 发信（极简客户端，无依赖）
│   └── ai.php         # DeepSeek API 调用（Key 由前端透传）
├── frontend/          # 前端
│   ├── index.html
│   ├── css/styles.css
│   └── js/app.js      # hash 路由 + 4 页面 + 轮询房间
└── data/
    ├── haiguitang.db  # SQLite（首次访问自动创建）
    └── soups/         # 148 碗汤的 MD 源
```

## 环境要求

- **PHP ≥ 8.0**（推荐 8.1+）
- 扩展：PDO_SQLITE、curl、mbstring、json（基本默认都有）
- 写权限：`data/` 目录（存 SQLite + 上传的 MD）

## 一、本地开发

```bash
cd haiguitang-php
php -S 127.0.0.1:8080 router.php
# 浏览器打开 http://127.0.0.1:8080
```

首次访问任意 `/api/*` 会自动导入 148 碗汤到 `data/haiguitang.db`。

## 二、Apache 虚拟主机（最常见）

把 `haiguitang-php/` 整个目录上传到网站根目录（或子目录），确保：

1. `.htaccess` 生效（Apache 需 `AllowOverride All`）
2. `data/` 目录可写

**虚拟主机配置示例：**

```apache
<VirtualHost *:80>
    ServerName hgt.example.com
    DocumentRoot /var/www/haiguitang-php

    <Directory /var/www/haiguitang-php>
        AllowOverride All
        Require all granted
    </Directory>

    # data 目录禁止外部访问
    <Directory /var/www/haiguitang-php/data>
        Require all denied
    </Directory>
</VirtualHost>
```

国内便宜虚拟主机（阿里云虚拟主机、西部数码等）一般默认就是 Apache + PHP，上传即可用。

## 三、Nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name hgt.example.com;
    root /var/www/haiguitang-php;
    index index.php;

    # 禁止访问 data 目录
    location ^~ /data/ {
        deny all;
        return 404;
    }

    # 静态文件
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # 隐藏 . 开头的文件
    location ~ /\. {
        deny all;
    }
}
```

## 四、宝塔面板

1. 新建网站 → PHP 8.1 → 设置运行目录为根目录
2. 上传 `haiguitang-php/` 全部内容到网站根
3. 给 `data/` 目录设 755 权限，所有者 www
4. 站点设置 → 伪静态 → 选「thinkphp」或粘贴：
   ```
   if (!-e $request_filename) {
       rewrite ^(.*)$ /index.php?s=$1 last;
   }
   ```

## 配置项（config.php 或环境变量）

| 配置 | 环境变量 | 说明 |
|---|---|---|
| SECRET_KEY | `SECRET_KEY` | session 签名密钥，**生产必须改** |
| DB_PATH | `DB_PATH` | SQLite 文件路径 |
| SOUPS_DIR | `SOUPS_DIR` | MD 汤源目录 |
| DEEPSEEK_BASE_URL | `DEEPSEEK_BASE_URL` | DeepSeek 接口地址（默认官方） |
| DEEPSEEK_MODEL | `DEEPSEEK_MODEL` | 模型名（默认 deepseek-chat） |
| MAIL_SMTP_HOST | `MAIL_SMTP_HOST` | SMTP 服务器 |
| MAIL_SMTP_PORT | `MAIL_SMTP_PORT` | 端口（465 SSL / 587 STARTTLS） |
| MAIL_SMTP_USER | `MAIL_SMTP_USER` | SMTP 用户名 |
| MAIL_SMTP_PASS | `MAIL_SMTP_PASS` | SMTP 密码/授权码 |
| MAIL_FROM | `MAIL_FROM` | 发信地址 |

**SMTP 不配置时**：验证码直接显示在 send-code 的响应里（仅开发模式），生产环境必须配。

## 关于 DeepSeek API Key

**重要：Key 由用户自己填，后端不存储。**

- 用户在前端「⚙ 设置」里粘贴自己的 DeepSeek API Key
- Key 存在浏览器 `localStorage`
- 每次提问时通过请求体传给后端，后端用完即丢，不落库、不写日志
- 这样部署者无需为所有用户承担 API 费用，每个用户用自己的额度

## 安全提示

1. **生产环境必改 `SECRET_KEY`**，否则 session 可被伪造
2. **生产环境必配 SMTP**，否则任何人能看到验证码
3. `data/` 目录通过 Apache/Nginx 配置禁止外部访问（SQLite 里有用户密码哈希）
4. SQLite 文件定期备份
