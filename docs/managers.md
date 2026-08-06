# Managers

## Plugin Manager (`admin_plugins`)

The Plugin Manager lists all discovered plugins, shows their metadata (name, version, author, description), and allows enabling/disabling them from the admin panel.

- Plugins are PHP files or folders in `plugins/`
- Metadata is parsed from the file header:
  ```php
   /**
    * Plugin Name: MyPlugin
    * Version: 1.0.0
    * Author: Developer
    * Description: Example plugin
    */
   function myplugin_init() {
       // your code
   }
  ```
- Plugin state is stored in `data/plugins.json`
- Install plugins directly from the dashboard by uploading a ZIP file containing one or more PHP plugin files
- Delete plugins directly from the dashboard

## Hook System

Plugins can register callbacks that run when the core fires specific events:

```php
function myplugin_init() {
    global $pluginManager;
    $pluginManager->addHook('after_post', function($threadId, $postId) {
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

The Update Manager tracks installed versions of the core, plugins, and themes, and can apply updates.

- Version tracking is stored in `data/updates.json`
- Core version is loaded dynamically from the `VERSION` file via `config.php`
- Remote update checks require setting `'update_server'` in `config.php`

### Core updates

If `update_server` points to a GitHub repository, the Update Manager uses the GitHub Releases API to discover the latest version automatically. No `versions.json` is required.

### Plugin and theme updates

Plugins and themes are checked against the repositories listed in `data/catalog.json`. If a catalog entry includes a `repo` URL pointing to GitHub, the Update Manager queries the GitHub Releases API for the latest tag.

### GitHub token

GitHub API has a rate limit of 60 requests/hour for unauthenticated requests. You can provide a `github_token` in `config.php` to raise the limit to 5000 requests/hour. See [Configuration](configuration.md#github-token) for details.

### Legacy update server

If `update_server` is not a GitHub URL, the Update Manager falls back to fetching `versions.json` from that server:

```json
{
    "core": {"version": "1.1.0"},
    "plugins": {
        "hellobored": {"version": "1.0.0", "url": "https://example.com/plugins/hellobored.zip"}
    },
    "themes": {
        "default": {"version": "1.1.0", "url": "https://example.com/themes/default.zip"}
    }
}
```

### Applying updates

- Core updates are downloaded automatically from GitHub releases and extracted into the forum root.
- Plugin and theme updates can be downloaded automatically from GitHub if a `repo` URL is defined in `catalog.json`.
- ZIP upload via the admin panel is still supported as a fallback for plugin/theme updates.
- After extraction, version metadata is updated automatically.
