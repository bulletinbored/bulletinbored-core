# bulletinbored

Minimal, extensible forum software with zero dependencies. Upload files and run — no Composer, no Docker, no deployment needed.

## Requirements

- **PHP 8.1+** with the PDO extension (`pdo_sqlite` or `pdo_mysql`)
- **mbstring** extension (UTF-8 string handling)
- **zip** extension (`ZipArchive`) — required to install or update plugins, themes, and language packs
- **Web server** — Apache, Nginx, IIS, LiteSpeed, or PHP built-in server

`curl` is optional: when it is missing, package downloads fall back to `file_get_contents()` (which requires `allow_url_fopen`).

## Installation

### Quick start (Apache)

1. Upload all files to your web server
2. Ensure `mod_rewrite` is enabled (the `.htaccess` file is included)
3. Ensure the `data/`, `uploads/`, and `uploads/avatars/` directories are writable
4. Visit the site in your browser — the installer starts automatically

### Nginx

1. Upload all files to your web server
2. Copy `nginx.conf` to your server's site configuration (e.g. `/etc/nginx/sites-available/bulletinbored`)
3. Adjust `server_name`, `root`, and the PHP-FPM socket path
4. Enable the site and reload Nginx
5. Visit the site in your browser — the installer starts automatically

```bash
sudo ln -s /etc/nginx/sites-available/bulletinbored /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

**Important:** Nginx does not read `.htaccess` files. The `nginx.conf` file includes all required rewrite rules and security blocks. Without it, pretty URLs and data directory protection will not work.

### IIS (Windows Server)

1. Upload all files to your web server
2. Ensure the **URL Rewrite Module** is installed (https://www.iis.net/downloads/microsoft/url-rewrite)
3. The included `web.config` file provides all rewrite rules
4. Ensure the `data/`, `uploads/`, and `uploads/avatars/` directories are writable by the application pool identity
5. Visit the site in your browser — the installer starts automatically

### LiteSpeed

Compatible with Apache `.htaccess` out of the box. No additional configuration needed.

### PHP built-in server (development only)

```bash
php -S localhost:8080 router.php
```

The `router.php` front controller handles URL resolution and blocks direct
access to secrets, which the built-in server would otherwise serve as static
files. No rewrite rules are needed.

## Installer steps

Once your server is configured, the web installer runs in 3 steps:

- **Step 1**: Choose your database (SQLite or MySQL) and test the connection
- **Step 2**: Set your site name, administrator account, and email
- **Step 3**: Optionally install suggested plugins to make the installation more complete. The core ships only the basic forum features, but you can install the suggested plugins now or add them later from the admin panel.

The installer creates `config.json` and the database automatically.

### Security reminder

After installation completes, **delete the installer files** from your server:
- `install.php`
- `install2.php`
- `install3.php`

Leaving them in place is a security risk.

## Production checklist

- **Delete the installer files** (`install.php`, `install2.php`, `install3.php`) after setup. Core updates
  remove them automatically. `api/install.php` is **not** an installer: it is the admin endpoint used
  to install catalog plugins/themes and it verifies the administrator against the database on every call.
- **`display_errors = Off`** and `expose_php = Off` in `php.ini` (the `doctor` command warns if they are on).
- **HTTPS**: enable `force_https` / `cookie_secure`; behind a proxy set `X-Forwarded-Proto`.
- **Confirm sensitive paths return 403**: `data/`, `uploads/private/`, `config.json`, `bb.php`, `router.php`. The shipped `.htaccess`, `nginx.conf`, `web.config` and `router.php` handle this — keep the rules if you customise them.
- **Change the admin password** if it was auto-generated during a manual install (see below).
- **Keep `data/`, `uploads/`, `uploads/avatars/`, `uploads/private/` writable** by the web server user.
- **Back up** `config.json` and the database; `data/` also holds sessions, logs and update metadata.
- **Keep `plugin_verify_files` / `theme_verify_files` enabled** and trust only extension sources you have reviewed.

On a manual install without `admin_pass` in `config.json`, the first request generates
a temporary admin password and writes it to the PHP error log — change it immediately
after the first login.

## Manual installation

If you prefer to configure `config.json` yourself instead of using the web installer, copy `config-sample.json` to `config.json` and set your database and site settings manually. Once `config.json` is in place, visiting the site will initialize the database on first access.

See the [Configuration documentation](https://docs.bulletinbored.net/configuration/) for the full list of options.

```json
{
    "db_driver": "sqlite",
    "db_path": "__DIR__/data/database.sqlite",
    "site_name": "bulletinbored",
    "admin_user": "admin",
    "admin_pass": "<a-long-unique-password>",
    "theme": "freshbored",
    "base_url": ""
}
```

`admin_pass` is only read on the very first request, when the administrator account is
created. Use a unique password of at least 10 characters with upper- and lower-case
letters and a digit (the same rule the registration form enforces) — never ship a
placeholder such as `changeme123`. After the first login, change it from your profile.

## Server configuration reference

| Server | Config file | Pretty URLs | Data protection | Notes |
|---|---|---|---|---|
| **Apache** | `.htaccess` (included) | ✅ Automatic | ✅ Automatic | Requires `mod_rewrite` |
| **Nginx** | `nginx.conf` (included) | ✅ Manual setup | ✅ Manual setup | See nginx.conf for full example |
| **IIS** | `web.config` (included) | ✅ Automatic | ✅ Automatic | Requires URL Rewrite Module |
| **LiteSpeed** | `.htaccess` (included) | ✅ Automatic | ✅ Automatic | Apache-compatible |
| **PHP built-in** | None needed | ✅ Internal | N/A | Development only |

## HTTPS behind a reverse proxy

If you run Nginx or another reverse proxy that terminates SSL in front of PHP-FPM, set this header so bulletinbored detects HTTPS correctly:

```nginx
proxy_set_header X-Forwarded-Proto $scheme;
```

Without it, the `force_https` redirect may loop. You can also disable HTTPS forcing in `config.json`:

```json
{
    "force_https": false
}
```

## Documentation

Full documentation lives in the separate [`docs`](https://github.com/bulletinbored/docs) repository (published at https://docs.bulletinbored.net):

- [Architecture](https://docs.bulletinbored.net/architecture/)
- [API Reference](https://docs.bulletinbored.net/api-reference/)
- [Route Map](https://docs.bulletinbored.net/routes/)
- [Action Handlers](https://docs.bulletinbored.net/action-handlers/)
- [Configuration](https://docs.bulletinbored.net/configuration/)
- [Managers](https://docs.bulletinbored.net/managers/)
- [Localization](https://docs.bulletinbored.net/localization/)
- [Theme Development](https://docs.bulletinbored.net/themes/)
- [Plugin Development](https://docs.bulletinbored.net/plugins/)
- [Security Model](https://docs.bulletinbored.net/security/)
- [Testing](https://docs.bulletinbored.net/testing/)
- [Versioning](https://docs.bulletinbored.net/versioning/)

## License

BSD Zero Clause — see [LICENSE](LICENSE).

### Why BSD Zero Clause

Part of this code was written with AI assistance, and there is no established community around the project yet. To prevent the code from becoming abandonware and to maximize the chances that it survives and is used, the most permissive possible license has been chosen: the **BSD Zero Clause**.

If and when a community forms, any future change to the license will be decided together with the community.

Contributions are accepted under the terms of the [CLA.md](CLA.md).

## MySQL / MariaDB storage engine

bulletinbored creates its tables with `ENGINE=InnoDB`, which is what foreign
keys and transactions require. On a server whose default engine is MyISAM the
forum still runs: the foreign-key migration detects it, logs the affected
tables and completes without constraints. To enable them, convert the tables
once:

```sql
ALTER TABLE users ENGINE=InnoDB;      -- repeat for every table in the database
```

Indexed text columns are limited to 191 characters so their utf8mb4 indexes fit
the 767/1000-byte key limits of older MySQL/MariaDB builds.

## Troubleshooting a 500

Unhandled errors are written to `data/logs/error.log` (message, file/line,
request URI, PHP version, database driver — never passwords). If you cannot
reach that file, upload `tools/diag.php` to the forum root as `diag.php` and open
it: it reports PHP extensions, config sanity, directory permissions, the
database connection and the migration state. **Delete it right after use.**

## Testing notes

Run the full CLI test suite with:

```bash
php tests/run.php
```

Coverage (Xdebug or PCOV required) and a regression floor over `src/` + `lib/`:

```bash
XDEBUG_MODE=coverage php tests/run.php --coverage --min-coverage=80
XDEBUG_MODE=coverage php tests/run.php --coverage --min-area-coverage=70
```

`--min-area-coverage` applies a separate floor to the areas that must not lose
coverage: authentication/sessions, authorization, updates, package installation
and migrations. CI runs both checks.

**Known limitation**: `src/bootstrap.php` is not fully exercised by the CLI suite. It contains a `BULLETIN_TEST_MODE` early-return path that skips session startup, security headers, and HTTPS redirects — those paths should be verified through integration testing in a real PHP server environment.

## Release process

Version numbers live in `VERSION` (and must match `config-sample.json` and
`CHANGELOG.md` — `tests/VersionConsistencyTest.php` enforces it). Every user-visible
change is recorded in [CHANGELOG.md](CHANGELOG.md) under `[Unreleased]`; a release
moves that section under the new version number.

The distribution archive is built, not copied:

```bash
php tools/build-release.php                  # dist/bulletinbored-core-<VERSION>.zip
php tools/build-release.php --output=/tmp/x.zip
```

The builder ships a whitelist of files (core, admin/installer scripts, `lib/`,
`src/`, `views/`, `api/`, `assets/`, `migrations/`, the default theme, the English
language pack and the server configuration samples) and refuses to write an archive
that contains development files (`tests/`, `.github/`, `tools/`), deployment files
(`config.json`, `config.php`, `.user.ini`) or runtime data (database, sessions,
logs, rate-limit buckets, backups, uploads). A `.sha256` checksum is written next
to the archive.

`tests/ReleasePackageTest.php` verifies the result end to end: the archive is built
into a temporary path, inspected for forbidden and required files, every shipped PHP
file is linted, and the package is extracted and installed from the ZIP alone through
`php bb.php migrate`. Pushing a tag matching `VERSION` runs the same steps in CI and
publishes the archive with its checksum.

Upgrading an existing installation:

- backups of the previous core are taken automatically (`data/backups/`), and a failed
  copy restores them;
- both update routes (repository download and uploaded ZIP) run the same
  finalisation, so `VERSION`, `config.json` and the update manifest always agree;
- the web installers (`install.php`, `install2.php`, `install3.php`) are removed after
  a successful update; `api/install.php` is kept because it is the runtime endpoint
  used by the admin panel to install catalog plugins/themes;
- plugins, themes, uploads, `data/` and `config.json` are never overwritten.

## HTTPS, HSTS and proxies

`force_https` (default `true`) redirects plain-HTTP visitors to HTTPS, and the
`Secure` session cookie is controlled by `cookie_secure`. Both decisions use the
forwarded headers validated against `trusted_proxies`, so reverse-proxy setups
work without extra configuration.

HSTS is sent only when the visitor is actually on HTTPS (directly or through a
trusted proxy):

| Option | Default | Meaning |
|---|---|---|
| `hsts_max_age` | `31536000` | HSTS lifetime in seconds. `0` disables HSTS (useful while a certificate is being rolled out). |
| `hsts_include_subdomains` | `false` | Adds `includeSubDomains`. Enable it only if **every** subdomain of the domain can serve valid HTTPS: the browser will refuse plain HTTP on all of them for the whole `max-age`. |

## Email authentication over TLS

`mail_secure` accepts `tls` (STARTTLS), `ssl` (implicit TLS) or an empty value
(plain connection). When SMTP credentials are configured without TLS, the
password is only base64-encoded on the wire: bulletinbored logs a warning and
keeps working, and `mail_require_tls_for_auth: true` makes it refuse instead.
Server certificates are always verified — a self-signed or expired certificate
makes the delivery fail rather than sending credentials to an unknown host.

## Security model & known risks

For the full security model, trust boundary, and known risks (including why plugin/
theme packages are not cryptographically signed in a decentralized distribution
model), see the [Security Model documentation](https://docs.bulletinbored.net/security/).

In short: bulletinbored is designed for a single trusted administrator who installs
plugins, themes, language packs and updates — installing code is an explicit act of
trust in its source. Defense-in-depth is provided by Zip Slip protection on every
package extraction, JSON-only (non-executable) language files, HTML sanitization under
a nonce-based CSP, and the `plugin_verify_files` / `theme_verify_files` integrity checks
(enabled by default).

Sessions are tied to the account, not just to the cookie: `is_logged_in()` rejects a
session whose user row disappeared, and any change of role, status or password bumps
`users.session_version`, which logs the affected account out everywhere. Endpoints
that bypass `src/bootstrap.php` (such as `api/install.php`) re-check the administrator
against the database on every request instead of trusting the role cached in the
session.

Package rollback is **filesystem-only**. When a plugin or theme install/update
fails, bulletinbored restores the previous files and `installed.json` metadata,
but it cannot undo arbitrary database changes an `on_install` / `on_update`
hook may already have performed. Plugin authors should keep migrations
idempotent or provide their own rollback logic.
