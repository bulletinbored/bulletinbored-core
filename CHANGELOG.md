# Changelog

All notable changes to bulletinbored are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The version in this file must always match the `VERSION` file (enforced by
`tests/VersionConsistencyTest.php`).

## [Unreleased] — hardening for 1.0.0

### Security

- **Sessions of deleted accounts are invalidated.** `is_logged_in()` now treats a
  missing user row as an invalid session instead of returning `true`, and clears
  the session data before destroying it.
- **`api/install.php` no longer trusts `$_SESSION['user_role']`.** The endpoint
  verifies the administrator against the database on every request (new
  `lib/AdminAuth.php`): the account must exist, have a matching
  `session_version`, still be an `admin`, and not be banned or suspended.
  Unauthorized calls now return HTTP 403.
- **Role and status changes invalidate existing sessions.** Editing a user's role
  or status, banning, suspending and unbanning now bump
  `users.session_version`, so a demoted administrator is logged out instead of
  keeping admin UI access until logout. Edits that do not change privileges
  (renaming, changing the email) keep sessions alive.
- **`DbQuery` validates SQL identifiers.** Table names, column names, `ORDER BY`
  columns and the comparison operator of `where()` are validated against an
  allow-list; anything else throws `InvalidArgumentException`. `whereRaw()` is
  documented as accepting trusted SQL only.
- `send_email()` validates `mail_from`/`mail_from_name` before they reach the
  message headers on both the SMTP and `mail()` paths.

### Fixed

- **SMTP delivery no longer reports false successes.** The dependency-free SMTP
  client now reads complete (including multi-line) replies, checks every status
  code (greeting, EHLO/HELO, STARTTLS, AUTH LOGIN, MAIL FROM, RCPT TO, DATA and
  the final reply), honours the configured timeout, re-sends EHLO after
  STARTTLS, applies dot-stuffing, and returns `false` whenever the server
  refuses a stage.
- **Core updates from an uploaded ZIP finalize the installation.** ZIP updates
  now share the post-copy steps with repository updates: installer scripts are
  removed, and `VERSION`, `config.json` and the update manifest are updated.
  A ZIP whose `VERSION` is missing or not a version leaves the metadata alone.
- `api/install.php` is no longer deleted by core updates: it is a runtime admin
  endpoint, not an installer.
- `where('column', null)` compares with `IS NULL` / `IS NOT NULL` instead of
  `= NULL`.
- `whereIn('column', [])` matches nothing (it previously relied on the string
  `'1'` being accepted as a column name).

### Fixed (remote installation on MySQL/MariaDB)

- **Unhandled 500s are now diagnosable on shared hosting.** `data/logs/error.log`
  receives the exception message, class, file/line, request URI, PHP version and
  database target (no credentials). Previously the only trace was `error_log()`,
  which most shared hosts never show.
- **Nothing in the MySQL bootstrap can fail the request any more.** The driver
  attribute and the session statements (`EMULATE_PREPARES`, `sql_mode`,
  `time_zone`, `FOREIGN_KEY_CHECKS`) are best effort: a restricted database user
  no longer turns every request into a 500.
- **`src/setup.php` no longer reads `db_path` on MySQL installations**, which
  raised "Undefined array key db_path" warnings on every request.
- **`tools/diag.php`** (not shipped in the release package): a one-file remote
  diagnostic that prints PHP extensions, permissions, the database connection
  and the migration state, for installations without access to logs.
- **The web installer's MySQL DDL is MySQL 8 compatible too**: `allowed_roles`
  is `TEXT` without a default, like the migration. The dialect guard now also
  scans the installer's MySQL statements.

### Fixed (MySQL 8 compatibility)

- **Fresh MySQL 8 installations failed at the initial schema.** The MySQL branch
  of `20260829_initial_schema.php` and `20260831_upgrade_05x.php` declared
  `allowed_roles TEXT DEFAULT NULL`. MySQL rejects a default on BLOB/TEXT
  columns (error 1101) while MariaDB and SQLite accept it, so the migration
  aborted on MySQL and a 0.5.x database could not be upgraded either.
  `BbPdo::normalizeForMysql()` now strips such defaults for any statement
  (protecting plugin migrations written for SQLite), and
  `tests/MySqlDialectTest.php` guards the MySQL code paths of the migrations
  statically, so this cannot come back unnoticed.
- The new driver-aware upgrade test now runs the migration chain through
  `BbPdo` (like `src/setup.php` does) and drops the shared test schema with
  `FOREIGN_KEY_CHECKS = 0`, so it also verifies the real MySQL path in CI.

### Fixed (review of develop)

- **CI now runs on `develop`** (`push` and `pull_request`), so the branch is
  actually verified before a merge.
