#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
海龟汤馆 · FTP 部署脚本

用法：
  1. 把本文件放到项目根目录（与 index.php 同级）
  2. 运行：python3 deploy.py

脚本会自动把项目文件递归上传到 FTP 的
  /home/hgt/web/hgt.dzol.vip/public_html/
"""

import os
import sys
import stat
from ftplib import FTP, error_perm

# ===================== 配置（从环境变量读取，请勿在此填写凭据） =====================
FTP_HOST = os.environ.get("FTP_HOST", "")
FTP_PORT = int(os.environ.get("FTP_PORT", "21"))
FTP_USER = os.environ.get("FTP_USER", "")
FTP_PASS = os.environ.get("FTP_PASS", "")

# FTP 远程目标目录（站点根目录）
REMOTE_DIR = "/home/hgt/web/hgt.dzol.vip/public_html"

# 本地项目根目录（脚本所在目录）
LOCAL_DIR = os.path.dirname(os.path.abspath(__file__))

# 需要排除的文件/目录名
EXCLUDE_NAMES = {
    ".git", ".github", ".gitignore", ".DS_Store", "Thumbs.db",
    "node_modules", "__pycache__", ".vscode", ".idea",
    "deploy.py", "DEPLOY.md",
    # 运行时生成的数据库（避免覆盖线上数据）
    "haiguitang.db", "haiguitang.db-wal", "haiguitang.db-shm",
}

# 二进制文件后缀（用 STOR/BINARY 上传）
BINARY_EXTS = {".png", ".jpg", ".jpeg", ".gif", ".ico", ".woff", ".woff2", ".ttf", ".eot", ".svg", ".zip"}


def is_excluded(name):
    """判断文件/目录是否应排除"""
    return name in EXCLUDE_NAMES


def ensure_remote_dir(ftp, path):
    """递归创建远程目录（类似 mkdir -p）"""
    if not path or path == "/" or path == ".":
        return
    parent = os.path.dirname(path)
    ensure_remote_dir(ftp, parent)
    try:
        ftp.cwd(path)
    except error_perm:
        ftp.mkd(path)
        ftp.cwd(path)


def upload_file(ftp, local_path, remote_name):
    """上传单个文件"""
    ext = os.path.splitext(local_path)[1].lower()
    if ext in BINARY_EXTS:
        with open(local_path, "rb") as f:
            ftp.storbinary(f"STOR {remote_name}", f)
    else:
        with open(local_path, "rb") as f:
            ftp.storbinary(f"STOR {remote_name}", f)


def deploy():
    print("=" * 56)
    print("  海龟汤馆 · FTP 部署")
    print("=" * 56)
    print(f"  本地目录 : {LOCAL_DIR}")
    print(f"  FTP 服务器: {FTP_HOST}:{FTP_PORT}")
    print(f"  远程目录  : {REMOTE_DIR}")
    print("-" * 56)

    if not FTP_HOST or not FTP_USER or not FTP_PASS:
        print("错误: 请设置环境变量 FTP_HOST, FTP_USER, FTP_PASS")
        print("用法: FTP_HOST=host FTP_USER=user FTP_PASS=pass python3 deploy.py")
        sys.exit(1)

    # 连接 FTP
    print("[1/4] 连接 FTP 服务器...", end=" ", flush=True)
    try:
        ftp = FTP()
        ftp.connect(FTP_HOST, FTP_PORT, timeout=30)
        ftp.login(FTP_USER, FTP_PASS)
        ftp.set_pasv(True)
        print("OK")
    except Exception as e:
        print(f"失败\n错误: {e}")
        sys.exit(1)

    # 切换到目标目录
    print("[2/4] 切换到目标目录...", end=" ", flush=True)
    try:
        ensure_remote_dir(ftp, REMOTE_DIR)
        ftp.cwd(REMOTE_DIR)
        print(f"OK ({REMOTE_DIR})")
    except Exception as e:
        print(f"失败\n错误: {e}")
        sys.exit(1)

    # 收集本地文件
    print("[3/4] 扫描本地文件...", end=" ", flush=True)
    files_to_upload = []
    for root, dirs, files in os.walk(LOCAL_DIR):
        # 过滤排除项
        dirs[:] = [d for d in dirs if not is_excluded(d)]
        files = [f for f in files if not is_excluded(f)]

        for fname in files:
            local_path = os.path.join(root, fname)
            rel_path = os.path.relpath(local_path, LOCAL_DIR)
            rel_path = rel_path.replace(os.sep, "/")
            files_to_upload.append((local_path, rel_path))
    print(f"共 {len(files_to_upload)} 个文件")

    # 上传
    print("[4/4] 开始上传...")
    print("-" * 56)
    success = 0
    failed = 0
    for i, (local_path, rel_path) in enumerate(files_to_upload, 1):
        remote_dir = os.path.dirname(rel_path)
        remote_name = os.path.basename(rel_path)

        # 切换到文件所在远程目录
        ftp.cwd(REMOTE_DIR)
        if remote_dir:
            for part in remote_dir.split("/"):
                if part and part != ".":
                    try:
                        ftp.cwd(part)
                    except error_perm:
                        ftp.mkd(part)
                        ftp.cwd(part)

        pct = i * 100 // len(files_to_upload)
        status_mark = "  " if failed == 0 else "⚠ "
        print(f"\r{status_mark}[{i}/{len(files_to_upload)}] {pct:3d}%  {rel_path}", end="", flush=True)
        try:
            upload_file(ftp, local_path, remote_name)
            success += 1
        except Exception as e:
            print(f"\n  ✗ 失败: {rel_path} -> {e}")
            failed += 1

    print("\n" + "-" * 56)
    print(f"  上传完成：成功 {success}，失败 {failed}")
    print("=" * 56)

    # 验证：列出远程根目录
    print("\n远程目录文件列表：")
    try:
        ftp.cwd(REMOTE_DIR)
        ftp.dir()
    except Exception:
        pass

    ftp.quit()
    print("\n部署结束。请访问 http://hgt.dzol.vip/ 验证。")

    if failed > 0:
        sys.exit(1)


if __name__ == "__main__":
    deploy()
