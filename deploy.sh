#!/bin/bash
# ============================================================
# 海龟汤馆 · 一键部署脚本（全自动版）
# 用法（任选一种）：
#   curl -sS https://raw.githubusercontent.com/moxianbizi/haiguitang-php/master/deploy.sh | bash
#   或下载后：bash deploy.sh
#
# 支持系统：Ubuntu/Debian/CentOS/Alpine
# ============================================================
set -e

# ============ 颜色 ============
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; NC='\033[0m'

echo -e "${CYAN}"
echo "╔══════════════════════════════════════════════╗"
echo "║        海龟汤馆 · 一键部署                   ║"
echo "║        haiguitang-php                        ║"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"

# ============ 配置（可修改） ============
INSTALL_DIR="/var/www/haiguitang"
NGINX_CONF="/etc/nginx/conf.d/haiguitang.conf"
NGINX_CONF_DIR="/etc/nginx/conf.d"
DB_PATH="$INSTALL_DIR/data/haiguitang.db"
ADMIN_USER="admin"
ADMIN_PASS="mlp09876"
ADMIN_EMAIL="admin@local"
GIT_REPO="https://github.com/moxianbizi/haiguitang-php.git"
API_TOKEN="k202607251359zjtbkx"
SECRET_KEY="hgt_auto_$(date +%s)_$(shuf -i 1000-9999 -n 1)"

# ============ 检测系统 ============
detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
        OS_VER=$VERSION_ID
    elif command -v lsb_release &>/dev/null; then
        OS=$(lsb_release -si | tr '[:upper:]' '[:lower:]')
    else
        OS=$(uname -s)
    fi
    echo -e "${GREEN}系统: $OS $OS_VER${NC}"
}

# ============ 安装依赖 ============
install_deps() {
    echo -e "\n${YELLOW}[1/6] 安装依赖...${NC}"
    
    if command -v apk &>/dev/null; then
        # Alpine Linux
        apk update -q
        apk add nginx php84 php84-fpm php84-pdo_sqlite php84-sqlite3 \
                php84-curl php84-openssl php84-mbstring php84-session \
                php84-json php84-ctype php84-gd git curl wget \
                php84-pdo php84-posix
        # 设置别名
        if [ ! -f /usr/bin/php ]; then
            ln -sf /usr/bin/php84 /usr/bin/php
        fi
        PHP_FPM="php-fpm84"
        FPM_CONF="/etc/php84/php-fpm.conf"
        WWW_CONF="/etc/php84/php-fpm.d/www.conf"
        NGINX_CONF_DIR="/etc/nginx/http.d"
        NGINX_CONF="$NGINX_CONF_DIR/haiguitang.conf"
        
    elif command -v apt &>/dev/null; then
        # Debian/Ubuntu
        apt update -qq
        apt install -y -qq nginx php-fpm php-pdo-sqlite php-sqlite3 \
                       php-curl php-mbstring php-xml php-json php-ctype \
                       php-gd git curl wget sqlite3
        PHP_FPM=$(ls /etc/init.d/php*-fpm 2>/dev/null | head -1 | xargs basename 2>/dev/null || echo "php8.2-fpm")
        FPM_CONF="/etc/php/*/fpm/php-fpm.conf"
        NGINX_CONF_DIR="/etc/nginx/conf.d"
        NGINX_CONF="$NGINX_CONF_DIR/haiguitang.conf"
        
    elif command -v yum &>/dev/null; then
        # CentOS/RHEL
        yum install -y epel-release
        yum install -y nginx php-fpm php-pdo php-sqlite3 php-mbstring \
                       php-curl php-json php-gd php-xml git wget
        PHP_FPM="php-fpm"
        NGINX_CONF_DIR="/etc/nginx/conf.d"
        NGINX_CONF="$NGINX_CONF_DIR/haiguitang.conf"
    else
        echo -e "${RED}不支持的包管理器，请手动安装 PHP 8.0+ / nginx / git${NC}"
        exit 1
    fi
    
    # 验证 PHP
    PHP_VER=$(php -r 'echo PHP_VERSION;' 2>/dev/null)
    echo -e "  PHP: ${GREEN}${PHP_VER}${NC}"
    echo -e "  nginx: $(nginx -v 2>&1 | grep -oP '[\d.]+')"
}

