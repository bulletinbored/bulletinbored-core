# Plugins

Create plugins as single PHP files in `plugins/` or as subdirectories with a `manifest.json` and a bootstrap file. Contributions and distributed plugins are accepted under the terms of the [CLA.md](../CLA.md).

## Plugin Conventions

 - **File-based plugin**: a single PHP file in `plugins/`
- **Folder-based plugin**: a subdirectory in `plugins/` with a `manifest.json` and a bootstrap PHP file (e.g., `plugins/editbored/`)
- Folder-based plugins are required when the plugin needs extra assets (CSS, JS, images, lang files)

## Plugin Metadata

File-based plugins expose metadata via PHPDoc comments in the bootstrap file:

```php
/**
 * Plugin Name: MyPlugin
 * Version: 1.0.0
 * Author: Developer
 * Description: Example plugin
 */
```

Folder-based plugins use `manifest.json`:

```json
{
    "name": "editbored",
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
| `footer_before_render` | — | Before the footer is rendered |

### Example

```php
function myplugin_init() {
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
├── hellobored/                 # Example folder-based plugin
│   ├── manifest.json           # Required: metadata
│   ├── hellobored.php          # Required: bootstrap with hellobored_init()
│   ├── assets/
│   │   ├── css/
│   │   │   └── hellobored.css  # Example styles
│   │   └── js/
│   │       └── hellobored.js   # Example logic
│   ├── upload.php              # Optional: custom endpoint
│   ├── lang/
│   │   └── en.php              # Optional: translations
│   └── vendor/                 # Optional: third-party assets
└── someplugin.php              # Example file-based plugin
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

## Example: editbored Plugin

The forum ships with the **editbored** plugin as an example of a folder-based plugin:

- Uses a WYSIWYG Markdown editor on thread/reply forms
- Injects CSS, JS, and user data via hooks
- Provides an image upload endpoint (`upload.php`)
- Implements @mentions with a user autocomplete dropdown

See `plugins/editbored/` for the full implementation.

## Example: bellbored Plugin

The forum ships with the **bellbored** notification center plugin:

- Adds a notification bell icon to the navbar for logged-in users
- Creates notifications when new threads or replies are posted in watched threads
- Sends welcome notifications to newly registered users
- Stores notifications in a dedicated `notifications` database table
- Supports marking notifications as read (single or bulk)
- AJAX-powered dropdown with unread count badge

### Directory Structure

```
plugins/
└── bellbored/
    ├── manifest.json
    ├── bellbored.php
    ├── api.php
    ├── assets/
    │   ├── css/
    │   │   └── bellbored.css
    │   └── js/
    │       └── bellbored.js
    └── lang/
        └── en.php
```

## Example: textmebored Plugin

The forum ships with the **textmebored** private messaging plugin:

- Adds an envelope icon to the navbar for logged-in users
- Provides a conversation list with unread message indicators
- Opens a modal conversation view for reading and sending private messages
- Stores messages in a dedicated `private_messages` database table
- Supports real-time message sending via AJAX
- Auto-marks messages as read when opening a conversation

### Directory Structure

```
plugins/
└── textmebored/
    ├── manifest.json
    ├── textmebored.php
    ├── api.php
    ├── assets/
    │   ├── css/
    │   │   └── textmebored.css
    │   └── js/
    │       └── textmebored.js
    └── lang/
        └── en.php
```

## Localization

Plugins can be localized independently from the core. Each plugin gets its own
translation **scope**, so plugin strings never collide with the core or with
other plugins (e.g. two plugins may both use a `title` key without conflict).

Folder-based plugins only (file-based plugins cannot carry lang files): place
translation files under `lang/` using the language code as filename:

```
plugins/myplugin/
└── lang/
    ├── en.php
    └── it.php
```

Each file returns an associative array:

```php
<?php
return [
    'bold' => 'Bold',
    'italic' => 'Italic',
];
```

The strings are loaded automatically into the `plugin:<name>` scope (e.g.
`plugin:myplugin`) based on the active language, and are available before the
plugin's `init` hook runs.

### Usage

Use the `pt()` helper, which is equivalent to `t($key, $params, 'plugin:<name>')`:

```php
echo pt('myplugin', 'bold');                 // -> 'Bold'
echo pt('myplugin', 'hello', ['name' => 'Joe']); // with {name} placeholder
```

You may also call the core translation function directly with an explicit scope:

```php
echo t('bold', [], 'plugin:myplugin');
```

If a key is missing in the plugin's language file, the key itself is returned
(untranslated) — so a plugin that ships no `lang/` directory still works
unchanged. The core translation function `t($key, $params)` continues to resolve
only from the `core` scope and is unaffected by plugin translations.

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

// Localization
$pluginManager->loadTranslations($lang);   // loads plugin/lang/{$lang}.php into the plugin:<name> scope

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
