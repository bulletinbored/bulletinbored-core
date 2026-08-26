# bulletinbored

Minimal, extensible forum software with zero dependencies. Upload files and run — no Composer, no Docker, no deployment needed.

## Installation

1. Upload all files to a PHP-enabled web server (PHP 8.x, PDO/SQLite or PDO/MySQL extension)
2. Ensure Apache `mod_rewrite` is enabled (for SEO-friendly URLs)
3. Ensure the `data/`, `uploads/`, and `uploads/avatars/` directories are writable by the web server
4. Visit the site in your browser. If `config.json` is missing, the 2-step installer starts automatically:
    - **Step 1**: Choose your database (SQLite or MySQL) and test the connection
    - **Step 2**: Set your site name, administrator account, and email
    - **Step 3**: Optionally install suggested plugins to make the installation more complete. The core ships only the basic forum features, but you can install the suggested plugins now or add them later from the admin panel.
5. The installer creates `config.json` and the database automatically
6. Log in with the administrator credentials you just created

### Manual installation

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
