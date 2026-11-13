<?php

/**
 * Email sending (SMTP and PHP mail()).
 *
 * The SMTP client is intentionally dependency-free (shared hosting often has no
 * Composer), but it follows the protocol instead of hoping for the best: every
 * server reply is read completely — including multi-line replies — and its
 * status code is checked before the next command is sent. A rejected
 * recipient, a refused message body or an expired timeout all make send_email()
 * return false instead of reporting a delivery that never happened.
 */

/**
 * Read one complete SMTP reply.
 *
 * SMTP replies can span several lines ("250-first\r\n250 second\r\n"): the code
 * is repeated on each line and the final line uses a space after the code.
 * Returns ['code' => int, 'text' => string] or null on I/O error, timeout or a
 * malformed reply.
 */
function bb_smtp_read_response($fp, int $timeout): ?array
{
    $text = '';
    $code = null;
    $deadline = microtime(true) + max(1, $timeout);

    while (true) {
        $line = fgets($fp, 2048);
        if ($line === false) {
            return null;
        }

        $meta = stream_get_meta_data($fp);
        if (!empty($meta['timed_out']) || !empty($meta['eof'])) {
            return null;
        }

        $text .= $line;
        // A server must not send megabytes of reply text; treat that as a
        // protocol error instead of buffering forever.
        if (strlen($text) > 65536) {
            return null;
        }

        if (!preg_match('/^(\d{3})([ -])(.*)$/s', rtrim($line, "\r\n"), $m)) {
            return null;
        }

        $code = (int)$m[1];

        if ($m[2] === ' ') {
            return ['code' => $code, 'text' => rtrim($text, "\r\n")];
        }

        if (microtime(true) > $deadline) {
            return null;
        }
    }
}

/**
 * Send one SMTP command and assert the expected reply code(s).
 * Returns the reply array, or null when the server refuses or times out.
 */
function bb_smtp_command($fp, string $command, array $expected, int $timeout, string $stage): ?array
{
    if (@fwrite($fp, $command . "\r\n") !== strlen($command) + 2) {
        error_log("SMTP {$stage}: write failed");
        return null;
    }

    $response = bb_smtp_read_response($fp, $timeout);
    if ($response === null) {
        error_log("SMTP {$stage}: no/invalid response");
        return null;
    }

    if (!in_array($response['code'], $expected, true)) {
        error_log("SMTP {$stage}: unexpected reply {$response['code']} ({$response['text']})");
        return null;
    }

    return $response;
}

/**
 * Apply SMTP transparency (dot-stuffing) and CRLF line endings to a message
 * body: a line consisting of a single "." would otherwise terminate DATA.
 */
function bb_smtp_dot_stuff(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);
    foreach ($lines as $i => $line) {
        if ($line !== '' && $line[0] === '.') {
            $lines[$i] = '.' . $line;
        }
    }
    return implode("\r\n", $lines);
}

/**
 * Deliver a message over SMTP. Returns true only when the server accepted the
 * message body (final 250 after DATA).
 */
