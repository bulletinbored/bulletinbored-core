<?php

/**
 * Telemetry — anonymous active-installation heartbeat.
 *
 * Sends a tiny, privacy-preserving ping to the project's collector so the
 * maintainers can count active installations. The only identifier is a random
 * install_id generated at install time (never derived from the domain, IP or
 * any host data). No content, users or personal data are ever transmitted.
 *
 * Enabled by default (opt-out) via config "telemetry". When disabled, no
 * network call is ever made. Every failure is swallowed: telemetry must never
 * break or noticeably slow down the forum.
 */
class Telemetry
{
    /** Minimum seconds between successful pings. */
    private const SUCCESS_INTERVAL = 86400;

    /** Minimum seconds between retries after a failed attempt. */
    private const RETRY_INTERVAL = 21600;

    private string $dataDir;
    private array $config;
    private ?string $configPath;
    /** @var callable|null */
    private $transport;

    /**
     * @param string        $dataDir    Writable data/ directory for the state file.
     * @param array         $config     Loaded config.json contents.
     * @param string|null   $configPath Path to config.json (to persist a missing install_id).
     * @param callable|null $transport  Optional transport(url, json): bool, for tests.
     */
    public function __construct(string $dataDir, array $config, ?string $configPath = null, ?callable $transport = null)
    {
        $this->dataDir = rtrim($dataDir, '/\\');
        $this->config = $config;
        $this->configPath = $configPath;
        $this->transport = $transport;
    }

    /**
     * Generate an RFC 4122 version 4 UUID using a cryptographically secure RNG.
     */
    public static function generateInstallId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['telemetry']);
    }

    public function endpoint(): string
    {
        $url = $this->config['telemetry_url'] ?? '';
        if (is_string($url) && trim($url) !== '') {
            return trim($url);
        }
        return 'https://bulletinbored.net/heartbeat.php';
    }

    /**
     * Return the install_id, generating and persisting one when missing so
     * installations upgraded from a version without telemetry are covered too.
     * Returns null when no id can be established (e.g. read-only config).
     */
    public function installId(): ?string
    {
        $id = (string)($this->config['install_id'] ?? '');
        if ($id !== '') {
            return $id;
        }
        if ($this->configPath === null || !is_file($this->configPath) || !is_writable($this->configPath)) {
            return null;
        }

        $id = self::generateInstallId();
        $raw = @file_get_contents($this->configPath);
        $config = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($config)) {
            return null;
        }
        $config['install_id'] = $id;
        $written = @file_put_contents(
            $this->configPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        if ($written === false) {
            return null;
        }

        $this->config['install_id'] = $id;
        return $id;
    }

    /**
     * Build the request payload. Kept minimal and free of anything identifying.
     */
    public function buildPayload(string $installId, int $pluginCount = 0, int $themeCount = 0): array
    {
        return [
            'install_id'   => $installId,
            'version'      => (string)($this->config['version'] ?? ''),
            'php_version'  => PHP_VERSION,
            'db_driver'    => (string)($this->config['db_driver'] ?? ''),
            'plugin_count' => max(0, $pluginCount),
            'theme_count'  => max(0, $themeCount),
            'lang'         => (string)($this->config['default_lang'] ?? 'en'),
        ];
    }

    /**
     * Send a ping if one is due. Safe to call on every request: the 24h guard
     * and the failure back-off keep it cheap.
     */
    public function maybePing(int $pluginCount = 0, int $themeCount = 0): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $installId = $this->installId();
        if ($installId === null || $installId === '') {
            return false;
        }

        $now = time();
        $state = $this->loadState();
        $lastSuccess = (int)($state['last_ping'] ?? 0);
        $lastAttempt = (int)($state['last_attempt'] ?? 0);

        if ($now - $lastSuccess < self::SUCCESS_INTERVAL) {
            return false;
        }
        if ($now - $lastAttempt < self::RETRY_INTERVAL) {
            return false;
        }

        $payload = $this->buildPayload($installId, $pluginCount, $themeCount);
        $state['last_attempt'] = $now;

        $ok = $this->send($this->endpoint(), json_encode($payload));
        if ($ok) {
            $state['last_ping'] = $now;
        }
        $this->saveState($state);

        return $ok;
    }

    /**
     * Perform the HTTP POST. Returns true only on a 2xx response. Never throws.
     */
    private function send(string $url, string $json): bool
    {
        if ($this->transport !== null) {
            try {
                return (bool)call_user_func($this->transport, $url, $json);
            } catch (\Throwable $e) {
                return false;
            }
        }

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT => 2,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_USERAGENT => 'bulletinbored-telemetry/1.0',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return $status >= 200 && $status < 300;
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nUser-Agent: bulletinbored-telemetry/1.0\r\n",
                    'content' => $json,
                    'timeout' => 2,
                    'ignore_errors' => true,
                ],
            ]);
            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                return false;
            }
            $status = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int)$m[1];
                }
            }
            return $status >= 200 && $status < 300;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function statePath(): string
    {
        return $this->dataDir . '/telemetry.json';
    }

    private function loadState(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function saveState(array $state): void
    {
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0755, true);
        }
        @file_put_contents(
            $this->statePath(),
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}
