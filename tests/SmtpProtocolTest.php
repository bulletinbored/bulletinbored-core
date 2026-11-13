<?php

/**
 * SmtpProtocolTest — the dependency-free SMTP client must check every server
 * reply instead of assuming success.
 *
 * A local fake SMTP server (tests/fixtures/fake_smtp_server.php) is started for
 * each case, so these tests exercise the real socket code path: greeting, EHLO,
 * AUTH, MAIL FROM, RCPT TO, DATA and the final 250.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/Helpers/Text.php';
require_once __DIR__ . '/../src/Helpers/Mail.php';

function smtp_test_free_port(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        return 0;
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    if (!is_string($name) || !str_contains($name, ':')) {
        return 0;
    }
    return (int)substr($name, strrpos($name, ':') + 1);
}

/**
 * Start the fake server.
 * @return array{0: mixed, 1: int, 2: string, 3: string} [proc, port, transcriptFile, pid]
 */
function smtp_test_start(string $mode, array $extraArgs = []): array
{
    if (!function_exists('proc_open')) {
        return [null, 0, '', ''];
    }
    $port = smtp_test_free_port();
    if ($port === 0) {
        return [null, 0, '', ''];
    }
    $transcript = sys_get_temp_dir() . '/bb_smtp_' . uniqid('', true) . '.log';
    touch($transcript);

    $serverScript = __DIR__ . '/fixtures/fake_smtp_server.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($serverScript)
        . ' ' . $port . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($transcript);
    foreach ($extraArgs as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        @unlink($transcript);
        return [null, 0, '', ''];
    }
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 5);

    // Wait for the server to bind (it prints READY) or to fail.
    $ready = fgets($pipes[1]);
    if (!is_string($ready) || !str_contains($ready, 'READY')) {
        @proc_terminate($proc, 9);
        @proc_close($proc);
        @unlink($transcript);
        return [null, 0, '', ''];
    }

    $status = proc_get_status($proc);
    $pid = (int)($status['pid'] ?? 0);
    return [$proc, $port, $transcript, $pid];
}

function smtp_test_stop($proc, int $pid = 0): void
{
    if (!is_resource($proc)) {
        return;
    }
    $status = @proc_get_status($proc);
    if (is_array($status) && !empty($status['running']) && $pid > 0) {
        if (stripos(PHP_OS, 'WIN') === 0) {
            @exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>NUL');
        } else {
            @exec('kill -9 ' . $pid . ' 2>/dev/null');
        }
    }
    @proc_terminate($proc, 9);
    @proc_close($proc);
}

function smtp_test_config(int $port, array $overrides = []): void
{
    $app = App::getInstance();
    $app->config = array_merge([
        'site_name' => 'Test Forum',
        'mail_method' => 'smtp',
        'mail_host' => '127.0.0.1',
        'mail_port' => $port,
        'mail_from' => 'noreply@example.com',
        'mail_from_name' => 'Test Forum',
        'mail_secure' => '',
        'mail_timeout' => 5,
        'mail_username' => '',
        'mail_password' => '',
    ], $overrides);
}

function smtp_test_run(string $mode, array $overrides, string $body, array $extraArgs = []): array
{
    [$proc, $port, $transcript, $pid] = smtp_test_start($mode, $extraArgs);
    if ($proc === null) {
        return ['skipped' => true, 'sent' => false, 'transcript' => ''];
    }
    try {
        smtp_test_config($port, $overrides);
        $sent = send_email('member@example.com', 'Reset your password', $body);
        $log = (string)@file_get_contents($transcript);
    } finally {
        smtp_test_stop($proc, $pid);
        @unlink($transcript);
        App::reset();
    }
    return ['skipped' => false, 'sent' => $sent, 'transcript' => $log];
}

