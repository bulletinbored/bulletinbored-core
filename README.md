# Forum Nuovo

Minimal, extensible forum software with zero dependencies. Upload files and run — no Composer, no Docker, no deployment needed.

## Installation
1. Upload all files to a PHP-enabled web server (PHP 8.x, PDO/SQLite or PDO/MySQL extension)
2. Ensure the `data/` and `uploads/` directories are writable by the web server
3. Visit the site — SQLite database auto-creates on first access
4. Login with admin/changeme123 (change password immediately!)

## Architecture

### Single-File MVC (index.php)
- All core logic in one file for easy upload
- SQLite by default, MySQL configurable via `$config['db_driver']`
- Session-based authentication
- Simple routing via `$_GET['action']`

### Directory Structure
```
/forum-nuovo/
├── index.php          # Main application (all-in-one)
├── views/             # Template files
│   ├── home.php       # Thread listing with categories + search + pagination
│   ├── thread.php     # Thread view with replies + attachments + pagination
│   ├── new_thread.php # New thread form with category + file upload
│   ├── edit_post.php  # Edit post form
│   ├── login.php      # Login form
│   ├── register.php   # Registration form
│   ├── profile.php    # User profile page
│   ├── edit_profile.php # Edit profile form
│   └── admin.php      # Admin panel (categories, moderation)
├── plugins/           # Plugin system (empty by default)
├── uploads/           # File upload storage (auto-created)
├── data/              # SQLite database storage (auto-created)
└── README.md
```

### Plugin System
Plugins are PHP files in `plugins/` that define an `{name}_init()` function:
- `add_hook('event', $callback)` — register a hook
- `run_hook('event', ...$args)` — fire a hook
- Plugins are auto-loaded on every request

Example plugin in `plugins/analytics.php` demonstrates the pattern.

## Current Features (Implemented)
- Thread listing with categories, author info, and pagination
- Thread creation with category selection and file attachments (authenticated users)
- Reply posting (authenticated users)
- Post editing and deletion by author or admin
- User registration and login/logout
- User profile pages with thread history
- Profile editing (username, password)
- Admin panel with category CRUD and thread moderation (approve/delete)
- Search functionality across threads, categories, and users
- File upload support for thread attachments
- SQLite auto-migration with MySQL support
- CSRF protection on all forms
- Input sanitization (trim, stripslashes, htmlspecialchars)
- Hook-based plugin system

## Missing Features (TODO)
- Password recovery / reset
- Email notifications
- Rich text editor (WYSIWYG) — deferred per user request
- Full plugin hook integration (all events not yet wired)

## Design Goals
- Zero external dependencies (pure PHP 8.x)
- Single file upload installation
- SQLite by default, MySQL configurable
- Hook-based plugin system
- Clean CSS (no framework dependency)