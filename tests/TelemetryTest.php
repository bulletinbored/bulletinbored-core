<?php

/**
 * TelemetryTest — anonymous install heartbeat, guards and privacy.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../lib/Telemetry.php';

function telemetry_tmp_dir(): string
{
    $dir = sys_get_temp_dir() . '/bb_telemetry_' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);
    return $dir;
}

function telemetry_rm_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir($dir);
}

function test_telemetry_generates_valid_uuid(): Test
{
    $t = new Test('Telemetry - generates valid v4 UUID');

    $id = Telemetry::generateInstallId();
    $t->assert('matches UUID v4 format', (bool)preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        $id
    ));
    $t->assertNotEquals('unique across calls', $id, Telemetry::generateInstallId());

    return $t;
}

function test_telemetry_payload_shape(): Test
{
    $t = new Test('Telemetry - payload contains only anonymous fields');

    $dir = telemetry_tmp_dir();
    $config = [
        'install_id' => 'abc',
        'version' => '0.9.6',
        'db_driver' => 'sqlite',
        'default_lang' => 'en',
    ];
    $telemetry = new Telemetry($dir, $config, null, fn() => true);
    $payload = $telemetry->buildPayload('abc', 3, 2);

    foreach (['install_id', 'version', 'php_version', 'db_driver', 'plugin_count', 'theme_count', 'lang'] as $key) {
        $t->assert("payload has '{$key}'", array_key_exists($key, $payload));
    }
    $t->assertEquals('plugin_count', 3, $payload['plugin_count']);
    $t->assertEquals('theme_count', 2, $payload['theme_count']);
    $t->assert('no host/domain/user fields leak', !isset($payload['host']) && !isset($payload['url']) && !isset($payload['user']));

    telemetry_rm_dir($dir);
    return $t;
}

function test_telemetry_disabled_sends_nothing(): Test
{
    $t = new Test('Telemetry - disabled sends nothing');

    $dir = telemetry_tmp_dir();
    $calls = 0;
    $telemetry = new Telemetry(
        $dir,
        ['telemetry' => false, 'install_id' => 'abc'],
        null,
        function () use (&$calls) { $calls++; return true; }
    );

    $t->assertFalse('isEnabled false', $telemetry->isEnabled());
    $t->assertFalse('maybePing returns false', $telemetry->maybePing());
    $t->assertEquals('transport never called', 0, $calls);

    telemetry_rm_dir($dir);
    return $t;
}

function test_telemetry_pings_once_per_day(): Test
{
    $t = new Test('Telemetry - at most one successful ping per day');

    $dir = telemetry_tmp_dir();
    $urls = [];
    $telemetry = new Telemetry(
        $dir,
        ['telemetry' => true, 'install_id' => 'abc', 'version' => '1.0.0'],
        null,
        function ($url, $json) use (&$urls) { $urls[] = [$url, $json]; return true; }
    );

    $t->assertTrue('first ping sent', $telemetry->maybePing());
    $t->assertFalse('second ping suppressed', $telemetry->maybePing());
    $t->assertEquals('transport called exactly once', 1, count($urls));
    $t->assert('payload is valid JSON', is_array(json_decode($urls[0][1], true)));

    telemetry_rm_dir($dir);
    return $t;
}

function test_telemetry_failure_backoff(): Test
{
    $t = new Test('Telemetry - failure backoff avoids retry storms');

    $dir = telemetry_tmp_dir();
    $calls = 0;
    $telemetry = new Telemetry(
        $dir,
        ['telemetry' => true, 'install_id' => 'abc'],
        null,
        function () use (&$calls) { $calls++; return false; }
    );

    $t->assertFalse('failed ping returns false', $telemetry->maybePing());
    $t->assertFalse('immediate retry suppressed', $telemetry->maybePing());
    $t->assertEquals('transport called once despite failure', 1, $calls);

    telemetry_rm_dir($dir);
    return $t;
}

function test_telemetry_persists_missing_install_id(): Test
{
    $t = new Test('Telemetry - generates and persists missing install_id');

    $dir = telemetry_tmp_dir();
    $configPath = $dir . '/config.json';
    file_put_contents($configPath, json_encode(['telemetry' => true, 'version' => '1.0.0']));

    $telemetry = new Telemetry($dir, ['telemetry' => true], $configPath, fn() => true);
    $id = $telemetry->installId();

    $t->assertNotNull('install_id generated', $id);
    $t->assert('matches UUID v4', (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$id));

    $saved = json_decode(file_get_contents($configPath), true);
    $t->assertEquals('install_id written to config', $id, $saved['install_id'] ?? null);

    telemetry_rm_dir($dir);
    return $t;
}

function test_telemetry_defaults_on_when_key_missing(): Test
{
    $t = new Test('Telemetry - opt-out defaults to enabled, canonical endpoint');

    $dir = telemetry_tmp_dir();
    $telemetry = new Telemetry($dir, ['install_id' => 'abc'], null, fn() => true);

    $t->assertTrue('enabled when telemetry key absent', $telemetry->isEnabled());
    $t->assertEquals(
        'default endpoint uses www host',
        'https://www.bulletinbored.net/heartbeat.php',
        $telemetry->endpoint()
    );

    telemetry_rm_dir($dir);
    return $t;
}

register_tests(
    'test_telemetry_generates_valid_uuid',
    'test_telemetry_payload_shape',
    'test_telemetry_disabled_sends_nothing',
    'test_telemetry_pings_once_per_day',
    'test_telemetry_failure_backoff',
    'test_telemetry_persists_missing_install_id',
    'test_telemetry_defaults_on_when_key_missing'
);