function bb_smtp_send(array $config, string $to, string $subject, string $htmlBody, string $from, string $fromName): bool
{
    $host = trim((string)($config['mail_host'] ?? 'localhost'));
    $port = (int)($config['mail_port'] ?? 25);
    $username = (string)($config['mail_username'] ?? '');
    $password = (string)($config['mail_password'] ?? '');
    $secure = strtolower(trim((string)($config['mail_secure'] ?? '')));
    $timeout = (int)($config['mail_timeout'] ?? 10);
    if ($timeout <= 0) {
        $timeout = 10;
    }

    if ($host === '') {
        error_log('SMTP: no host configured');
        return false;
    }
    if ($port < 1 || $port > 65535) {
        error_log('SMTP: invalid port ' . $port);
        return false;
    }

    $connectHost = $secure === 'ssl' ? 'ssl://' . $host : $host;

    $fp = @fsockopen($connectHost, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        error_log("SMTP connect failed: {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($fp, $timeout);

    $clientName = php_uname('n') ?: 'localhost';

    // Greeting
    $greeting = bb_smtp_read_response($fp, $timeout);
    if ($greeting === null || $greeting['code'] !== 220) {
        error_log('SMTP greeting rejected: ' . ($greeting['text'] ?? 'no response'));
        fclose($fp);
        return false;
    }

    // EHLO, falling back to HELO for servers that do not implement ESMTP.
    $hello = bb_smtp_command($fp, 'EHLO ' . $clientName, [250], $timeout, 'EHLO');
    if ($hello === null) {
        $hello = bb_smtp_command($fp, 'HELO ' . $clientName, [250], $timeout, 'HELO');
        if ($hello === null) {
            fclose($fp);
            return false;
        }
    }

    if ($secure === 'tls') {
        if (bb_smtp_command($fp, 'STARTTLS', [220], $timeout, 'STARTTLS') === null) {
            fclose($fp);
            return false;
        }
        $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($crypto !== true) {
            error_log('SMTP STARTTLS failed');
            fclose($fp);
            return false;
        }
        // The session state is discarded after STARTTLS: EHLO must be repeated.
        if (bb_smtp_command($fp, 'EHLO ' . $clientName, [250], $timeout, 'EHLO (post-TLS)') === null) {
            fclose($fp);
            return false;
        }
    }

    if ($username !== '' && $password !== '') {
        if (bb_smtp_command($fp, 'AUTH LOGIN', [334], $timeout, 'AUTH LOGIN') === null) {
            fclose($fp);
            return false;
        }
        if (bb_smtp_command($fp, base64_encode($username), [334], $timeout, 'AUTH username') === null) {
            fclose($fp);
            return false;
        }
        if (bb_smtp_command($fp, base64_encode($password), [235], $timeout, 'AUTH password') === null) {
            fclose($fp);
            return false;
        }
    }

    if (bb_smtp_command($fp, 'MAIL FROM:<' . $from . '>', [250], $timeout, 'MAIL FROM') === null) {
        fclose($fp);
        return false;
    }
    // 251 = user not local, will forward.
    if (bb_smtp_command($fp, 'RCPT TO:<' . $to . '>', [250, 251], $timeout, 'RCPT TO') === null) {
        fclose($fp);
        return false;
    }
    if (bb_smtp_command($fp, 'DATA', [354], $timeout, 'DATA') === null) {
        fclose($fp);
        return false;
    }

    $hostForId = str_contains($from, '@') ? substr($from, strpos($from, '@') + 1) : 'localhost';
    $headers = 'Date: ' . date('r') . "\r\n";
    $headers .= 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $hostForId . ">\r\n";
    $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
    $headers .= 'From: ' . $fromName . ' <' . $from . ">\r\n";
    $headers .= 'To: ' . $to . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    $payload = bb_smtp_dot_stuff($headers . "\r\n" . $htmlBody) . "\r\n.\r\n";

    if (@fwrite($fp, $payload) !== strlen($payload)) {
        error_log('SMTP DATA: write failed');
        fclose($fp);
        return false;
    }

    $accepted = bb_smtp_read_response($fp, $timeout);
    if ($accepted === null || $accepted['code'] !== 250) {
        error_log('SMTP DATA: message not accepted: ' . ($accepted['text'] ?? 'no response'));
        // Best effort: tell the server we are done before closing.
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return false;
    }

    // QUIT is polite but not required: a failure here must not turn a
    // successfully accepted message into an error.
    if (bb_smtp_command($fp, 'QUIT', [221], $timeout, 'QUIT') === null) {
        @fwrite($fp, "QUIT\r\n");
    }
    fclose($fp);
    return true;
}

function send_email($to, $subject, $body) {
    if ($to === '' || preg_match('/[\r\n]/', $to)) {
        error_log("send_email: invalid recipient address");
        return false;
    }

    $filtered = filter_var($to, FILTER_VALIDATE_EMAIL);
    if ($filtered === false) {
        error_log("send_email: email validation failed for: " . substr($to, 0, 50));
        return false;
    }
    $to = $filtered;

    $config = App::getInstance()->config;

    $mailFrom = $config['mail_from'] ?? '';
    $mailFromName = $config['mail_from_name'] ?? '';

    $filteredFrom = filter_var($mailFrom, FILTER_VALIDATE_EMAIL);
    if ($mailFrom === '' || $filteredFrom === false) {
        error_log("send_email: invalid mail_from address");
        return false;
    }
    $mailFrom = $filteredFrom;
    if (preg_match('/[\r\n]/', $mailFromName)) {
        error_log("send_email: invalid mail_from_name");
        return false;
    }

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$mailFromName} <{$mailFrom}>\r\n";
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

    if (($config['mail_method'] ?? 'mail') === 'smtp') {
        return bb_smtp_send($config, $to, (string)$subject, $htmlBody, $mailFrom, (string)$mailFromName);
    }

    $envelope = '-f' . $mailFrom;
    return @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $htmlBody, $headers, $envelope);
}
