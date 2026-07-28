<?php
/** 邮件发送 + 验证码 */

/**
 * 发送验证码到邮箱
 * 返回 [success, msg, token]
 * token 为签名 token，注册时连同验证码一起回传用于校验
 */
function send_verification_code(string $email): array {
    $code = gen_code(6);
    $token = sign_code($email, $code);

    if (!Config::$MAIL_SMTP_HOST) {
        // 开发模式：直接返回验证码
        return [false, "SMTP 未配置，验证码为: {$code}（仅开发模式）", $token];
    }

    $subject = '海龟汤馆 - 验证码';
    $html = <<<HTML
<div style="font-family:sans-serif;max-width:480px;margin:auto">
  <h2 style="color:#6ee7ff">海龟汤馆</h2>
  <p>你的注册验证码是：</p>
  <p style="font-size:2rem;font-weight:bold;letter-spacing:.2em;color:#6ee7ff">{$code}</p>
  <p style="color:#888">验证码 10 分钟内有效，请勿泄露给他人。</p>
</div>
HTML;

    try {
        $host = Config::$MAIL_SMTP_HOST;
        $port = Config::$MAIL_SMTP_PORT;
        $user = Config::$MAIL_SMTP_USER;
        $pass = Config::$MAIL_SMTP_PASS;
        $from = Config::$MAIL_FROM ?: $user;

        // 用 fsockopen + STARTTLS 简化实现（避免依赖 Mail 扩展）
        // 这里优先用 PHP 内置的 SMTP 通信
        $sent = smtp_send($host, $port, $user, $pass, $from, $email, $subject, $html);
        if ($sent) return [true, '验证码已发送', $token];
        return [false, '邮件发送失败', $token];
    } catch (Throwable $e) {
        return [false, '邮件发送失败: ' . $e->getMessage(), $token];
    }
}

/** 极简 SMTP 客户端（支持 SSL 直连 465 或 STARTTLS 587） */
function smtp_send(string $host, int $port, string $user, string $pass, string $from, string $to, string $subject, string $body): bool {
    // OpenSSL 扩展检查（465/587 都需要）
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('PHP 未启用 openssl 扩展，无法发送加密邮件（465/587 都需要）');
    }
    if ($user === '' || $pass === '') {
        throw new RuntimeException('SMTP 账号或密码为空');
    }

    $ssl = ($port == 465);
    $remote = ($ssl ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        // 给出可操作的诊断信息：连接失败最常见原因是云厂商封端口 / 防火墙
        throw new RuntimeException(
            "连接 {$host}:{$port} 失败：{$errstr}（errno={$errno}）\n" .
            "常见原因：\n" .
            "1. 云服务器（阿里云/腾讯云/AWS）默认封禁 25/465/587 出口端口，需在控制台申请解封\n" .
            "2. 服务器防火墙（iptables/ufw/安全组）未放行出站\n" .
            "3. SMTP 主机或端口填错\n" .
            "可在服务器上执行：nc -zv {$host} {$port}  验证连通性"
        );
    }

    // 当前步骤名，出错时附带进异常信息便于定位
    $step = 'connect';
    try {
        $read = function() use ($fp): string {
            $data = '';
            while ($line = fgets($fp, 4096)) {
                $data .= $line;
                if (substr($line, 3, 1) === ' ') break; // SMTP 状态码后空格表示一行结束
            }
            return $data;
        };
        $write = function(string $cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); };
        $expect = function(string $code) use ($read, &$step): string {
            $resp = $read();
            if (!str_starts_with($resp, $code)) {
                throw new RuntimeException("SMTP [{$step}] 期望 {$code}，服务器返回：" . trim($resp));
            }
            return $resp;
        };

        $step = 'banner';        $expect('220');
        $step = 'EHLO';          $write('EHLO haiguitang.local');
        $ehlo = $expect('250');

        if (!$ssl && str_contains($ehlo, 'STARTTLS')) {
            $step = 'STARTTLS';   $write('STARTTLS');
            $expect('220');
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $step = 'EHLO(TLS)';  $write('EHLO haiguitang.local');
            $expect('250');
        }

        $step = 'AUTH LOGIN';    $write('AUTH LOGIN');
        $expect('334');
        $step = 'AUTH user';     $write(base64_encode($user));
        $expect('334');
        $step = 'AUTH pass';     $write(base64_encode($pass));
        $expect('235');

        $step = 'MAIL FROM';     $write('MAIL FROM:<' . $from . '>');
        $expect('250');
        $step = 'RCPT TO';       $write('RCPT TO:<' . $to . '>');
        $expect('250');
        $step = 'DATA';          $write('DATA');
        $expect('354');

        $headers = [
            'From: <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $msg = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
        $write($msg);
        $write('.');
        $step = 'DATA end';      $expect('250');

        $step = 'QUIT';          $write('QUIT');
        return true;
    } finally {
        // 无论成功失败都关闭句柄，避免资源泄漏
        if (is_resource($fp)) fclose($fp);
    }
}
