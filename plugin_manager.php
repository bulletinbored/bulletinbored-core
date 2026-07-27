<?php
// Plugin System - Hook-based architecture

class PluginManager {
    private $hooks = [];

    public function addHook($hookName, $callback) {
        $this->hooks[$hookName][] = $callback;
    }

    public function runHook($hookName, ...$args) {
        if (isset($this->hooks[$hookName])) {
            foreach ($this->hooks[$hookName] as $callback) {
                call_user_func($callback, ...$args);
            }
        }
    }

    public function loadPlugins($pluginDir) {
        if (!is_dir($pluginDir)) return;

        foreach (glob($pluginDir . "*.php") as $file) {
            require $file;
        }
    }
}

// Global plugin manager instance
$GLOBALS['plugin_manager'] = new PluginManager();

// Hook functions for easy access
function add_hook($name, $callback) {
    $GLOBALS['plugin_manager']->addHook($name, $callback);
}

function run_hook($name, ...$args) {
    $GLOBALS['plugin_manager']->runHook($name, ...$args);
}