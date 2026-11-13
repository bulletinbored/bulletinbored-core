<?php

/**
 * Minimal SMTP server used by the mail tests.
 *
 * Usage: php fake_smtp_server.php <port> <mode> <transcript-file>
 *
 * Modes:
 *   ok           accept the message (250 after DATA), multi-line EHLO
 *   reject_rcpt  refuse the recipient with 550
 *   reject_data  accept RCPT but refuse the message body with 554
 *   auth         require AUTH LOGIN before MAIL FROM
 *   slow         never answer (client timeout)
 *   no_starttls  refuse STARTTLS with 502
 *   tls_garbage  accept STARTTLS then speak plain text (handshake failure)
 *   tls_selfsigned  accept STARTTLS and present the self-signed certificate
 *                   given as argv[4] (cert) and argv[5] (key)
 *
 * Every line received from the client is appended to the transcript file so a
 * test can assert what was actually put on the wire (e.g. dot-stuffing).
 */

$port = (int)($argv[1] ?? 0);
$mode = (string)($argv[2] ?? 'ok');
$transcriptFile = (string)($argv[3] ?? '');
$certFile = (string)($argv[4] ?? '');
$keyFile = (string)($argv[5] ?? '');

$context = null;
if (in_array($mode, ['tls_selfsigned', 'tls_garbage'], true) && $certFile !== '' && is_file($certFile)) {
    $context = stream_context_create(['ssl' => [
        'local_cert' => $certFile,
        'local_pk' => $keyFile !== '' ? $keyFile : null,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ]]);
}

$server = @stream_socket_server(
    'tcp://127.0.0.1:' . $port,
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}

// Tell the test the socket is ready.
echo "READY\n";
flush();

$transcript = function (string $line, string $dir) use ($transcriptFile): void {
    if ($transcriptFile !== '') {
        @file_put_contents($transcriptFile, $dir . ' ' . rtrim($line, "\r\n") . "\n", FILE_APPEND);
    }
};

$conn = @stream_socket_accept($server, 15);
if ($conn === false) {
    exit(2);
}
stream_set_timeout($conn, 10);

$write = function (string $line) use ($conn, $transcript): void {
    fwrite($conn, $line);
    $transcript($line, 'S:');
};

$read = function () use ($conn, $transcript): ?string {
    $line = fgets($conn, 2048);
    if ($line === false) {
        return null;
    }
    $transcript($line, 'C:');
    return rtrim($line, "\r\n");
};

$write("220 fake.local ESMTP ready\r\n");

if ($mode === 'slow') {
    // Accept the connection and then stay silent until the client gives up.
    sleep(15);
    fclose($conn);
    exit(0);
}

$authenticated = false;

while (($line = $read()) !== null) {
    $upper = strtoupper($line);

    if (str_starts_with($upper, 'EHLO')) {
        $write("250-fake.local\r\n");
        $write("250-SIZE 10240000\r\n");
        if ($mode === 'auth') {
            $write("250-AUTH LOGIN\r\n");
        }
        $write("250 HELP\r\n");
        continue;
    }

    if (str_starts_with($upper, 'HELO')) {
        $write("250 fake.local\r\n");
        continue;
    }

    if (str_starts_with($upper, 'STARTTLS')) {
        if ($mode === 'no_starttls') {
            $write("502 5.5.1 STARTTLS not supported\r\n");
            continue;
        }
        if ($mode === 'tls_garbage') {
            $write("220 2.0.0 Ready to start TLS\r\n");
            $write("this is not a TLS handshake\r\n");
            break;
        }
        if ($mode === 'tls_selfsigned') {
            $write("220 2.0.0 Ready to start TLS\r\n");
            $ok = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            if ($ok !== true) {
                // The client rejected the certificate (expected in the test).
                break;
            }
            continue;
        }
        $write("454 4.7.0 TLS not available\r\n");
        continue;
    }

    if (str_starts_with($upper, 'AUTH LOGIN')) {
        if ($mode !== 'auth') {
            $write("503 5.5.1 Authentication not enabled\r\n");
            continue;
        }
        $write("334 VXNlcm5hbWU6\r\n");
        $read(); // username
        $write("334 UGFzc3dvcmQ6\r\n");
        $read(); // password
        $write("235 2.7.0 Authentication successful\r\n");
        $authenticated = true;
        continue;
    }

    if (str_starts_with($upper, 'MAIL FROM')) {
        if ($mode === 'auth' && !$authenticated) {
            $write("530 5.7.0 Authentication required\r\n");
            continue;
        }
        $write("250 2.1.0 Ok\r\n");
        continue;
    }

    if (str_starts_with($upper, 'RCPT TO')) {
        if ($mode === 'reject_rcpt') {
            $write("550 5.1.1 No such user\r\n");
            continue;
        }
        $write("250 2.1.5 Ok\r\n");
        continue;
    }

    if ($upper === 'DATA') {
        $write("354 End data with <CR><LF>.<CR><LF>\r\n");
        while (($bodyLine = $read()) !== null) {
            if ($bodyLine === '.') {
                break;
            }
        }
        if ($mode === 'reject_data') {
            $write("554 5.7.1 Message content rejected\r\n");
            continue;
        }
        $write("250 2.0.0 Ok: queued as FAKE123\r\n");
        continue;
    }

    if ($upper === 'QUIT') {
        $write("221 2.0.0 Bye\r\n");
        break;
    }

    if ($upper === 'RSET' || $upper === 'NOOP') {
        $write("250 2.0.0 Ok\r\n");
        continue;
    }

    $write("500 5.5.2 Command unrecognized\r\n");
}

fclose($conn);
fclose($server);
exit(0);
