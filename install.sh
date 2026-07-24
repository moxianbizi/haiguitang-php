#!/bin/bash
# ============================================================
# 海龟汤馆 · 一键部署脚本
# 在服务器上执行：bash install.sh
# ============================================================
set -e

# 颜色
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${CYAN}====================================================${NC}"
echo -e "${CYAN}  海龟汤馆 · 自动部署${NC}"
echo -e "${CYAN}====================================================${NC}"

# ---------- 1. 检测站点根目录 ----------
DEPLOY_DIR=""
CANDIDATES=(
    "/home/hgt/web/hgt.dzol.vip/public_html"
    "$HOME/web/hgt.dzol.vip/public_html"
    "$HOME/public_html"
    "/var/www/html"
)

for d in "${CANDIDATES[@]}"; do
    if [ -d "$d" ]; then
        DEPLOY_DIR="$d"
        break
    fi
done

if [ -z "$DEPLOY_DIR" ]; then
    echo -e "${RED}错误：找不到网站根目录${NC}"
    echo "尝试过以下路径："
    for d in "${CANDIDATES[@]}"; do echo "  - $d"; done
    echo ""
    echo "请手动指定：bash install.sh /path/to/public_html"
    exit 1
fi

# 如果有参数，用参数覆盖
if [ -n "$1" ]; then
    DEPLOY_DIR="$1"
fi

echo -e "${GREEN}站点目录: ${DEPLOY_DIR}${NC}"

# ---------- 2. 检测 PHP ----------
echo -e "\n${YELLOW}[1/6] 检测 PHP...${NC}"
if ! command -v php &>/dev/null; then
    echo -e "${RED}错误：未找到 PHP，请先安装 PHP 7.4+${NC}"
    exit 1
fi
PHP_VER=$(php -r 'echo PHP_VERSION;')
echo "  PHP 版本: ${PHP_VER}"
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')
if [ "$PHP_MAJOR" -lt 7 ] || ([ "$PHP_MAJOR" -eq 7 ] && [ "$PHP_MINOR" -lt 4 ]); then
    echo -e "${RED}错误：需要 PHP 7.4+，当前 ${PHP_VER}${NC}"
    exit 1
fi
echo -e "  ${GREEN}OK${NC}"

# ---------- 3. 检查扩展 ----------
echo -e "\n${YELLOW}[2/6] 检查 PHP 扩展...${NC}"
MISSING=0

if php -m | grep -qi 'pdo_sqlite'; then
    echo -e "  pdo_sqlite: ${GREEN}OK${NC}"
else
    echo -e "  pdo_sqlite: ${RED}缺失！${NC}"
    MISSING=1
fi

if php -m | grep -qi 'curl'; then
    echo -e "  curl: ${GREEN}OK${NC}"
else
    echo -e "  curl: ${RED}缺失（AI 功能需要）${NC}"
fi

if php -m | grep -qi 'mbstring'; then
    echo -e "  mbstring: ${GREEN}OK${NC}"
else
    echo -e "  mbstring: ${RED}缺失${NC}"
    MISSING=1
fi

if php -m | grep -qi 'json'; then
    echo -e "  json: ${GREEN}OK${NC}"
else
    echo -e "  json: ${RED}缺失${NC}"
    MISSING=1
fi

if [ "$MISSING" -eq 1 ]; then
    echo -e "\n${RED}缺少必需扩展，请安装后重试。${NC}"
    echo "常见安装命令："
    echo "  apt install php-pdo-sqlite php-curl php-mbstring php-json"
    exit 1
fi

# ---------- 4. 下载代码 ----------
echo -e "\n${YELLOW}[3/6] 下载代码...${NC}"
cd "$DEPLOY_DIR"

# 如果已有 .git，更新；否则 clone
if [ -d ".git" ]; then
    echo "  已有 git 仓库，拉取最新..."
    git pull origin master || true
