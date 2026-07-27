<?php
// Forum Nuovo - Minimal Forum Software
// Configuration

class Config {
    private static $instance = null;
    private $config = [];

    private function __construct() {
        $this->loadConfig();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfig() {
        // Default configuration
        $this->config = [
            'db_driver' => 'sqlite',
            'db_path' => __DIR__ . '/database.sqlite',
            'db_table_prefix' => 'forum_',
            'site_name' => 'Forum Nuovo',
            'site_url' => '',
            'admin_email' => 'admin@example.com',
            'upload_dir' => 'uploads/',
            'max_upload_size' => 10485760, // 10MB
            'plugin_dir' => 'plugins/',
            'template_dir' => 'templates/',
            'session_lifetime' => 3600,
            'salt' => 'generated_salt_key'
        ];

        // Load from local config file if exists
        $config_file = __DIR__ . '/config/local.php';
        if (file_exists($config_file)) {
            $loaded_config = require $config_file;
            $this->config = array_merge($this->config, $loaded_config);
        }

        // Ensure directories exist
        foreach (['uploads', 'config', 'plugins', 'templates'] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    public function get($key, $default = null) {
        return $this->config[$key] ?? $default;
    }

    public function set($key, $value) {
        $this->config[$key] = $value;
    }
}
