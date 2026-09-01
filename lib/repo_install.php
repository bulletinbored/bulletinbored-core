<?php

/**
 * Install a repository without requiring git to be present on the server.
 *
 * Tries git first (when available), then falls back to downloading a zip
 * archive from common Git hosts (GitHub / GitLab) and extracting it.
 *
 * @return array{success:bool,message:string}
 */
function install_repo_package(string $repoUrl, string $targetDir, ?string $tag = null, ?string $expectedName = null): array
{
    $repo = trim($repoUrl, '/');
    $repoName = basename(str_replace(['\\', '.git'], ['', ''], $repo));
    $dest = rtrim(dirname($targetDir), '/') . '/';
    $finalName = $expectedName ?: $repoName;
    $targetDir = $dest . $finalName;
    $haveGit = function_exists('exec') && !ini_get('disable_functions') && !str_contains(ini_get('disable_functions') ?? '', 'exec')
        && (@exec('git --version 2>/dev/null', $gitOut, $gitRet) || true) && $gitRet === 0;

    if (is_dir($targetDir)) {
        if ($haveGit) {
            exec('git -C ' . escapeshellarg($targetDir) . ' fetch --tags 2>&1', $fetchOut, $fetchCode);
            $pull = $tag
                ? 'git -C ' . escapeshellarg($targetDir) . ' checkout -q ' . escapeshellarg($tag) . ' 2>&1 && git -C ' . escapeshellarg($targetDir) . ' pull --ff-only 2>&1'
                : 'git -C ' . escapeshellarg($targetDir) . ' pull --ff-only 2>&1';
            exec($pull, $out, $code);
            if ($code === 0) {
                return ['success' => true, 'message' => 'Repository updated'];
            }
        }
    } else {
        if ($haveGit) {
            $cmd = 'git clone --depth 1';
            if ($tag) {
                $cmd .= ' --branch ' . escapeshellarg($tag);
            }
            $cmd .= ' ' . escapeshellarg($repoUrl) . ' ' . escapeshellarg($targetDir) . ' 2>&1';
            exec($cmd, $out, $code);
            if ($code === 0) {
                return ['success' => true, 'message' => 'Repository cloned'];
            }
        }
    }

    // Fallback: download a zip archive and extract it.
    return download_repo_archive($repoUrl, $targetDir, $tag);
}

function download_repo_archive(string $repoUrl, string $targetDir, ?string $tag = null): array
{
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return ['success' => false, 'message' => 'Unable to create target directory: ' . $targetDir];
        }
    }

    $archiveUrls = repo_archive_urls($repoUrl, $tag);
    if (empty($archiveUrls)) {
        return ['success' => false, 'message' => 'Git is not available and the repository host does not support direct archive downloads. Install git on the server or upload the package manually.'];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'repo') . '.zip';
    $downloaded = false;
    $lastUrl = '';
    $lastCode = null;
    foreach ($archiveUrls as $archiveUrl) {
        $lastUrl = $archiveUrl;
        $code = null;
        if (download_file($archiveUrl, $tmp, $code)) {
            $downloaded = true;
            break;
        }
        $lastCode = $code;
    }
    if (!$downloaded) {
        @unlink($tmp);
        $detail = $lastCode ? ' (HTTP ' . $lastCode . ')' : '';
        return ['success' => false, 'message' => 'Failed to download archive from ' . $lastUrl . $detail];
    }

    $extracted = extract_zip($tmp, $targetDir);
    if (!$extracted) {
        $reason = 'unknown';
        if (!class_exists('ZipArchive')) {
            $reason = 'PHP zip extension not enabled';
        } elseif (!is_writable(dirname($targetDir))) {
            $reason = 'parent directory not writable: ' . dirname($targetDir);
        } elseif (!is_dir($targetDir) || !is_writable($targetDir)) {
            $reason = 'target directory not writable: ' . $targetDir;
        } else {
            $z = new ZipArchive();
            if ($z->open($tmp) === true) {
                $z->close();
                $reason = 'extraction blocked (zip_entries_safe validation or file write failure); target=' . $targetDir;
            } else {
                $reason = 'downloaded file is not a valid ZIP (possibly HTML/rate-limit from GitHub instead of archive)';
            }
        }
        @unlink($tmp);
        return ['success' => false, 'message' => 'Failed to extract archive: ' . $reason];
    }
    @unlink($tmp);

    // Git archives nest files under <repo>-<ref>/ ; flatten if needed.
    $entries = array_values(array_filter(glob($targetDir . '/*'), 'is_dir'));
    if (count($entries) === 1) {
        $nested = $entries[0];
        foreach (glob($nested . '/*') as $item) {
            $base = basename($item);
            $destItem = $targetDir . '/' . $base;
            if (file_exists($destItem)) {
                if (is_dir($destItem)) {
                    @rmdir($destItem);
                } else {
                    @unlink($destItem);
                }
            }
            @rename($item, $destItem);
        }
        @rmdir($nested);
    }

    return ['success' => true, 'message' => 'Package installed from archive'];
}