- **Migration markers are mandatory where they matter.** On MySQL/MariaDB the
  run is refused when `migration_progress` cannot be written: without a recovery
  marker a half-applied schema would be untraceable. SQLite keeps tracking as
  best effort because a failure is rolled back.
- **Plugin migrations are no longer retried blindly.** After an interruption,
  core migrations are retried (they are idempotent); a plugin migration is
  stopped with instructions unless it declares `isIdempotent(): bool`.
- **Expired suspensions are honoured by `AdminAuth`**: an administrator whose
  suspension ended can use `api/install.php` again, matching the login flow,
  while a running or indefinite suspension is still refused.
- **`tools/build-release.php` refuses a dirty working tree** unless
  `--allow-dirty` is passed, verifies every archive write, the archive size and
  the checksum file.
- **Release workflow accepts both `1.0.0` and `v1.0.0` tags** (the prefix is
  normalised before comparing with `VERSION`).
- **Core update finalization is verified**: `VERSION`, `config.json` and the
  update manifest must all be written, otherwise the update is reported as
  failed (the files are in place; a retry re-runs the metadata writes).
- **HSTS works behind a reverse proxy**: the HTTPS state is resolved after the
  trusted-proxy headers are validated, and `includeSubDomains` is now opt-in
  (`hsts_include_subdomains`, default `false`) because it also binds every other
  subdomain of the domain.
- **SMTP credentials over plaintext**: a warning is logged, and
  `mail_require_tls_for_auth` makes the client refuse. New tests cover STARTTLS
  refusal, a broken TLS handshake and self-signed certificates (proof that the
  server certificate is verified).
- `DbQuery::raw()`, `rawFirst()` and `rawExec()` are documented as trusted-SQL
  APIs, like `whereRaw()`.

### Fixed (second pass)

- **Nested plugin/theme archives failed the integrity check.** `manifest.json`
  was counted as an undeclared payload file, so any archive with a top-level
  `plugin-1.0.0/` folder (the standard GitHub layout) was rejected.
- **Temporary package directories were never fully removed.** Cleanup and
  flattening used `glob('*')`, which skips dotfiles: `.github/`, `.gitignore`
  and friends stayed behind, `rmdir()` failed and `.install-tmp-*` directories
  leaked into `plugins/`/`themes/`, where the loader could pick them up.
  `deleteDir()` now removes hidden files too and never follows symlinks.
- **A core ZIP whose only top-level entry is a directory was rejected** as an
  "invalid package structure" (e.g. a package containing `index.php`, `VERSION`
  and `lib/`). The wrapper-directory detection now requires `index.php` and
  `VERSION` inside the single directory before treating it as a wrapper.

### Reliability

- **Interrupted migrations are recoverable.** The migrator records a row in the
  new `migration_progress` table before running a migration and clears it on
  completion. On drivers without transactional DDL (MySQL/MariaDB) a failure
  leaves that row behind with the error message, so
  `getInterruptedMigrations()` shows exactly which migration is partially
  applied; `forgetInterrupted()` re-arms a retry and `recoverInterrupted()`
  records a manually repaired migration without re-running it. SQLite behaviour
  is unchanged: a failed migration is rolled back cleanly.
- Core ZIP updates honour the configured installation root, which also makes
  them testable without touching the real installation.

### Tooling and distribution

- Route authorization map test: every `/admin` route must sit inside the admin
  middleware group and every non-public route must require authentication; the
  guest/auth/admin/can middleware chains are also exercised end to end.
- `tests/run.php --min-area-coverage=<percent>` enforces a separate coverage
  floor for authentication/sessions, authorization, updates, packages and
  migrations (CI runs it at 70%).
- Adversarial `PackageInstaller` tests (Zip Slip variants, integrity mismatches,
  pre-existing target directories, repo-file stripping, nested archives),
  upgrade-compatibility tests (previous-release database, populated database,
  core update preserving data/plugins/themes/uploads/config) and telemetry
  opt-out/failure tests.

- Versioned `CHANGELOG.md` checked by the test suite.
- `tools/build-release.php` produces a reproducible distribution ZIP (whitelist
  of shipped files, no development or personal files) plus a SHA-256 checksum.
- `tests/run.php --min-coverage=<percent>` fails the run when line coverage
  measured by Xdebug or PCOV drops below the threshold.
- The CI matrix installs the `zip` extension, so package tests can no longer be
  skipped silently.

## [0.9.8] — 2026-11-12

### Changed

- Password complexity validation on the registration form (minimum length,
  upper/lower case and digit).

## [0.9.0] — 2026

First public pre-release: forum core (threads, posts, categories, moderation),
roles and permissions, plugins and themes with a catalog, admin panel, guided
installer, migrations, SQLite and MySQL support, localisation and the automated
test suite.
