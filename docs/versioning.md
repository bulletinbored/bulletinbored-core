# Versioning

bulletinbored uses semantic versioning (SemVer): `MAJOR.MINOR.PATCH` — currently `0.1.0`.

The project is released under the BSD Zero Clause (BSD-0). See [LICENSE](LICENSE) and [CLA.md](../CLA.md).

## What the numbers mean

- `MAJOR` — incremented for incompatible changes that require migration (e.g. config key renames, database schema breaks)
- `MINOR` — incremented for new features that are backward-compatible (e.g. new admin panels, new core hooks, new managers)
- `PATCH` — incremented for bug fixes and security patches that don't add features

Since this is `0.x`, the API is still considered unstable: minor releases may break things.

## How to bump a version

1. Edit the `VERSION` file at the project root (single line, e.g. `0.1.0`)
2. Update the version constant in `config.php` if you keep an inline fallback there
3. If you ship updates via the Update Manager, make sure `versions.json` on your update server exposes the new version
4. Tag the release in git:

   ```bash
   git add VERSION
   git commit -m "Bump version to 0.1.1"
   git tag 0.1.1
   git push --follow-tags
   ```

## Where the version lives in code

- `VERSION` — single-source-of-truth file at the project root
- `config.php` — `$config['version']` is loaded from `VERSION` at boot time
- `data/updates.json` — stores installed versions for core, plugins, and themes

## Version checks

The Update Manager compares installed versions with remote versions:

- Core version comes from `config['version']`
- Plugin versions come from the `Version:` header in each plugin PHP file
- Theme versions come from `manifest.json` in each theme folder

If an update server URL is configured (`config['update_server']`), the Update Manager fetches `versions.json` from it and compares remote versions against installed ones.

## Example release checklist

1. Update `VERSION` file
2. Update changelog / docs
3. Run tests or manual QA
4. Commit and tag
5. Build ZIP packages for core, plugin, and theme updates if distributable packages are needed
6. Update remote `versions.json` pointing to the new packages
