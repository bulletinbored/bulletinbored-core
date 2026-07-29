# Configuration

Edit `config.php` to customize your installation.

```php
$config = [
    // Database
    'db_driver' => 'sqlite',          // 'sqlite' or 'mysql'
    'db_path' => __DIR__.'/data/database.sqlite',
    'db_host' => 'localhost',
    'db_name' => 'forum',
    'db_user' => 'root',
    'db_pass' => '',
    
    // Site
    'site_name' => 'bulletinbored',
    'admin_user' => 'admin',
    'admin_pass' => 'changeme123',
    
    // Email (for password reset, notifications)
    'mail_from' => 'noreply@yourdomain.com',
    'mail_from_name' => 'bulletinbored',
    'mail_method' => 'mail',          // 'mail' for PHP mail(), 'smtp' for SMTP
    
    // Theme
    'theme' => 'freshbored',              // Theme name (folder in themes/)
    
    // Uploads
    'avatar_max_size' => 2 * 1024 * 1024, // 2MB
    'avatar_allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    
    // URL
    'base_url' => '', // Leave empty for auto-detection, or set to '/forum-nuovo'
    'version' => trim(file_get_contents(__DIR__.'/VERSION')),
    'plugin_manifest' => __DIR__.'/data/plugins.json',
    'theme_manifest' => __DIR__.'/data/themes.json',
    'update_manifest' => __DIR__.'/data/updates.json',
    'update_server' => '', // Remote update server URL (empty to disable)
];
```

## Version

Do not hardcode the version; the application reads it from the `VERSION` file at the project root, e.g. `0.1.0`. See [docs/versioning.md](versioning.md) for how to manage releases.

## Database

SQLite requires no additional setup. The database file is created automatically in `data/database.sqlite`.

### MySQL

To use MySQL, set:

```php
'db_driver' => 'mysql',
'db_host' => 'localhost',
'db_name' => 'forum',
'db_user' => 'root',
'db_pass' => '',
```

Tables are created automatically on first access.

## Email

The forum uses PHP's `mail()` function by default. For SMTP support, set:

```php
'mail_method' => 'smtp',
'mail_host' => 'smtp.example.com',
'mail_port' => 587,
'mail_username' => 'user@example.com',
'mail_password' => 'secret',
```

## Theme

Set the active theme by folder name:

```php
'theme' => 'freshbored',
```

## Localization

Configure the default language and available languages:

```php
'default_lang' => 'en',
'available_langs' => ['en', 'it'],
```

## Updates

Configure a remote update server if you want automatic update checking:

```php
'update_server' => 'https://updates.example.com',
```

The server must serve a `versions.json` file. See [Update Manager](managers.md#update-manager) for details.