# ============ 克隆代码 ============
clone_code() {
    echo -e "\n${YELLOW}[2/6] 下载项目代码...${NC}"
    
    if [ -d "$INSTALL_DIR" ]; then
        echo "  目录已存在，备份数据..."
        if [ -f "$DB_PATH" ]; then
            cp "$DB_PATH" /tmp/haiguitang.db.bak
            echo "  数据库已备份到 /tmp/haiguitang.db.bak"
        fi
        rm -rf "$INSTALL_DIR"
    fi
    
    mkdir -p "$INSTALL_DIR"
    cd /tmp
    git clone --depth 1 "$GIT_REPO" hgt-deploy 2>/dev/null || {
        echo -e "${RED}克隆失败，尝试备用方式...${NC}"
        wget -q -O hgt.zip "https://github.com/moxianbizi/haiguitang-php/archive/refs/heads/master.zip"
        unzip -q hgt.zip
        mv haiguitang-php-master/* hgt-deploy/ 2>/dev/null || true
        mv haiguitang-php-master/.* hgt-deploy/ 2>/dev/null || true
        rm -rf hgt.zip haiguitang-php-master
    }
    
    if [ -d /tmp/hgt-deploy ]; then
        # 排除 .git 复制所有文件
        find /tmp/hgt-deploy -maxdepth 1 -not -name .git -not -name . -exec cp -r {} "$INSTALL_DIR/" \; 2>/dev/null || true
        cp /tmp/hgt-deploy/.htaccess "$INSTALL_DIR/" 2>/dev/null || true
        cp /tmp/hgt-deploy/.gitignore "$INSTALL_DIR/" 2>/dev/null || true
        rm -rf /tmp/hgt-deploy
    fi
    
    # 恢复数据库
    if [ -f /tmp/haiguitang.db.bak ]; then
        mkdir -p "$INSTALL_DIR/data"
        mv /tmp/haiguitang.db.bak "$DB_PATH"
        echo "  数据库已恢复"
    fi
    
    # 添加 .git 以便 tool.php 使用 git pull
    cd "$INSTALL_DIR"
    git init -q
    git remote add origin "$GIT_REPO"
    git fetch origin master -q 2>/dev/null || true
    git add -A && git commit -m "deploy" -q 2>/dev/null || true
    
    echo -e "  代码已部署到 ${GREEN}$INSTALL_DIR${NC}"
}

# ============ 配置配置项 ============
write_config() {
    echo -e "\n${YELLOW}[3/6] 写入配置...${NC}"
    
    # 修改 config.php
    cd "$INSTALL_DIR"
    sed -i "s/public static \$SECRET_KEY = '';/public static \$SECRET_KEY = '$SECRET_KEY';/" config.php
    sed -i "s/public static \$ADMIN_API_TOKEN = '';/public static \$ADMIN_API_TOKEN = '$API_TOKEN';/" config.php
    
    # 创建 nginx 配置
    cat > "$NGINX_CONF" << 'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name _;

    root /var/www/haiguitang;
    index index.php;

    # 安全头
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options SAMEORIGIN;
    add_header X-XSS-Protection "1; mode=block";

    location / {
        try_files $uri $uri/ /index.php;
    }

    location ~ \.php$ {
        fastcgi_pass   127.0.0.1:9000;
        fastcgi_index  index.php;
        include        fastcgi.conf;
        fastcgi_param  SCRIPT_FILENAME  $document_root$fastcgi_script_name;
    }

    # 保护 data 目录
    location ~ ^/data/  { deny all; }

    # 保护 .git 和配置文件
    location ~ /\.(git|env|htaccess) { deny all; }

    # 静态文件缓存
    location /frontend/ {
        try_files $uri $uri/ =404;
        expires 7d;
        add_header Cache-Control "public, immutable";
    }
}
NGINX

    echo -e "  nginx 配置: ${GREEN}$NGINX_CONF${NC}"
}

# ============ 设置权限 ============
setup_permissions() {
    echo -e "\n${YELLOW}[4/6] 设置权限...${NC}"
    
    # 检测 web 用户
    WEB_USER="nobody"
    for w in www-data nginx apache nobody; do
        if id "$w" &>/dev/null 2>&1; then
            WEB_USER="$w"; break
        fi
    done
    
    echo "  Web 用户: $WEB_USER"
    
    mkdir -p "$INSTALL_DIR/data"
    chown -R "$WEB_USER":"$WEB_USER" "$INSTALL_DIR"
    find "$INSTALL_DIR" -type f -exec chmod 644 {} \;
    find "$INSTALL_DIR" -type d -exec chmod 755 {} \;
    chmod 775 "$INSTALL_DIR/data"
    
    # 确保 php-fpm 的 www.conf 用户匹配
    if [ -f /etc/php84/php-fpm.d/www.conf ]; then
        sed -i "s/^user = .*/user = $WEB_USER/" /etc/php84/php-fpm.d/www.conf
        sed -i "s/^group = .*/group = $WEB_USER/" /etc/php84/php-fpm.d/www.conf
    elif ls /etc/php*/fpm/pool.d/www.conf &>/dev/null; then
        POOL=$(ls /etc/php*/fpm/pool.d/www.conf 2>/dev/null | head -1)
        [ -n "$POOL" ] && sed -i "s/^user = .*/user = $WEB_USER/" "$POOL"
        [ -n "$POOL" ] && sed -i "s/^group = .*/group = $WEB_USER/" "$POOL"
    fi
    
    echo -e "  权限: ${GREEN}OK${NC}"
}

# ============ 启动服务 ============
start_services() {
    echo -e "\n${YELLOW}[5/6] 启动服务...${NC}"
    
    # 启动 php-fpm
    if command -v rc-service &>/dev/null; then
        rc-service php-fpm84 restart 2>/dev/null || rc-service php-fpm restart 2>/dev/null || true
        rc-service nginx restart 2>/dev/null || true
    elif command -v systemctl &>/dev/null; then
        systemctl restart php*-fpm 2>/dev/null || true
        systemctl restart nginx 2>/dev/null || true
    elif command -v service &>/dev/null; then
        service php*-fpm restart 2>/dev/null || true
        service nginx restart 2>/dev/null || true
    fi
    
    # 测试 nginx
    nginx -t 2>&1 | grep -q successful && echo -e "  nginx: ${GREEN}配置正确${NC}" || echo -e "  ${RED}nginx 配置有误${NC}"
    
    sleep 1
    
    # 测试 PHP
    HTTP_CODE=$(wget -q -O /dev/null -w "%{http_code}" http://localhost/api/health 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        echo -e "  PHP: ${GREEN}正常${NC}"
    else
        echo -e "  ${YELLOW}等待服务就绪...${NC}"
        sleep 2
    fi
}

# ============ 初始化数据 ============
init_data() {
    echo -e "\n${YELLOW}[6/6] 初始化数据...${NC}"
    
    # 等待服务就绪
    sleep 1
    
    # 导入汤题并创建管理员
    php -r "
        require '$INSTALL_DIR/config.php';
        require '$INSTALL_DIR/db.php';
        DB::pdo();
        \$pdo = DB::pdo();
        
        // 导入汤题
        require '$INSTALL_DIR/lib/md.php';
        \$dir = '$INSTALL_DIR/data/soups';
        if (is_dir(\$dir)) {
            \$files = array_filter(scandir(\$dir), fn(\$f) => str_ends_with(\$f, '.md'));
            sort(\$files, SORT_NATURAL | SORT_FLAG_CASE);
            \$stmt = \$pdo->prepare('INSERT OR IGNORE INTO soups (filename, season, episode, title, surface, base, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach (\$files as \$idx => \$f) {
                \$content = file_get_contents(\"\$dir/\$f\");
                \$p = parse_md(\$f, \$content);
                \$stmt->execute([\$p['filename'], \$p['season'], \$p['episode'], \$p['title'], \$p['surface'], \$p['base'], \$idx]);
            }
        }
        
        // 创建管理员
        \$hash = password_hash('$ADMIN_PASS', PASSWORD_DEFAULT);
        try {
            \$pdo->exec(\"INSERT INTO users (username, email, password_hash, is_admin) VALUES ('$ADMIN_USER', '$ADMIN_EMAIL', '\$hash', 1)\");
            echo \"  管理员: $ADMIN_USER / $ADMIN_PASS\\n\";
        } catch (Exception \$e) {
            echo \"  管理员已存在\\n\";
        }
        
        // 统计
        \$soupCount = \$pdo->query('SELECT COUNT(*) FROM soups')->fetchColumn();
        echo \"  汤题: \$soupCount 个\\n\";
    " 2>&1
    
    # 配置 git safe.directory
    git config --global --add safe.directory "$INSTALL_DIR" 2>/dev/null || true
    cd "$INSTALL_DIR" && git add -A && git commit -m "deploy with config" -q 2>/dev/null || true
}

# ============ 完成 ============
show_result() {
    IP=$(curl -s http://ip.sb 2>/dev/null || wget -qO- http://ip.sb 2>/dev/null || hostname -I | awk '{print $1}')
    
    echo -e "\n${CYAN}╔══════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║          🎉 部署完成！                       ║${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "  访问地址: ${GREEN}http://$IP/${NC}"
    echo -e "  运维工具: ${GREEN}http://$IP/tool.php${NC}"
    echo ""
    echo -e "  管理员账号:"
    echo -e "    用户名: ${YELLOW}$ADMIN_USER${NC}"
    echo -e "    密码:   ${YELLOW}$ADMIN_PASS${NC}"
    echo -e "    Token:  ${YELLOW}$API_TOKEN${NC}"
    echo ""
    echo -e "  安装目录: $INSTALL_DIR"
    echo -e "  数据库:   $DB_PATH"
    echo ""
    echo -e "${YELLOW}提示: 如需修改管理员密码，登录后台设置即可${NC}"
    echo -e "${YELLOW}      或在 tool.php 中使用运维功能更新代码${NC}"
}

# ============ 入口 ============
detect_os
install_deps
clone_code
write_config
setup_permissions
start_services
init_data
show_result