else
    echo "  克隆仓库..."
    git clone https://github.com/moxianbizi/haiguitang-php.git /tmp/hgt-deploy 2>/dev/null || true
    if [ -d /tmp/hgt-deploy ]; then
        # 复制文件（保留已有 data/ 下的数据库）
        cp -rn /tmp/hgt-deploy/* ./
        cp -rn /tmp/hgt-deploy/.htaccess ./ 2>/dev/null || true
        cp -rn /tmp/hgt-deploy/.gitignore ./ 2>/dev/null || true
        rm -rf /tmp/hgt-deploy
    else
        echo -e "${RED}克隆失败，请检查网络或手动下载${NC}"
        exit 1
    fi
fi

# ---------- 5. 设置权限 ----------
echo -e "\n${YELLOW}[4/6] 设置目录权限...${NC}"

# 确保数据目录存在
mkdir -p data/soups

# 当前用户
CUR_USER=$(whoami)
CUR_GROUP=$(id -gn)

# 设置属主（如果是 root，尝试改为 web 用户）
WEB_USER=""
if [ "$CUR_USER" = "root" ]; then
    # 常见 web 用户
    for wu in www-data apache nginx nobody; do
        if id "$wu" &>/dev/null 2>&1; then
            WEB_USER="$wu"
            break
        fi
    done
fi

if [ -n "$WEB_USER" ]; then
    echo "  设置属主: ${WEB_USER}"
    chown -R "$WEB_USER":"$WEB_USER" .
else
    echo "  当前用户: ${CUR_USER}（非 root，跳过 chown）"
fi

# 权限
chmod -R 775 data/
chmod -R 775 data/soups/
chmod 664 data/*.db 2>/dev/null || true
chmod 644 .htaccess
chmod 644 index.php config.php db.php
chmod -R 644 api/ lib/ frontend/
chmod 755 api/ lib/ frontend/ frontend/css/ frontend/js/

echo -e "  data/ 可写: $([ -w data ] && echo '${GREEN}yes${NC}' || echo '${RED}no${NC}')"
echo -e "  data/soups/ 可写: $([ -w data/soups ] && echo '${GREEN}yes${NC}' || echo '${RED}no${NC}')"

# ---------- 6. 检查 session 目录 ----------
echo -e "\n${YELLOW}[5/6] 检查 session 配置...${NC}"
SESSION_PATH=$(php -r 'echo sys_get_temp_dir();')
echo "  系统临时目录: ${SESSION_PATH}"
if [ -w "$SESSION_PATH" ]; then
    echo -e "  可写: ${GREEN}OK${NC}"
else
    echo -e "  ${YELLOW}警告：系统临时目录不可写，session 可能失败${NC}"
    echo "  建议在站点下创建 tmp/ 目录并设置 session.save_path"
fi

# ---------- 7. 语法检查 ----------
echo -e "\n${YELLOW}[6/6] PHP 语法检查...${NC}"
SYNTAX_OK=1
for f in index.php config.php db.php api/*.php lib/*.php; do
    if php -l "$f" 2>/dev/null | grep -q "No syntax errors"; then
        :
    else
        echo -e "  ${RED}语法错误: $f${NC}"
        php -l "$f"
        SYNTAX_OK=0
    fi
done
if [ "$SYNTAX_OK" -eq 1 ]; then
    echo -e "  ${GREEN}全部通过${NC}"
fi

# ---------- 完成 ----------
echo -e "\n${CYAN}====================================================${NC}"
echo -e "${GREEN}  部署完成！${NC}"
echo -e "${CYAN}====================================================${NC}"
echo ""
echo "  访问地址: http://hgt.dzol.vip/"
echo ""
echo "  如果仍有 500："
echo "    1. 访问 http://hgt.dzol.vip/api/health 查看错误详情"
echo "    2. 检查 data/ 目录权限：ls -la data/"
echo "    3. 检查 Apache 错误日志：tail -f logs/hgt.dzol.vip.error.log"
echo "    4. 确保 Apache 启用了 mod_rewrite"
echo ""
