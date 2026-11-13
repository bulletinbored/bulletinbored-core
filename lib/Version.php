<?php

/**
 * Version.php — SemVer helpers shared by the updater and the extension loader.
 *
 * Only the two things bulletinbored actually needs:
 *   - is a version a prerelease (1.0.0-rc.1)?
 *   - what is its base version (1.0.0), for matching extension requirements?
 */

/**
 * Does the version carry a SemVer prerelease suffix (1.0.0-rc.1, 1.0.0-beta.2)?
 * Build metadata alone (1.0.0+build.5) does not make a prerelease.
 */
function bb_version_is_prerelease(string $version): bool
{
    return (bool)preg_match('/^\d+\.\d+\.\d+-[0-9A-Za-z.-]+(?:\+[0-9A-Za-z.-]+)?$/', trim($version));
}

/**
 * Strip the prerelease/build suffix and any leading "v": 1.0.0-rc.1 -> 1.0.0.
 *
 * Used when matching extension requirements: a plugin declaring
 * "core": ">=1.0.0" must be installable while testing 1.0.0-rc.1, because that
 * is the whole point of a release candidate. Requirement matching therefore
 * uses the base version, while update comparisons keep the full version
 * (1.0.0-rc.1 < 1.0.0, as SemVer requires).
 */
function bb_version_base(string $version): string
{
    $version = ltrim(trim($version), 'vV');
    $cut = strcspn($version, '-+');
    return $cut > 0 ? substr($version, 0, $cut) : $version;
}