function repo_archive_urls(string $repoUrl, ?string $tag): array
{
    $repo = rtrim(trim($repoUrl, '/'), '.git');

    if (preg_match('#^https?://github\.com/([^/]+)/([^/]+)#i', $repo, $m)) {
        $owner = $m[1];
        $name = $m[2];
        if ($tag) {
            return [
                "https://github.com/{$owner}/{$name}/archive/refs/tags/{$tag}.zip",
                "https://github.com/{$owner}/{$name}/archive/refs/heads/main.zip",
                "https://github.com/{$owner}/{$name}/archive/refs/heads/master.zip",
            ];
        }
        return [
            "https://github.com/{$owner}/{$name}/archive/refs/heads/main.zip",
            "https://github.com/{$owner}/{$name}/archive/refs/heads/master.zip",
        ];
    }

    if (preg_match('#^https?://gitlab\.com/([^/]+/[^/]+)#i', $repo, $m)) {
        $project = urlencode($m[1]);
        $ref = $tag ?: 'main';
        return ["https://gitlab.com/api/v4/projects/{$project}/repository/archive.zip?sha={$ref}"];
    }

    return [];
}

function download_file(string $url, string $dest, ?int &$httpCode = null): bool
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = @fopen($dest, 'wb');
        if ($fp === false) {
            return false;
        }
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; ForumInstaller/1.0)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $executed = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ok = $executed !== false && $httpCode < 400 && $httpCode > 0;
        curl_close($ch);
        fclose($fp);
        return (bool)$ok;
    }

    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 120, 'user_agent' => 'Mozilla/5.0 (compatible; ForumInstaller/1.0)'],
            'https' => ['timeout' => 120, 'user_agent' => 'Mozilla/5.0 (compatible; ForumInstaller/1.0)'],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            return false;
        }
        return file_put_contents($dest, $data) !== false;
    }

    return false;
}

/**
 * Validate every ZIP entry stays inside $targetDir. Blocks absolute paths,
 * ".." traversal and any resolved target escaping the destination.
 */
function zip_entries_safe(ZipArchive $zip, string $targetDir): bool
{
    $targetDir = rtrim($targetDir, '/');
    // Canonicalize the target so "../" segments in the input path don't break
    // the prefix comparison below (e.g. "src/actions/../../plugins/editbored").
    $realDest = realpath($targetDir);
    if ($realDest === false) {
        return false;
    }
    $targetDir = $realDest;
    // Normalize to forward slashes so the prefix check is portable across
    // Windows (backslashes) and Linux (forward slashes).
    $realDest = str_replace('\\', '/', rtrim($realDest, '/'));

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }

        $name = str_replace('\\', '/', $name);
        if (str_starts_with($name, '/') || str_contains($name, '..')) {
            return false;
        }

        $resolved = realpath($targetDir . '/' . $name);
        if ($resolved === false) {
            $resolved = $targetDir . '/' . $name;
        }
        $resolved = str_replace('\\', '/', $resolved);
        if ($resolved !== $realDest && !str_starts_with($resolved . '/', $realDest . '/')) {
            return false;
        }
    }

    return true;
}

/**
 * Safely extract a ZIP into $targetDir, mitigating Zip Slip by validating
 * every entry before extracting and writing each entry individually.
 */
function extract_zip(string $zipPath, string $targetDir): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }
    if (!is_writable(dirname($targetDir))) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return false;
    }

    $targetDir = rtrim($targetDir, '/');
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }
    if (!is_dir($targetDir) || !is_writable($targetDir)) {
        $zip->close();
        return false;
    }

    if (!zip_entries_safe($zip, $targetDir)) {
        $zip->close();
        return false;
    }

    $ok = true;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        $name = str_replace('\\', '/', $name);

        $dest = $targetDir . '/' . $name;
        if (substr($name, -1) === '/') {
            if (!is_dir($dest)) {
                @mkdir($dest, 0755, true);
            }
            continue;
        }

        $dir = dirname($dest);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            // Fallback: extract this single entry via extractTo with a temp dir
            $tmpDir = sys_get_temp_dir() . '/zipfall_' . uniqid();
            @mkdir($tmpDir, 0755, true);
            if ($zip->extractTo($tmpDir, [$i])) {
                $srcFile = $tmpDir . '/' . $name;
                if (file_exists($srcFile)) {
                    @mkdir(dirname($dest), 0755, true);
                    @copy($srcFile, $dest);
                }
                @unlink($srcFile);
            }
            @rmdir($tmpDir);
            continue;
        }
        if (@file_put_contents($dest, $content) === false) {
            $ok = false;
            break;
        }
        @chmod($dest, 0644);
    }

    $zip->close();
    return $ok;
}
