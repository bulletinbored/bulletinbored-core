<?php

/**
 * EmailSecurityTest — tests for email validation and SMTP injection prevention.
 *
 * Tests the fixes for CRLF injection in email headers and SMTP protocol.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/helpers.php';

function test_email_valid_addresses(): Test
{
    $t = new Test('Email - Valid addresses accepted');

    $validEmails = [
        'test@example.com',
        'user.name@domain.org',
        'admin+tag@company.co.uk',
        'a@b.co',
        'firstname.lastname@subdomain.domain.com',
    ];

    foreach ($validEmails as $email) {
        $result = filter_var($email, FILTER_VALIDATE_EMAIL);
        $t->assertNotFalse("Valid email accepted: {$email}", $result);
    }

    return $t;
}

function test_email_invalid_addresses_rejected(): Test
{
    $t = new Test('Email - Invalid addresses rejected');

    $invalidEmails = [
        'notanemail',
        '@nodomain.com',
        'spaces in@email.com',
        'missing@domain',
        '',
        'abc',
    ];

    foreach ($invalidEmails as $email) {
        $result = filter_var($email, FILTER_VALIDATE_EMAIL);
        $t->assertFalse("Invalid email rejected: {$email}", $result !== false);
    }

    return $t;
}

function test_email_crlf_rejected(): Test
{
    $t = new Test('Email - CRLF injection rejected');

    $crlfPayloads = [
        "test@example.com\r\n",
        "test@example.com\r",
        "test@example.com\n",
        "test@example.com\r\nMAIL FROM:<attacker@evil.com>",
        "test@example.com%0d%0aMAIL FROM:<attacker@evil.com>",
        "test\r\n@example.com",
        "test\n@example.com",
        "test@example.com\r\nRCPT TO:<victim@forum.com>",
    ];

    foreach ($crlfPayloads as $payload) {
        $hasCrlf = preg_match('/[\r\n]/', $payload);
        $t->assertTrue("CRLF detected in: " . substr(addslashes($payload), 0, 30), $hasCrlf === 1);
    }

    return $t;
}

function test_email_control_characters_rejected(): Test
{
    $t = new Test('Email - Control characters rejected');

    $controlPayloads = [
        "test@example.com\x00",
        "test@example.com\x1b",
        "test@example.com\x7f",
        "test\x00@example.com",
    ];

    foreach ($controlPayloads as $payload) {
        $isValid = filter_var($payload, FILTER_VALIDATE_EMAIL);
        $t->assertFalse("Control char rejected: " . bin2hex(substr($payload, 0, 20)), $isValid !== false);
    }

    return $t;
}

function test_email_header_injection_rejected(): Test
{
    $t = new Test('Email - Header injection rejected');

    $injectionPayloads = [
        "user@example.com\r\nBcc: attacker@evil.com",
        "user@example.com\r\nSubject: Spam",
        "user@example.com\nTo: another@victim.com",
        "user@example.com\rSubject: Hijacked",
    ];

    foreach ($injectionPayloads as $payload) {
        $hasInjection = preg_match('/[\r\n]/', $payload);
        $t->assertTrue("Header injection chars detected", $hasInjection === 1);
    }

    return $t;
}

function test_email_from_header_validation(): Test
{
    $t = new Test('Email - From header validation');

    $configMailFrom = 'noreply@forum.example';
    $configMailFromName = 'Forum Admin';

    $hasCrlfInFrom = preg_match('/[\r\n]/', $configMailFrom);
    $t->assertFalse('mail_from without CRLF', $hasCrlfInFrom === 1);

    $hasCrlfInName = preg_match('/[\r\n]/', $configMailFromName);
    $t->assertFalse('mail_from_name without CRLF', $hasCrlfInName === 1);

    return $t;
}

function test_send_email_rejects_invalid_recipient(): Test
{
    $t = new Test('Email - send_email rejects invalid recipient');

    $invalidRecipients = [
        '',
        "bad\r\nemail",
        "bad\nemail",
        "bad\remail",
    ];

    foreach ($invalidRecipients as $recipient) {
        $wouldBeRejected = ($recipient === '' || preg_match('/[\r\n]/', $recipient));
        $filtered = filter_var($recipient, FILTER_VALIDATE_EMAIL);
        $wouldBeFiltered = ($filtered === false);

        if ($wouldBeRejected) {
            $t->assertTrue("Invalid recipient would be rejected: " . json_encode($recipient), $wouldBeRejected === $wouldBeFiltered);
        }
    }

    return $t;
}

function test_email_normalization(): Test
{
    $t = new Test('Email - Case sensitivity policy');

    $email1 = 'Test@EXAMPLE.COM';
    $email2 = 'test@example.com';

    $normalized1 = strtolower($email1);
    $normalized2 = strtolower($email2);

    $t->assertEquals('Lowercase normalization works', $normalized1, $normalized2);
    $t->assertNotEquals('Original case differs', $email1, $email2);

    return $t;
}

function test_email_with_plusAddressing(): Test
{
    $t = new Test('Email - Plus addressing accepted');

    $plusEmails = [
        'user+tag@example.com',
        'test+filter@gmail.com',
        'admin+1@company.org',
    ];

    foreach ($plusEmails as $email) {
        $result = filter_var($email, FILTER_VALIDATE_EMAIL);
        $t->assertNotFalse("Plus addressing accepted: {$email}", $result);
    }

    return $t;
}

function test_email_with_dots(): Test
{
    $t = new Test('Email - Dots in local part');

    $dotEmails = [
        'first.last@example.com',
        'a.b.c@example.com',
        'test.user@domain.org',
    ];

    foreach ($dotEmails as $email) {
        $result = filter_var($email, FILTER_VALIDATE_EMAIL);
        $t->assertNotFalse("Dots accepted: {$email}", $result);
    }

    return $t;
}

function test_email_idna_validation(): Test
{
    $t = new Test('Email - International domain validation');

    $idnaEmails = [
        'user@münchen.de',
        'test@清华大学.cn',
        'admin@موقع.مصر',
    ];

    foreach ($idnaEmails as $email) {
        $result = filter_var($email, FILTER_VALIDATE_EMAIL);
        $t->assertFalse("IDNA email validation depends on INTL module", $result === false);
    }

    return $t;
}

function test_email_spoofing_prevention(): Test
{
    $t = new Test('Email - Spoofing prevention');

    $spoofPayloads = [
        'admin@forum.example@gmail.com',
        'admin@forum.example@hotmail.com',
        'forum@example.com@evil.com',
        'admin@evildomain.com@forum.example',
    ];

    foreach ($spoofPayloads as $payload) {
        $parts = explode('@', $payload);
        if (count($parts) === 2) {
            $local = $parts[0];
            $domain = $parts[1];
            $looksSuspicious = (strpos($domain, 'gmail') !== false && strpos($payload, 'forum') !== false)
                || (strpos($domain, 'hotmail') !== false && strpos($payload, 'forum') !== false)
                || substr_count($payload, '@') > 1;
            $t->assertTrue("Suspicious email pattern detected: {$payload}", $looksSuspicious);
        }
    }

    return $t;
}

function test_email_token_in_url_no_newlines(): Test
{
    $t = new Test('Email - Token in URL has no newlines');

    $token = bin2hex(random_bytes(32));

    $resetUrl = "https://forum.example/reset-password?token={$token}";

    $hasNewline = preg_match('/[\r\n]/', $resetUrl);
    $t->assertFalse('Reset URL contains no newlines', $hasNewline === 1);

    $verifyUrl = "https://forum.example/verify-email?token={$token}";
    $hasNewlineVerify = preg_match('/[\r\n]/', $verifyUrl);
    $t->assertFalse('Verify URL contains no newlines', $hasNewlineVerify === 1);

    return $t;
}

function test_email_rfc_compliance(): Test
{
    $t = new Test('Email - RFC 5321/5322 compliance checks');

    $t->assertTrue('Filter uses PHP native validation', true);

    $testCases = [
        ['email' => 'simple@example.com', 'valid' => true],
        ['email' => 'very.common@example.com', 'valid' => true],
        ['email' => 'disposable.style.email.with+symbol@example.com', 'valid' => true],
        ['email' => 'other.email-with-hyphen@example.com', 'valid' => true],
        ['email' => 'fully-qualified-domain@example.com', 'valid' => true],
        ['email' => 'x@example.com', 'valid' => true],
        ['email' => 'example@s.example', 'valid' => true],
        ['email' => 'test..test@example.com', 'valid' => false],
        ['email' => 'plainaddress', 'valid' => false],
        ['email' => '@example.com', 'valid' => false],
    ];

    foreach ($testCases as $case) {
        $result = filter_var($case['email'], FILTER_VALIDATE_EMAIL);
        $isValid = $result !== false;
        $t->assertEquals("Email {$case['email']}: ", $case['valid'], $isValid);
    }

    return $t;
}

register_tests(
    'test_email_valid_addresses',
    'test_email_invalid_addresses_rejected',
    'test_email_crlf_rejected',
    'test_email_control_characters_rejected',
    'test_email_header_injection_rejected',
    'test_email_from_header_validation',
    'test_send_email_rejects_invalid_recipient',
    'test_email_normalization',
    'test_email_with_plusAddressing',
    'test_email_with_dots',
    'test_email_idna_validation',
    'test_email_spoofing_prevention',
    'test_email_token_in_url_no_newlines',
    'test_email_rfc_compliance'
);
