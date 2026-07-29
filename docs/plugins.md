# Plugins

Create plugins as single PHP files in `plugins/` or as subdirectories with a `manifest.json` and a bootstrap file. Contributions and distributed plugins are accepted under the terms of the [CLA.md](../CLA.md).

## Plugin Conventions

- **File-based plugin**: a single PHP file in `plugins/` (e.g., `plugins/analytics.php`)
- **Folder-based plugin**: a subdirectory in `plugins/` with a `manifest.json` and a bootstrap PHP file (e.g., `plugins/editbored/`)
- Folder-based plugins are required when the plugin needs extra assets (CSS, JS, images, lang files)

## Plugin Metadata

File-based plugins expose metadata via PHPDoc comments in the bootstrap file:

```php
/**
 * Plugin Name: Analytics
 * Version: 1.0.0
 * Author: mlzog
 * Description: Logs page visits
 */
```

Folder-based plugins use `manifest.json`:

```json
{
    "name": "Editbored",
    "version": "1.0.0",
    "author": "mlzog",
    "description": "WYSIWYG Markdown editor",
    "bootstrap": "editbored.php"
}
```

## Hook System

Plugins register callbacks via `$pluginManager->addHook('event', $callback)`.

## Core Events

| Event | Arguments | When |
|---|---|---|
| `after_thread` | `$threadId` | After a thread is created |
| `after_post` | `$threadId`, `$postId` | After a reply is posted |
| `user_registered` | `$userId`, `$username` | After a user registers |
| `before_render` | — | Before a page is rendered |
| `frontend_before_render` | — | Before a frontend page is rendered |
| `admin_before_render` | — | Before an admin page is rendered |

### Example

```php
function analytics_init() {
    global $pluginManager;
    $pluginManager->addHook('after_post', function($threadId, $postId) {
        // react to new posts
    });
}
```

## Asset Loading

Plugins can inject assets (CSS, JS) into the page via hooks:

```php
function myplugin_init() {
    global $pluginManager;
    $pluginManager->addHook('frontend_before_render', function() {
        echo '<link href="/plugins/myplugin/assets/css/myplugin.css" rel="stylesheet">' . "\n";
        echo '<script src="/plugins/myplugin/assets/js/myplugin.js"></script>' . "\n";
    });
}
```

## Global Data for JS

You can pass data to your JS via global variables:

```php
function myplugin_init() {
    global $pluginManager;
    $pluginManager->addHook('frontend_before_render', function() {
        echo '<script>';
        echo 'window.MyPlugin = window.MyPlugin || {};';
        echo 'window.MyPlugin.endpoint = "' . htmlspecialchars('/plugins/myplugin/endpoint.php', ENT_QUOTES) . '";';
        echo 'window.MyPlugin.csrfToken = "' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) . '";';
        echo '</script>' . "\n";
    });
}
```

## Asset Loading

Plugins can inject assets (CSS, JS) into the page via hooks:

```php
function myplugin_init() {
    global $pluginManager;
    $pluginManager->addHook('frontend_before_render', function() {
        echo '<link href="/plugins/myplugin/assets/css/myplugin.css" rel="stylesheet">' . "\n";
        echo '<script src="/plugins/myplugin/assets/js/myplugin.js"></script>' . "\n";
    });
}

## Global Data for JS

You can pass data to your JS via global variables:

```php
function myplugin_init() {
    global $pluginManager;
    $pluginManager->addHook('frontend_before_render', function() {
        echo '<script>';
        echo 'window.MyPlugin = window.MyPlugin || {};';
        echo 'window.MyPlugin.endpoint = "' . htmlspecialchars('/plugins/myplugin/endpoint.php', ENT_QUOTES) . '";';
        echo 'window.MyPlugin.csrfToken = "' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) . '";';
        echo '</script>' . "\n";
    });
}
```

## Directory Structure

```
plugins/
├── analytics.php               # File-based plugin
└── editbored/                  # Folder-based plugin
    ├── manifest.json           # Required: metadata
    ├── editbored.php           # Required: bootstrap with editbored_init()
    ├── assets/
    │   ├── css/
    │   │   └── editbored.css   # Editor styles
    │   └── js/
    │       ├── editbored.js    # Editor logic
    │       └── mentions.js     # Mentions autocomplete
    ├── upload.php              # Optional: custom endpoint
    ├── lang/
    │   └── en.php              # Optional: translations
    └── vendor/                 # Optional: third-party assets
```

## Shipping as ZIP

To distribute a plugin as a ZIP package:

1. Package your plugin so that the plugin folder is at the root of the ZIP
2. For file-based plugins, the PHP file should be at the root of the ZIP
3. For folder-based plugins, the folder should be at the root of the ZIP
4. Upload via **Admin Panel → Plugins → Install Plugin**

Recommended ZIP layout:
```
myplugin-1.0.0.zip
├── myplugin/
│   ├── manifest.json
│   ├── myplugin.php
│   └── assets/...
```

The installer automatically detects a single top-level folder and flattens it.

## Managing Plugins

- **Enable / Disable**: toggle plugin state without deleting files
- **Delete**: removes the plugin files and clears state from `data/plugins.json`
- **Install**: upload a ZIP to add the plugin
- **Update**: the Update Manager can apply new versions as ZIP packages

## Example: Editbored Plugin

The forum ships with the **Editbored** plugin as an example of a folder-based plugin:

- Uses a WYSIWYG Markdown editor on thread/reply forms
- Injects CSS, JS, and user data via hooks
- Provides an image upload endpoint (`upload.php`)
- Implements @mentions with a user autocomplete dropdown

See `plugins/editbored/` for the full implementation.

## Plugin Manager API

```php
// Discovery
$pluginManager->discover();
$pluginManager->getAll();
$pluginManager->getEnabled();
$pluginManager->getByName('editbored');

// State
$pluginManager->isEnabled('editbored');
$pluginManager->enable('editbored');
$pluginManager->disable('editbored');

// Lifecycle
$pluginManager->loadEnabled();
$pluginManager->installFromZip('/path/to/plugin.zip');
$pluginManager->delete('editbored');

// Versioning
$pluginManager->getVersion('editbored');

// Hooks
$pluginManager->addHook('after_thread', $callback);
$pluginManager->removeHook('after_thread', $callback);
$pluginManager->runHook('after_thread', $threadId);
```
