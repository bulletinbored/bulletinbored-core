# bulletinbored

Minimal, extensible forum software with zero dependencies. Upload files and run — no Composer, no Docker, no deployment needed.

Current version: **0.1.0**

## Installation

1. Upload all files to a PHP-enabled web server (PHP 8.x, PDO/SQLite or PDO/MySQL extension)
2. Ensure Apache `mod_rewrite` is enabled (for SEO-friendly URLs)
3. Ensure the `data/`, `uploads/`, and `uploads/avatars/` directories are writable by the web server
4. Visit the site in your browser. If `config.php` is missing, the 2-step installer starts automatically:
   - **Step 1**: Choose your database (SQLite or MySQL) and test the connection
   - **Step 2**: Set your site name, administrator account, and email
5. The installer creates `config.php` and the database automatically
6. Log in with the administrator credentials you just created

### Manual installation

If you prefer to configure `config.php` yourself instead of using the web installer, copy `config-sample.php` to `config.php` and set your database and site settings manually. Once `config.php` is in place, visiting the site will initialize the database on first access.

See `docs/configuration.md` for the full list of options.

```php
// config.php
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
- [Versioning](docs/versioning.md)

## License

BSD Zero Clause — see [LICENSE](LICENSE).

### Why BSD Zero Clause

Part of this code was written with AI assistance, and there is no established community around the project yet. To prevent the code from becoming abandonware and to maximize the chances that it survives and is used, the most permissive possible license has been chosen: the **BSD Zero Clause**.

If and when a community forms, any future change to the license will be decided together with the community.

Contributions are accepted under the terms of the [CLA.md](CLA.md).