function test_smtp_accepts_message_and_stuffs_dots(): Test
{
    $t = new Test('SMTP - Accepted message is dot-stuffed');

    $body = "Hello\n.hidden dot line\n..two dots\nEnd";
    $result = smtp_test_run('ok', [], $body);

    if ($result['skipped']) {
        $t->assert('Skipped: could not start the fake SMTP server', true);
        return $t;
    }

    $t->assertTrue('send_email reports success on a 250 after DATA', $result['sent']);
    $t->assert('Client greeted the server', str_contains($result['transcript'], 'S: 220'));
    $t->assert('Client sent EHLO', str_contains($result['transcript'], 'C: EHLO'));
    $t->assert('Client sent MAIL FROM', str_contains($result['transcript'], 'C: MAIL FROM:<noreply@example.com>'));
    $t->assert('Client sent RCPT TO', str_contains($result['transcript'], 'C: RCPT TO:<member@example.com>'));
    $t->assert('Line starting with a dot is escaped', str_contains($result['transcript'], "C: ..hidden dot line"));
    $t->assert('Line with two dots is escaped once', str_contains($result['transcript'], "C: ...two dots"));
    $t->assert('Message terminated by a single dot', str_contains($result['transcript'], "C: .\n"));
    $t->assert('Subject header present', str_contains($result['transcript'], 'C: Subject: =?UTF-8?B?'));
    $t->assert('Date header present', str_contains($result['transcript'], 'C: Date: '));
    $t->assert('Message-ID header present', str_contains($result['transcript'], 'C: Message-ID: <'));

    return $t;
}

function test_smtp_rejected_recipient_fails(): Test
{
    $t = new Test('SMTP - Rejected recipient returns false');

    $result = smtp_test_run('reject_rcpt', [], 'Body');
    if ($result['skipped']) {
        $t->assert('Skipped: could not start the fake SMTP server', true);
        return $t;
    }

    $t->assertFalse('send_email returns false on 550 for RCPT TO', $result['sent']);
    $t->assert('Client never sent DATA after a rejected recipient', !str_contains($result['transcript'], "C: DATA"));

    return $t;
}

function test_smtp_rejected_message_fails(): Test
{
    $t = new Test('SMTP - Message refused after DATA returns false');

    $result = smtp_test_run('reject_data', [], 'Body');
    if ($result['skipped']) {
        $t->assert('Skipped: could not start the fake SMTP server', true);
        return $t;
    }

    $t->assertFalse('send_email returns false on 554 after DATA', $result['sent']);

    return $t;
}

function test_smtp_authentication(): Test
{
    $t = new Test('SMTP - AUTH LOGIN is verified');

    $result = smtp_test_run('auth', ['mail_username' => 'smtp-user', 'mail_password' => 'smtp-pass'], 'Body');
    if ($result['skipped']) {
        $t->assert('Skipped: could not start the fake SMTP server', true);
        return $t;
    }

    $t->assertTrue('Authenticated message accepted', $result['sent']);
    $t->assert('AUTH LOGIN sent', str_contains($result['transcript'], 'C: AUTH LOGIN'));
    $t->assert('Username sent base64-encoded', str_contains($result['transcript'], 'C: ' . base64_encode('smtp-user')));
    $t->assert('Password sent base64-encoded', str_contains($result['transcript'], 'C: ' . base64_encode('smtp-pass')));

    return $t;
}

function test_smtp_timeout_returns_false(): Test
{
    $t = new Test('SMTP - Silent server times out');

    $result = smtp_test_run('slow', ['mail_timeout' => 1], 'Body');
    if ($result['skipped']) {
        $t->assert('Skipped: could not start the fake SMTP server', true);
        return $t;
    }

    $t->assertFalse('send_email returns false when the greeting never arrives', $result['sent']);

    return $t;
}

function test_smtp_dot_stuff_helper(): Test
{
    $t = new Test('SMTP - dot-stuffing helper');

    $t->assertEquals('Single dot line escaped', '..', bb_smtp_dot_stuff('.'));
    $t->assertEquals('LF normalised to CRLF', "a\r\nb", bb_smtp_dot_stuff("a\nb"));
    $t->assertEquals('CR only normalised to CRLF', "a\r\nb", bb_smtp_dot_stuff("a\rb"));
    $t->assertEquals('Interior dots untouched', "a.b", bb_smtp_dot_stuff('a.b'));

    return $t;
}

