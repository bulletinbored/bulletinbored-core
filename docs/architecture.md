# Architecture

## Manager System (backend classes in `lib/`)

- **PluginManager** — handles plugin discovery, enable/disable, hook registration/execution
- **ThemeManager** — handles theme discovery, activation, and CSS URL/path resolution
- **UpdateManager** — handles version tracking and update checks for core, plugins, and themes

All managers are instantiated at the top of `index.php` and are fully integrated into the routing layer and admin panel.

## Single-File MVC (index.php)

- All core logic in one file for easy upload
- SQLite by default, MySQL configurable via `config.php`
- Session-based authentication
- Simple routing via `$_GET['action']`
- SEO-friendly URLs via `.htaccess` rewrite rules

## SEO-Friendly URLs

Clean URLs are supported via `.htaccess` rewrite rules:

- `/thread/{id}-{slug}` — single thread view
- `/category/{id}-{slug}` — category view
- `/u/{username}` — user profile

Old query-string URLs (`?action=thread&id=1`) still work internally.

## Directory Structure

```
/bulletinbored/
├── config.php             # Configuration (database, email, site, theme, localization)
├── index.php              # Main application (all-in-one)
├── .htaccess              # SEO-friendly URL rewrites
├── router.php             # Router for PHP built-in server
├── lib/                   # Backend managers (PluginManager, ThemeManager, UpdateManager)
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
│   ├── admin_plugins.php  # Plugin manager
│   ├── admin_themes.php   # Theme manager
│   ├── admin_langs.php    # Language file manager
│   ├── admin_updates.php  # Update manager
│   ├── forgot_password.php # Password reset request form
│   └── reset_password.php  # Password reset form with token
├── themes/                # Theme system (like plugins)
│   └── freshbored/
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

## Theme System

Themes work like plugins — each theme is a folder in `themes/` with a `style.css` file:

- Configure active theme in `config.php`: `'theme' => 'freshbored'`
- Create custom themes by adding folders in `themes/`
- Theme CSS is automatically loaded by `views/header.php`
- All frontend pages use the active theme
- Admin panel uses Bootstrap 5 default styles

## Plugin System

Plugins are PHP files in `plugins/` that define an `{name}_init()` function:

- `$pluginManager->add_hook('event', $callback)` — register a hook
- `$pluginManager->run_hook('event', ...$args)` — fire a hook
- Plugins are auto-loaded on every request

Example plugin in `plugins/analytics.php` demonstrates the pattern.
