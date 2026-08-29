# bulletinbored

Minimal, extensible forum software with zero dependencies. Upload files and run — no Composer, no Docker, no deployment needed.

## Requirements

- **PHP 8.1+** with PDO extension (pdo_sqlite or pdo_mysql)
- **Web server** — Apache, Nginx, IIS, LiteSpeed, or PHP built-in server

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
php -S localhost:8080
```

The router handles URL resolution internally, so no rewrite rules are needed.

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

## Manual installation

If you prefer to configure `config.json` yourself instead of using the web installer, copy `config-sample.json` to `config.json` and set your database and site settings manually. Once `config.json` is in place, visiting the site will initialize the database on first access.

See `docs/configuration.md` for the full list of options.

```json
{
    "db_driver": "sqlite",
    "db_path": "__DIR__/data/database.sqlite",
    "site_name": "bulletinbored",
    "admin_user": "admin",
    "admin_pass": "changeme123",
    "theme": "freshbored",
    "base_url": ""
}
```

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
- [Configuration](https://docs.bulletinbored.net/configuration/)
- [Managers](https://docs.bulletinbored.net/managers/)
- [Localization](https://docs.bulletinbored.net/localization/)
- [Theme Development](https://docs.bulletinbored.net/themes/)
- [Plugin Development](https://docs.bulletinbored.net/plugins/)
- [Versioning](https://docs.bulletinbored.net/versioning/)

## License

BSD Zero Clause — see [LICENSE](LICENSE).

### Why BSD Zero Clause

Part of this code was written with AI assistance, and there is no established community around the project yet. To prevent the code from becoming abandonware and to maximize the chances that it survives and is used, the most permissive possible license has been chosen: the **BSD Zero Clause**.

If and when a community forms, any future change to the license will be decided together with the community.

Contributions are accepted under the terms of the [CLA.md](CLA.md).

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
