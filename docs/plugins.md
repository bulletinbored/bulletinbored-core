# Plugins

Create plugins as single PHP files in `plugins/`.

## Plugin File Format

Every plugin is a PHP file with an `{name}_init()` function:

```php
<?php
/**
 * Plugin Name: Analytics
 * Version: 1.0.0
 * Author: Developer
 * Description: Logs page visits
 */

function analytics_init() {
    // Register hooks
    global $pluginManager;
    $pluginManager->add_hook('after_post', function($threadId, $postId) {
        // ...
    });
}
```

## Installed Plugins Folder

By default, plugins are placed directly in `plugins/` (e.g., `plugins/analytics.php`).

If you install a plugin via ZIP from the dashboard, the ZIP contents are extracted into `plugins/`, so your ZIP can contain either a single `.php` file or a folder with multiple files.

## Hook System

Plugins register callbacks via `$pluginManager->add_hook('event', $callback)`.

### Core Events

| Event | Arguments | When |
|---|---|---|
| `after_thread` | `$threadId` | After a thread is created |
| `after_post` | `$threadId`, `$postId` | After a reply is posted |
| `user_registered` | `$userId`, `$username` | After a user registers |

### Example: Log new threads

```php
function analytics_init() {
    global $pluginManager;
    $pluginManager->add_hook('after_thread', function($threadId) {
        $log = date('c') . " Thread $threadId created\n";
        file_put_contents(__DIR__.'/../data/threads.log', $log, FILE_APPEND);
    });
}
```

## Shipping Plugins as ZIP

To distribute a plugin as a ZIP package:

1. Package your plugin PHP file(s) into a ZIP
2. The plugin file(s) must be at the root of the ZIP or inside a single folder
3. Upload via **Admin Panel → Plugins → Install Plugin**

## Managing Plugins

- **Enable / Disable**: toggle plugin state without deleting the file
- **Delete**: removes the PHP file from `plugins/` and clears state from `data/plugins.json`
- **Install**: upload a ZIP to add one or more plugin files

## Accessing the Plugin Manager

From PHP code:

```php
$pluginManager->getAll();
$pluginManager->getEnabled();
$pluginManager->enable('analytics');
$pluginManager->disable('analytics');
$pluginManager->add_hook('after_post', $callback);
$pluginManager->run_hook('after_post', $arg1, $arg2);
```
