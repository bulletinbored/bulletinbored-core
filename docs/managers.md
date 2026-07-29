# Managers

## Plugin Manager (`admin_plugins`)

The Plugin Manager lists all discovered plugins, shows their metadata (name, version, author, description), and allows enabling/disabling them from the admin panel.

- Plugins are single PHP files in `plugins/` (e.g., `plugins/analytics.php`)
- Metadata is parsed from the file header:
  ```php
  /**
   * Plugin Name: Analytics
   * Version: 1.0.0
   * Author: Developer
   * Description: Logs page visits
   */
  function analytics_init() {
      // your code
  }
  ```
- Plugin state is stored in `data/plugins.json`
- Install plugins directly from the dashboard by uploading a ZIP file containing one or more PHP plugin files
- Delete plugins directly from the dashboard

## Hook System

Plugins can register callbacks that run when the core fires specific events:

```php
function analytics_init() {
    global $pluginManager;
    $pluginManager->add_hook('after_post', function($threadId, $postId) {
        // react to new posts
    });
}
```

Core events currently wired:
- `after_thread` — fired after a thread is created (receives `$threadId`)
- `after_post` — fired after a reply is posted (receives `$threadId`, `$postId`)
- `user_registered` — fired after a user registers (receives `$userId`, `$username`)

## Theme Manager (`admin_themes`)

The Theme Manager discovers all themes in `themes/`, tracks the active theme, and provides CSS URLs/paths.

- Themes are subdirectories in `themes/` containing a `style.css`
- Optional `manifest.json` for metadata:
  ```json
  {
      "name": "My Theme",
      "version": "1.0.0",
      "author": "Author Name",
      "description": "Theme description"
  }
  ```
- Theme state is stored in `data/themes.json`
- Switch themes from **Admin Panel → Themes**
- Install themes directly from the dashboard by uploading a ZIP file containing a folder with `style.css` and optional `manifest.json`
- Delete themes directly from the dashboard (default theme is protected)

## Language Manager (`admin_langs`)

The Language Manager lets you upload and delete localization PHP files from the dashboard.

- Upload a file by choosing a language code (e.g. `fr`) and selecting a PHP file that returns a translation array
- Files are saved to `lang/{code}.php`
- Delete any language file except the default one
- Language files are automatically picked up by the translation system

## Update Manager (`admin_updates`)

The Update Manager tracks installed versions of the core, plugins, and themes, and can apply updates from ZIP packages.

- Version tracking is stored in `data/updates.json`
- Core version is defined in `config.php` as `'version' => '1.0.0'`
- Remote update checks require setting `'update_server'` in `config.php`
- The update server must serve a `versions.json` file:
  ```json
  {
      "core": {"version": "1.1.0"},
      "plugins": {
          "analytics": {"version": "1.2.0", "url": "https://example.com/plugins/analytics.zip"}
      },
      "themes": {
          "default": {"version": "1.1.0", "url": "https://example.com/themes/default.zip"}
      }
  }
  ```
- Updates are applied by uploading ZIP packages via the admin panel
- Zips are extracted into the forum root, and version metadata is updated automatically
