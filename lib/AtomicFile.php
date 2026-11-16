<?php

/**
 * AtomicFile — the one way this project writes a file that matters.
 *
 * A plain file_put_contents() is not enough for configuration and metadata:
 *
 *   - it returns the number of bytes written, so a disk that fills up mid-write
 *     produces a shorter file with a positive result;
 *   - the file is created with the process umask, so a config.json holding the
 *     database credentials can be readable by other local users;
 *   - a crash mid-write leaves a truncated file behind.
 *
 * This helper writes to a temporary file in the same directory (so the final
 * rename stays atomic), protects it before a single byte is written, verifies
 * the byte count and the JSON, replaces the target and checks the permissions
 * that ended up on disk.
 *
 * Every failure before the rename leaves the previous file untouched. The one
 * case that cannot promise it is the verification after the rename: on a
 * filesystem that keeps the destination's attributes the content is already
 * replaced, and if the corrective chmod also fails the file has the new content
 * with the old mode — the call returns false, so the caller reports the failure.
 */
final class AtomicFile
{
    /**
     * @param string        $path      Target file.
     * @param string        $contents  Exact content to write.
     * @param int           $mode      Permission bits for the result (e.g. 0600).
     * @param bool          $mustBeJson Reject content that is not valid JSON.
     * @param callable|null $writer    Optional writer(path, contents): int, used
     *                                 by the tests to simulate a short write.
     */
    public static function write(
        string $path,
        string $contents,
        int $mode = 0644,
        bool $mustBeJson = false,
        ?callable $writer = null
    ): bool {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            error_log('AtomicFile: directory does not exist for ' . $path);
            return false;
        }

        $tmp = rtrim($dir, '/') . '/.bb-tmp-' . bin2hex(random_bytes(8));
        // Owner-only from the very first byte: with the umask the file would
        // otherwise be world-readable while the content is being written.
        if (!@touch($tmp) || !@chmod($tmp, 0600) || !is_file($tmp)) {
            @unlink($tmp);
            error_log('AtomicFile: cannot create a protected temporary file in ' . $dir);
            return false;
        }

        $written = $writer !== null ? (int)$writer($tmp, $contents) : self::putContents($tmp, $contents);
        if ($written !== strlen($contents)) {
            @unlink($tmp);
            error_log(sprintf(
                'AtomicFile: partial write of %s (%d of %d bytes), file left unchanged',
                $path,
                $written,
                strlen($contents)
            ));
            return false;
        }

        if ($mustBeJson && !is_array(json_decode((string)@file_get_contents($tmp), true))) {
            @unlink($tmp);
            error_log('AtomicFile: refusing to install invalid JSON at ' . $path);
            return false;
        }

        if (!@chmod($tmp, $mode)) {
            @unlink($tmp);
            error_log(sprintf('AtomicFile: cannot set mode %04o on %s', $mode, $path));
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            error_log('AtomicFile: cannot replace ' . $path);
            return false;
        }

        // POSIX rename() keeps the source inode's attributes, but some
        // filesystems preserve the destination's: verify instead of assuming.
        clearstatcache(true, $path);
        $actual = @fileperms($path);
        if ($actual === false || ($actual & 0777) !== $mode) {
            if (!@chmod($path, $mode)) {
                error_log(sprintf(
                    'AtomicFile: %s ended with mode %s instead of %04o',
                    $path,
                    $actual === false ? 'unknown' : decoct($actual & 0777),
                    $mode
                ));
                return false;
            }
        }

        return true;
    }

    /**
     * Make an existing sensitive file unreadable by group and others.
     *
     * Shared hosting often leaves config.json at 0644 (created by an installer
     * without an explicit chmod), which exposes the database credentials to
     * every other account on the machine. This is the self-healing half: it runs
     * on boot and is a no-op once the mode is right.
     *
     * @return bool true when the file is restrictive afterwards
     */
    public static function hardenMode(string $path, int $mode = 0600): bool
    {
        if (!is_file($path)) {
            return false;
        }

        // stat() results are cached per request and chmod() does not invalidate
        // them on every PHP version: without this the check would act on a
        // stale mode (seen on 8.2, not on 8.4).
        clearstatcache(true, $path);
        $perms = @fileperms($path);
        if ($perms === false) {
            return false;
        }
        if (($perms & 0077) === 0) {
            return true; // already owner-only
        }

        if (!@chmod($path, $mode)) {
            // Not the owner (or a read-only mount): the admin has to fix it by
            // hand, `bb.php doctor` reports it. Stay quiet here to avoid one log
            // line per request.
            return false;
        }

        clearstatcache(true, $path);
        error_log(sprintf(
            'AtomicFile: tightened permissions of %s from %04o to %04o',
            $path,
            $perms & 0777,
            $mode
        ));
        return true;
    }

    /** Bytes accepted by the filesystem, 0 on failure. */
    public static function putContents(string $path, string $contents): int
    {
        $written = @file_put_contents($path, $contents, LOCK_EX);
        return $written === false ? 0 : (int)$written;
    }
}
