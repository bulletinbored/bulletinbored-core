<?php

/**
 * UpdateBackup — copy the installation for a core update, and restore it when
 * the update fails.
 *
 * Reliability rules this class follows, because these files are the only way
 * back from a broken update:
 *
 *   - every filesystem operation is checked; a failed copy() returns false and
 *     does NOT throw on its own (a full disk produces a warning and false), so
 *     the result is turned into an exception here;
 *   - hidden files are part of the package (.htaccess is shipped and would
 *     otherwise be overwritten without a copy to restore);
 *   - a backup is only valid when it completed: a manifest written next to the
 *     directory records the entries it contains, and a restore refuses to prune
 *     anything without it.
 */
class UpdateBackup
{
    /** Suffix of the manifest that marks a complete backup. */
    public const MANIFEST_SUFFIX = '.json';

    /** Top-level paths that belong to the installation, never to a core update. */
    private const PRESERVED = ['data', 'plugins', 'themes', 'uploads', 'vendor', 'config.json', 'config.php'];

    private string $backupDir;
    private string $rootDir;
    private int $maxBackups;

    public function __construct(string $dataDir, string $rootDir, int $maxBackups = 3)
    {
        $this->backupDir = rtrim($dataDir, '/') . '/backups';
        $this->rootDir = rtrim($rootDir, '/');
        $this->maxBackups = $maxBackups;
    }

