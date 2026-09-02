<?php

class UpdateBackup
{
    private string $backupDir;
    private string $rootDir;
    private int $maxBackups;

    public function __construct(string $dataDir, string $rootDir, int $maxBackups = 3)
    {
        $this->backupDir = rtrim($dataDir, '/') . '/backups';
        $this->rootDir = rtrim($rootDir, '/');
        $this->maxBackups = $maxBackups;
    }

    public function backupCore(): ?string
    {
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }

        $backupPath = $this->backupDir . '/core_' . date('Ymd_His') . '_' . uniqid();
        if (!mkdir($backupPath, 0755, true)) {
            return null;
        }

        $exclude = ['data', 'plugins', 'themes', 'uploads', 'vendor'];
        $items = glob($this->rootDir . '/*');
        foreach ($items as $item) {
            $basename = basename($item);
            if (in_array($basename, $exclude, true)) {
                continue;
            }
            $dest = $backupPath . '/' . $basename;
            if (is_dir($item)) {
                $this->copyRecursive($item, $dest);
            } else {
                copy($item, $dest);
            }
        }

        $this->pruneOldBackups();

        return $backupPath;
    }

    public function restoreCoreBackup(string $backupPath): bool
    {
        if (!is_dir($backupPath)) {
            return false;
        }

        $items = glob($backupPath . '/*');
        foreach ($items as $item) {
            $basename = basename($item);
            $dest = $this->rootDir . '/' . $basename;
            if (is_dir($item)) {
                if (is_dir($dest)) {
                    $this->deleteRecursive($dest);
                }
                $this->copyRecursive($item, $dest);
            } else {
                copy($item, $dest);
            }
        }

        return true;
    }

    public function listBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $backups = [];
        foreach (glob($this->backupDir . '/core_*', GLOB_ONLYDIR) as $dir) {
            $backups[] = [
                'path' => $dir,
                'name' => basename($dir),
                'date' => date('Y-m-d H:i:s', filemtime($dir)),
                'size' => $this->dirSize($dir),
            ];
        }

        usort($backups, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $backups;
    }

    private function pruneOldBackups(): void
    {
        $backups = glob($this->backupDir . '/core_*', GLOB_ONLYDIR);
        if (count($backups) > $this->maxBackups) {
            usort($backups, fn($a, $b) => filemtime($a) <=> filemtime($b));
            $toDelete = array_slice($backups, 0, count($backups) - $this->maxBackups);
            foreach ($toDelete as $old) {
                $this->deleteRecursive($old);
            }
        }
    }

    public function copyRecursive(string $src, string $dst): void
    {
        if (is_dir($src)) {
            if (!is_dir($dst)) {
                if (file_exists($dst)) {
                    @unlink($dst);
                }
                mkdir($dst, 0755, true);
            }
            $items = glob($src . '/*');
            foreach ($items as $item) {
                $basename = basename($item);
                $target = $dst . '/' . $basename;
                if (is_dir($item)) {
                    $this->copyRecursive($item, $target);
                } elseif (is_file($item)) {
                    copy($item, $target);
                }
            }
        } elseif (is_file($src)) {
            if (is_dir($dst)) {
                $this->deleteRecursive($dst);
            }
            copy($src, $dst);
        }
    }

    public function deleteRecursive(string $dir): bool
    {
        if (!is_dir($dir)) {
            return @unlink($dir);
        }
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteRecursive($path);
            } else {
                @unlink($path);
            }
        }
        return @rmdir($dir);
    }

    private function dirSize(string $dir): int
    {
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }
}
