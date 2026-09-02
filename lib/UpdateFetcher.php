<?php

class UpdateFetcher
{
    private ?string $updateServer;
    private ?string $updateMirror;
    private ?string $githubToken;
    private int $cacheTTL;
    private string $cachePath;

    public function __construct(
        string $cacheDir,
        ?string $updateServer = null,
        ?string $githubToken = null,
        ?string $updateMirror = null,
        int $cacheTTL = 3600
    ) {
        $this->updateServer = $updateServer;
        $this->githubToken = $githubToken;
        $this->updateMirror = rtrim($updateMirror ?? '', '/');
        $this->cacheTTL = $cacheTTL;
        $this->cachePath = rtrim($cacheDir, '/') . '/update-cache.json';
    }

    public function httpGet(string $url, int $timeout = 10, ?string $token = null): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'bulletinbored-update-checker/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($token) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: token ' . $token]);
            }
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno !== 0 || $body === false) {
                return null;
            }
            return $body;
        }

        $headers = [
            'timeout' => $timeout,
            'user_agent' => 'bulletinbored-update-checker/1.0',
        ];
        if ($token) {
            $headers['header'] = 'Authorization: token ' . $token;
        }
        $body = @file_get_contents($url, false, stream_context_create(['http' => $headers]));
        return $body === false ? null : $body;
    }

    public function fetchRemoteVersion(string $type, ?string $name = null, ?string $repoUrl = null): ?string
    {
        $cached = $this->getCachedVersion($type, $name);
        if ($cached !== null) {
            return $cached;
        }

        $version = null;

        if ($this->updateMirror) {
            $json = $this->httpGet($this->updateMirror . '/versions.json', 10);
            if ($json !== null) {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    if ($type === 'core') {
                        $version = $data['core']['version'] ?? null;
                    } else {
                        $section = $data[$type . 's'] ?? $data[$type] ?? [];
                        $key = is_string($name) ? strtolower($name) : null;
                        if ($key !== null && isset($section[$key]['version'])) {
                            $version = $section[$key]['version'];
                        }
                    }
                }
            }
        }

        if ($version === null && !$repoUrl && !empty($this->updateServer)
            && preg_match('#github\.com/([^/]+)/([^/]+)#i', $this->updateServer, $m)) {
            $repoUrl = 'https://github.com/' . $m[1] . '/' . $m[2];
        }
        if ($version === null && $repoUrl && preg_match('#(?:https?://)?github\.com/([^/]+)/([^/]+)(?:/|$)#i', $repoUrl, $m)) {
            $apiUrl = 'https://api.github.com/repos/' . rawurlencode($m[1]) . '/' . rawurlencode($m[2]) . '/releases/latest';
            $json = $this->httpGet($apiUrl, 10, $this->githubToken);
            if ($json) {
                $release = json_decode($json, true);
                if (is_array($release) && !empty($release['tag_name'])) {
                    $version = ltrim($release['tag_name'], 'v');
                }
            }
        }

        if ($version !== null) {
            $this->setCachedVersion($type, $name, $version);
        }

        return $version;
    }

    private function loadCache(): array
    {
        if (!file_exists($this->cachePath)) {
            return [];
        }
        $data = json_decode(file_get_contents($this->cachePath), true);
        return is_array($data) ? $data : [];
    }

    private function saveCache(array $cache): void
    {
        $dir = dirname($this->cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->cachePath, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function getCachedVersion(string $type, ?string $name = null): ?string
    {
        $cache = $this->loadCache();
        $key = $type . ':' . ($name ?? 'core');
        if (isset($cache[$key]['version'], $cache[$key]['timestamp']) && (time() - (int)$cache[$key]['timestamp'] < $this->cacheTTL)) {
            return $cache[$key]['version'];
        }
        return null;
    }

    private function setCachedVersion(string $type, ?string $name, string $version): void
    {
        if ($version === '' || $version === null) {
            return;
        }
        $cache = $this->loadCache();
        $key = $type . ':' . ($name ?? 'core');
        $cache[$key] = [
            'version' => $version,
            'timestamp' => time(),
        ];
        $this->saveCache($cache);
    }

    public function clearCache(): void
    {
        @unlink($this->cachePath);
    }
}