    /**
     * Copy the installation root, excluding the user-owned directories.
     *
     * Returns null when the copy could not be completed: the partial directory
     * is removed, so a half-written backup is never used to restore anything.
     */
    public function backupCore(): ?string
    {
        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0755, true) && !is_dir($this->backupDir)) {
            error_log('UpdateBackup: cannot create ' . $this->backupDir);
            return null;
        }

        $backupPath = $this->backupDir . '/core_' . date('Ymd_His') . '_' . uniqid();
        if (!@mkdir($backupPath, 0755, true)) {
            error_log('UpdateBackup: cannot create ' . $backupPath);
            return null;
        }

        $entries = [];
        try {
            foreach ($this->listEntries($this->rootDir) as $entry) {
                $basename = basename($entry);
                if (in_array($basename, self::PRESERVED, true)) {
                    continue;
                }
                if (is_link($entry)) {
                    // Never follow a link: it may point at user data or outside
                    // the installation. The entry is recorded so a restore does
                    // not prune it.
                    error_log('UpdateBackup: skipping symlink ' . $basename);
                    $entries[] = $basename;
                    continue;
                }
                $this->copyRecursive($entry, $backupPath . '/' . $basename);
                $entries[] = $basename;
            }
        } catch (\Throwable $e) {
            // A partial backup is worthless and dangerous: remove it.
            $this->deleteRecursive($backupPath);
            @unlink($this->manifestPath($backupPath));
            error_log('UpdateBackup: backup failed, aborted: ' . $e->getMessage());
            return null;
        }

        $manifest = [
            'complete' => true,
            'created_at' => date('c'),
            'version' => trim((string)@file_get_contents($this->rootDir . '/VERSION')),
            'entries' => $entries,
        ];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($this->manifestPath($backupPath), $json) === false) {
            $this->deleteRecursive($backupPath);
            error_log('UpdateBackup: cannot write the backup manifest, backup discarded');
            return null;
        }

        $this->pruneOldBackups();

        return $backupPath;
    }

    /**
     * Restore the installation root from a core backup.
     *
     * Beyond copying the backup back, this removes the top-level entries the
     * interrupted update had already created (a failed copy can leave a new root
     * file behind). The pruning happens only when the backup carries a valid
     * manifest: without it nothing is deleted, so an incomplete backup can never
     * remove files that simply were not copied.
     */
    public function restoreCoreBackup(string $backupPath): bool
    {
        if (!is_dir($backupPath)) {
            return false;
        }

        // Everything below can fail (unreadable backup, a directory that cannot
        // be replaced, a full disk). A restore either brings the installation
        // back exactly, or reports failure: it never throws out of here, or the
        // caller would lose the original error.
        try {
            $manifest = $this->readManifest($backupPath);

            foreach ($this->listEntries($backupPath) as $item) {
                $basename = basename($item);
                $dest = $this->rootDir . '/' . $basename;

                if (is_link($item)) {
                    // Recorded in the manifest, never followed: leave the
                    // existing entry in place so it is not pruned.
                    continue;
                }
                if (is_dir($item)) {
                    if (is_dir($dest) && !$this->deleteRecursive($dest)) {
                        // Copying over a half-deleted directory could leave files
                        // from the failed update inside it.
                        error_log('UpdateBackup: restore aborted, cannot clear ' . $dest);
                        return false;
                    }
                    $this->copyRecursive($item, $dest);
                } else {
                    $this->copyRecursive($item, $dest);
                }
            }

            if ($manifest === null) {
                // No verified manifest: copy back only, never prune.
                error_log('UpdateBackup: backup has no valid manifest, skipping cleanup of extra files');
                return true;
            }

            $restored = array_flip($manifest['entries']);
            foreach ($this->listEntries($this->rootDir) as $entry) {
                $basename = basename($entry);
                if (isset($restored[$basename]) || in_array($basename, self::PRESERVED, true)) {
                    continue;
                }
                if (is_dir($entry) && !is_link($entry)) {
                    if (!$this->deleteRecursive($entry)) {
                        error_log('UpdateBackup: could not remove ' . $entry . ' created by the failed update');
                    }
                } elseif (!@unlink($entry)) {
                    error_log('UpdateBackup: could not remove ' . $entry . ' created by the failed update');
                }
            }

            return true;
        } catch (\Throwable $e) {
            error_log('UpdateBackup: restore aborted: ' . $e->getMessage());
            return false;
        }
    }

    public function listBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $backups = [];
        foreach (glob($this->backupDir . '/core_*', GLOB_ONLYDIR) ?: [] as $dir) {
            $manifest = $this->readManifest($dir);
            $backups[] = [
                'path' => $dir,
                'name' => basename($dir),
                'date' => date('Y-m-d H:i:s', (int)@filemtime($dir)),
                'size' => $this->dirSize($dir),
                'complete' => $manifest !== null,
            ];
        }

        usort($backups, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $backups;
    }

    /**
     * Copy a file or a directory tree. Throws on the first failure: a silent
     * partial copy is what turns a full disk into a half-updated installation.
     */
    public function copyRecursive(string $src, string $dst): void
    {
        if (is_link($src)) {
            throw new RuntimeException('refusing to copy symlink ' . $src);
        }

        if (is_dir($src)) {
            if (!is_dir($dst)) {
                if (file_exists($dst) && !@unlink($dst)) {
                    throw new RuntimeException('cannot replace ' . $dst);
                }
                if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
                    throw new RuntimeException('cannot create directory ' . $dst);
                }
            }
            foreach ($this->listEntries($src) as $item) {
                $this->copyRecursive($item, $dst . '/' . basename($item));
            }
            return;
        }

        if (is_file($src)) {
            if (is_dir($dst)) {
                $this->deleteRecursive($dst);
            }
            if (!@copy($src, $dst)) {
                throw new RuntimeException('cannot copy ' . $src . ' to ' . $dst);
            }
            return;
        }

        throw new RuntimeException('unsupported source ' . $src);
    }

    /**
     * Recursively delete a path. Returns false when anything could not be
     * removed, and logs what was left behind: a cleanup that silently leaves
     * files behind is worse than a noisy one.
     */
    public function deleteRecursive(string $dir): bool
    {
        if (!is_dir($dir) || is_link($dir)) {
            return @unlink($dir);
        }

        // scandir(), not glob(): hidden entries must be removed too.
        $items = @scandir($dir);
        if ($items === false) {
            error_log('UpdateBackup: cannot list ' . $dir . ' for deletion');
            return false;
        }

        $ok = true;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            $removed = (is_dir($path) && !is_link($path))
                ? $this->deleteRecursive($path)
                : @unlink($path);
            if (!$removed) {
                $ok = false;
                error_log('UpdateBackup: could not delete ' . $path);
            }
        }

        if (!@rmdir($dir)) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * All entries of a directory, hidden ones included.
     *
     * Throws when the directory cannot be read: returning an empty list would
     * make an unreadable directory indistinguishable from an empty one, and the
     * backup would be marked complete while silently missing files.
     *
     * Public so the contract can be unit-tested (permission bits do not stop a
     * root user, so the failure is triggered with a non-directory path).
     *
     * @return string[]
     * @throws RuntimeException
     */
    public function listEntries(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new RuntimeException('not a directory: ' . $dir);
        }

        $items = @scandir($dir);
        if ($items === false) {
            // Clear the cached stat/read state: a retry after a mount or
            // permission fix must not see the stale failure.
            clearstatcache(true, $dir);
            throw new RuntimeException('cannot read directory: ' . $dir);
        }

        $entries = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $entries[] = rtrim($dir, '/') . '/' . $item;
        }
        return $entries;
    }

    private function manifestPath(string $backupPath): string
    {
        return rtrim($backupPath, '/') . self::MANIFEST_SUFFIX;
    }

    /**
     * @return array{complete: bool, entries: string[], created_at?: string}|null
     */
    private function readManifest(string $backupPath): ?array
    {
        $path = $this->manifestPath($backupPath);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data) || empty($data['complete']) || !isset($data['entries']) || !is_array($data['entries'])) {
            return null;
        }
        $data['entries'] = array_values(array_map('strval', $data['entries']));
        return $data;
    }

    private function pruneOldBackups(): void
    {
        $backups = glob($this->backupDir . '/core_*', GLOB_ONLYDIR) ?: [];
        if (count($backups) > $this->maxBackups) {
            usort($backups, fn($a, $b) => (int)@filemtime($a) <=> (int)@filemtime($b));
            $toDelete = array_slice($backups, 0, count($backups) - $this->maxBackups);
            foreach ($toDelete as $old) {
                $this->deleteRecursive($old);
                @unlink($this->manifestPath($old));
            }
        }
    }

    private function dirSize(string $dir): int
    {
        $size = 0;
        if (!is_dir($dir)) {
            return 0;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            $size += (int)$file->getSize();
        }
        return $size;
    }
}
