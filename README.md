# bulletinbored

Minimal, extensible forum software with zero dependencies. Upload files and run — no Composer, no Docker, no deployment needed.

## Installation
1. Upload all files to a PHP-enabled web server (PHP 8.x, PDO/SQLite or PDO/MySQL extension)
2. Ensure Apache `mod_rewrite` is enabled (for SEO-friendly URLs)
3. Ensure the `data/`, `uploads/`, and `uploads/avatars/` directories are writable by the web server
4. If installed in a subdirectory (e.g., `/forum`), set `'base_url' => '/forum'` in `config.php`
5. Visit the site — SQLite database auto-creates on first access
6. Login with admin/changeme123 (change password immediately!)

## Architecture

### Single-File MVC (index.php)
- All core logic in one file for easy upload
- SQLite by default, MySQL configurable via `config.php`
- Session-based authentication
- Simple routing via `$_GET['action']`
- SEO-friendly URLs via `.htaccess` rewrite rules

### SEO-Friendly URLs
Clean URLs are supported via `.htaccess` rewrite rules:
- `/thread/{id}-{slug}` — single thread view
- `/category/{id}-{slug}` — category view
- `/u/{username}` — user profile

Old query-string URLs (`?action=thread&id=1`) still work internally.

### Directory Structure
```
/bulletinbored/
├── config.php             # Configuration (database, email, site, theme, localization)
├── index.php              # Main application (all-in-one)
├── .htaccess              # SEO-friendly URL rewrites
├── views/                 # Template files
│   ├── header.php         # Shared frontend header/footer (loads theme CSS)
│   ├── home.php           # Thread listing with sidebar categories + pagination
│   ├── thread.php         # Thread view with replies, attachments, pagination
│   ├── new_thread.php     # New thread form with category + file upload
│   ├── edit_post.php      # Edit post form
│   ├── login.php          # Login form with "Forgot Password" link
│   ├── register.php       # Registration form with email field
│   ├── profile.php        # User profile page with avatar, role badge, threads
│   ├── edit_profile.php   # Edit profile form (username, email, password, avatar)
│   ├── admin.php          # Admin dashboard (Bootstrap 5)
│   ├── admin_header.php   # Admin panel header
│   ├── admin_footer.php   # Admin panel footer
│   ├── admin_moderation.php # Moderation queue
│   ├── admin_categories.php # Category management
│   ├── admin_users.php    # User management
│   ├── admin_settings.php # Site settings
│   ├── forgot_password.php # Password reset request form
│   └── reset_password.php  # Password reset form with token
├── themes/                # Theme system (like plugins)
│   └── default/
│       └── style.css      # Default theme styles
├── plugins/               # Plugin system (empty by default)
├── uploads/               # File upload storage (auto-created)
│   └── avatars/           # User avatar uploads
├── data/                  # SQLite database storage (auto-created)
├── lang/                  # Localization files
│   ├── en.php             # English translations
│   └── it.php             # Italian translations
└── README.md
```

### Theme System
Themes work like plugins — each theme is a folder in `themes/` with a `style.css` file:
- Configure active theme in `config.php`: `'theme' => 'default'`
- Create custom themes by adding folders in `themes/`
- Theme CSS is automatically loaded by `views/header.php`
- All frontend pages use the active theme
- Admin panel uses Bootstrap 5 default styles

### Plugin System
Plugins are PHP files in `plugins/` that define an `{name}_init()` function:
- `add_hook('event', $callback)` — register a hook
- `run_hook('event', ...$args)` — fire a hook
- Plugins are auto-loaded on every request

Example plugin in `plugins/analytics.php` demonstrates the pattern.

## Current Features (Implemented)
- **Theme system** — easy CSS customization via `themes/` directory
- **Frontend**: Clean Bootstrap 5 dark navbar theme (customizable)
- **Admin Panel**: Simple Bootstrap 5 dashboard with stat cards
- Thread listing with categories, author info, and pagination
- Thread creation with category selection and file attachments (authenticated users)
- Reply posting (authenticated users)
- Post editing and deletion by author or admin
- User registration with email field and welcome email
- User login/logout with email stored in session
- User profile pages with avatar, role badge, and thread history
- Profile editing (username, email, password)
- **User avatar upload** (authenticated users)
- **Email notification on new reply to watched threads**
- **Thread watching** — watch/unwatch threads
- Admin dashboard with stat cards (Users, Threads, Posts, Pending)
- Category management (CRUD operations)
- Thread moderation (approve/delete pending threads)
- User list with email, role, and registration date
- Search functionality across threads, categories, and users
- File upload support for thread attachments
- **Password recovery / reset** via email
- **Email notifications** (welcome email, password reset, reset confirmation)
- SQLite auto-migration with MySQL support
- CSRF protection on all forms
- Input sanitization (trim, stripslashes, htmlspecialchars)
- Hook-based plugin system
- **SEO-friendly URLs** via `.htaccess` rewrite rules
- **Localization (i18n) infrastructure** with English and Italian translations

## Configuration
Edit `config.php` to customize your installation:

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
    'theme' => 'default',              // Theme name (folder in themes/)
    
    // Localization
    'default_lang' => 'en',            // Default language (folder in lang/)
    'available_langs' => ['en', 'it'], // Available languages
    
    // Uploads
    'avatar_max_size' => 2 * 1024 * 1024, // 2MB
    'avatar_allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    
    // URL
    'base_url' => '', // Leave empty for auto-detection, or set to '/forum-nuovo'
];
```

## Creating Custom Themes
1. Create a new folder in `themes/` (e.g., `themes/mytheme/`)
2. Create a `style.css` file in that folder
3. Update `config.php` to use your theme: `'theme' => 'mytheme'`
4. The theme CSS is automatically loaded on all frontend pages

Example `themes/mytheme/style.css`:
```css
body { background-color: #fff; }
.navbar-forum { background-color: #your-color; }
/* ... more styles ... */
```

## Localization (i18n)
The forum includes a basic translation system managed from the admin dashboard:

1. Create translation files in `lang/` (e.g., `lang/en.php`, `lang/it.php`)
2. Each file returns an associative array of key => translated string
3. Go to **Admin Panel → Settings** to configure:
   - **Default Language**: the language used by default
   - **Available Languages**: comma-separated list of enabled language codes
4. Use `t('key')` in views and index.php to output translated strings

The forum ships with English (`lang/en.php`) and Italian (`lang/it.php`) translations.


## Missing Features (TODO)
- Rich text editor (WYSIWYG) — through plugin. Which one should I choose?
- Full plugin hook integration (all events not yet wired)
- SMTP email support (currently uses PHP `mail()` function)
- Private messaging system (through plugin?)
- Create a simple and modern installer
- Update manager (core and plugin/themes)

## Design Goals
- Zero external dependencies (pure PHP 8.x)
- Single file upload installation
- SQLite by default, MySQL configurable
- Hook-based plugin system
- Theme system for easy customization
- Bootstrap 5 UI