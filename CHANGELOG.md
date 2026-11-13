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
