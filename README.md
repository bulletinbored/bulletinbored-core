# bulletinbored

Minimal, extensible forum software with zero dependencies. Upload files and run — no Composer, no Docker, no deployment needed.

## Installation

1. Upload all files to a PHP-enabled web server (PHP 8.x, PDO/SQLite or PDO/MySQL extension)
2. Ensure Apache `mod_rewrite` is enabled (for SEO-friendly URLs)
3. Ensure the `data/`, `uploads/`, and `uploads/avatars/` directories are writable by the web server
4. If installed in a subdirectory (e.g., `/forum`), set `'base_url' => '/forum'` in `config.php`
5. Visit the site — SQLite database auto-creates on first access
6. Login with admin/changeme123 (change password immediately!)

## Features

- Threaded discussions with categories and pagination
- User registration, profiles, avatars
- File attachments on threads and replies
- Thread watching with email notifications
- Search across threads, categories, and users
- Password reset via email
- Admin dashboard: categories, moderation, users, settings
- **Plugin Manager** — install, enable, disable, and delete plugins from the dashboard
- **Theme Manager** — install, activate, and delete themes from the dashboard; ships with **Freshbored**
- **Update Manager** — check for updates and apply ZIP packages for core, plugins, and themes
- **Language Manager** — upload and delete localization files from the dashboard
- Hook-based plugin system
- SEO-friendly URLs
- Localization (i18n) infrastructure

## Configuration

See `docs/configuration.md` for the full list of options.

```php
$config = [
    'db_driver' => 'sqlite',
    'db_path' => __DIR__.'/data/database.sqlite',
    'site_name' => 'bulletinbored',
    'admin_user' => 'admin',
    'admin_pass' => 'changeme123',
    'theme' => 'freshbored',
    'base_url' => '',
];
```

## Documentation

- [Architecture](docs/architecture.md)
- [Configuration](docs/configuration.md)
- [Managers](docs/managers.md)
- [Localization](docs/localization.md)
- [Theme Development](docs/themes.md)
- [Plugin Development](docs/plugins.md)

## License

MIT
