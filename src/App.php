<?php

/**
 * ApplicationContext — centralizes application state that was previously in $GLOBALS.
 *
 * Usage:
 *   $app = App::getInstance();
 *   $app->config = $config;
 *   $app->pdo = $pdo;
 *   $app->authz = $authz;
 *
 *   // Access anywhere:
 *   $config = App::getInstance()->config;
 */
if (class_exists('App', false)) {
    return;
}
class App
{
    private static ?App $instance = null;

    public array $config = [];
    public ?PDO $pdo = null;
    public ?object $authz = null;
    public ?object $pluginManager = null;
    public ?object $themeManager = null;
    public ?object $updateManager = null;
    public ?string $cspNonce = null;
    public array $i18n = [];
    public ?string $forwardedProto = null;
    public ?string $forwardedSsl = null;
    public ?string $forwardedFor = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset the instance (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Get a config value with optional default.
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get the client IP, respecting trusted proxies.
     */
    public function clientIp(): string
    {
        return $this->forwardedFor ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
