# Forum Nuovo

Minimal, extensible forum software with zero dependencies. Upload files and run - no Composer, no Docker, no deployment needed.

## Installation
1. Upload all files to a PHP-enabled web server
2. Visit the site - SQLite database auto-creates
3. Login with admin/changeme123 (change password immediately!)

## Architecture

### Single-File MVC (index.php)
- All core logic in one file for easy upload
- SQLite database with automatic migration
- Session-based authentication
- Simple routing via `$_GET['action']`

### Directory Structure
```
/forum-nuovo/
├── index.php          # Main application (all-in-one)
├── views/             # Template files
│   ├── home.php       # Thread listing
│   ├── thread.php     # Thread view
│   ├── login.php      # Login form
│   ├── register.php   # Registration form
│   └── admin.php      # Moderation panel
├── plugins/           # Plugin system (empty by default)
└── data/              # SQLite database storage
```

### Plugin System (Planned)
Plugins are PHP files in `plugins/` that can:
- Hook into events via `add_hook('event', $callback)`
- Run on page load, thread creation, etc.
- Currently stubbed - needs full implementation

## Current Features (Implemented)
- Thread listing with author info
- Thread creation (authenticated users)
- Reply posting (authenticated users)  
- User registration
- User login/logout
- Admin panel with moderation
- Pending content approval/deletion

## Missing Features (TODO)
- Create remaining view templates:
  - `views/new_thread.php` - New thread form
  - `views/login.php` - Login form (stub exists but incomplete)
  - `views/register.php` - Registration form (stub exists but incomplete)
  - `views/admin.php` - Admin moderation panel (stub exists but incomplete)
- Plugin hook integration in ForumApp class
- Category management
- Post editing/deletion
- User profile pages
- Password recovery
- Email notifications
- Rich text editor
- File upload support

## Design Goals
- Zero external dependencies (pure PHP 7.4+)
- Single file upload installation
- SQLite by default, MySQL optional
- Hook-based plugin system
- Bootstrap-ready (CSS classes included)