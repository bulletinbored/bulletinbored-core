<?php

/**
 * Email sending (SMTP and PHP mail()).
 */

function send_email($to, $subject, $body) {
    $config = App::getInstance()->config;
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$config['mail_from_name']} <{$config['mail_from']}>\r\n";
    $headers .= "X-Mailer: bulletinbored/1.0\r\n";

    $siteLogoHtml = '';
    if (!empty($config['site_logo'])) {
        $siteLogoHtml = '<img src="' . escape($config['site_logo']) . '" alt="" style="max-height:40px; margin-bottom:10px;"><br>';
    }
    $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: Arial, sans-serif; background: #f8f9fc; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #550296, #3d046f); color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; }
        .footer { background: #f8f9fc; padding: 15px; text-align: center; font-size: 12px; color: #666; }
        .btn { display: inline-block; padding: 10px 20px; background: #550296; color: white; text-decoration: none; border-radius: 5px; }
    </style></head><body>
    <div class="container">
        <div class="header">' . $siteLogoHtml . '<h2 style="margin:0;">' . render_site_name($config['site_name'] ?? 'bulletinbored') . '</h2></div>
        <div class="content">' . $body . '</div>
        <div class="footer">&copy; ' . date('Y') . ' ' . render_site_name($config['site_name'] ?? 'bulletinbored') . '</div>
    </div></body></html>';

    $envelope = '-f' . ($config['mail_from'] ?? '');
    if ($config['mail_method'] === 'smtp') {
        $host = $config['mail_host'] ?? 'localhost';
        $port = (int)($config['mail_port'] ?? 25);
        $username = $config['mail_username'] ?? '';
        $password = $config['mail_password'] ?? '';
        $secure = strtolower($config['mail_secure'] ?? '');
        $timeout = (int)($config['mail_timeout'] ?? 10);

        $connectHost = $secure === 'ssl' ? 'ssl://' . $host : $host;

        $fp = @fsockopen($connectHost, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            error_log("SMTP connect failed: {$errstr} ({$errno})");
            return false;
        }

        stream_set_timeout($fp, $timeout);

        $readResponse = function($fp) {
            $response = '';
            while (($line = fgets($fp, 515)) !== false) {
                $response .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $response;
        };

        $sendCommand = function($fp, $command) {
            fwrite($fp, $command . "\r\n");
        };

        $readResponse($fp);
        $sendCommand($fp, 'EHLO ' . (php_uname('n') ?: 'localhost'));
        $readResponse($fp);

        if ($secure === 'tls') {
            $sendCommand($fp, 'STARTTLS');
            $readResponse($fp);
            if (stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $sendCommand($fp, 'EHLO ' . (php_uname('n') ?: 'localhost'));
                $readResponse($fp);
            }
        }

        if ($username !== '' && $password !== '') {
            $sendCommand($fp, 'AUTH LOGIN');
            $readResponse($fp);
            $sendCommand($fp, base64_encode($username));
            $readResponse($fp);
            $sendCommand($fp, base64_encode($password));
            $readResponse($fp);
        }

        $sendCommand($fp, 'MAIL FROM:<' . $config['mail_from'] . '>');
        $readResponse($fp);
        $sendCommand($fp, 'RCPT TO:<' . $to . '>');
        $readResponse($fp);
        $sendCommand($fp, 'DATA');
        $readResponse($fp);

        fwrite($fp, "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n");
        fwrite($fp, "From: {$config['mail_from_name']} <{$config['mail_from']}>\r\n");
        fwrite($fp, "To: {$to}\r\n");
        fwrite($fp, "MIME-Version: 1.0\r\n");
        fwrite($fp, "Content-Type: text/html; charset=UTF-8\r\n");
        fwrite($fp, "\r\n");
        fwrite($fp, $htmlBody . "\r\n");
        fwrite($fp, ".\r\n");
        $readResponse($fp);

        $sendCommand($fp, 'QUIT');
        $readResponse($fp);
        fclose($fp);
        return true;
    }
    return @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $htmlBody, $headers, $envelope);
}