function test_smtp_plaintext_auth_policy(): Test
{
    $t = new Test('SMTP - credentials require explicit consent on plaintext');

    // Without TLS the client warns but keeps working (existing installations
    // with a local relay must not break).
    $allowed = smtp_test_run('auth', ['mail_username' => 'u', 'mail_password' => 'p'], 'Body');
    if ($allowed['skipped']) {
        $t->assert('Skipped: cannot start the fake SMTP server', true);
        return $t;
    }
    $t->assertTrue('Plaintext AUTH still works when not forbidden', $allowed['sent']);
    $t->assert('AUTH was sent in clear', str_contains($allowed['transcript'], 'C: AUTH LOGIN'));

    // With mail_require_tls_for_auth the client must refuse before sending
    // the password.
    $refused = smtp_test_run(
        'auth',
        ['mail_username' => 'u', 'mail_password' => 'p', 'mail_require_tls_for_auth' => true],
        'Body'
    );
    $t->assertFalse('Plaintext AUTH refused when TLS is required', $refused['sent']);
    $t->assert('No credentials sent', !str_contains($refused['transcript'], 'C: ' . base64_encode('p')));

    return $t;
}

function test_smtp_starttls_refused(): Test
{
    $t = new Test('SMTP - STARTTLS refusal is an error');

    $result = smtp_test_run('no_starttls', ['mail_secure' => 'tls'], 'Body');
    if ($result['skipped']) {
        $t->assert('Skipped: cannot start the fake SMTP server', true);
        return $t;
    }

    $t->assertFalse('send_email fails when STARTTLS is refused', $result['sent']);
    $t->assert('Client attempted STARTTLS', str_contains($result['transcript'], 'C: STARTTLS'));
    $t->assert('Client never sent the message', !str_contains($result['transcript'], 'C: DATA'));

    return $t;
}

function test_smtp_tls_handshake_failure(): Test
{
    $t = new Test('SMTP - broken TLS handshake is an error');

    $result = smtp_test_run('tls_garbage', ['mail_secure' => 'tls'], 'Body');
    if ($result['skipped']) {
        $t->assert('Skipped: cannot start the fake SMTP server', true);
        return $t;
    }

    $t->assertFalse('send_email fails when the handshake fails', $result['sent']);
    $t->assert('Client never sent the message', !str_contains($result['transcript'], 'C: DATA'));

    return $t;
}

function test_smtp_rejects_self_signed_certificate(): Test
{
    $t = new Test('SMTP - server certificate is verified');

    if (!function_exists('openssl_pkey_new') || !function_exists('openssl_csr_sign')) {
        $t->assert('Skipped: openssl extension not available', true);
        return $t;
    }

    $dir = sys_get_temp_dir() . '/bb_cert_' . uniqid('', true);
    @mkdir($dir, 0700, true);
    $certFile = $dir . '/server.crt';
    $keyFile = $dir . '/server.key';

    $key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = $key ? @openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']) : false;
    $cert = $csr ? @openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']) : false;
    if (!$cert || !$key) {
        $t->assert('Skipped: cannot generate a test certificate', true);
        @rmdir($dir);
        return $t;
    }
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);
    file_put_contents($certFile, $certPem);
    file_put_contents($keyFile, $keyPem);

    try {
        $result = smtp_test_run('tls_selfsigned', ['mail_secure' => 'tls'], 'Body', [$certFile, $keyFile]);
        if ($result['skipped']) {
            $t->assert('Skipped: cannot start the TLS test server', true);
            return $t;
        }
        $t->assertFalse('Self-signed certificate is not trusted', $result['sent']);
        $t->assert('Client attempted STARTTLS', str_contains($result['transcript'], 'C: STARTTLS'));
        $t->assert('Message never sent over the unverified channel', !str_contains($result['transcript'], 'C: DATA'));
    } finally {
        @unlink($certFile);
        @unlink($keyFile);
        @rmdir($dir);
    }

    return $t;
}

register_tests(
    'test_smtp_plaintext_auth_policy',
    'test_smtp_starttls_refused',
    'test_smtp_tls_handshake_failure',
    'test_smtp_rejects_self_signed_certificate',
    'test_smtp_accepts_message_and_stuffs_dots',
    'test_smtp_rejected_recipient_fails',
    'test_smtp_rejected_message_fails',
    'test_smtp_authentication',
    'test_smtp_timeout_returns_false',
    'test_smtp_dot_stuff_helper'
);
