<?php

function handle_admin_diagnostics_get(): \Bulletin\Response|bool
{
    global $config;

    $diag = [];

    $diag['php_version'] = PHP_VERSION;

    $diag['zip'] = extension_loaded('zip');
    $diag['curl'] = extension_loaded('curl');
    $diag['allow_url_fopen'] = (bool) ini_get('allow_url_fopen');
    $diag['exec'] = function_exists('exec');
    $diag['git'] = false;
    if (function_exists('exec')) {
        $out = @shell_exec('git --version 2>/dev/null');
        $diag['git'] = !empty($out);
    }

    $githubOk = false;
    $githubError = '';
    $testUrl = 'https://github.com/bulletinbored/editbored-plugin/archive/refs/heads/main.zip';
    if (extension_loaded('curl')) {
        $ch = curl_init($testUrl);
        curl_setopt_array($ch, [
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ForumDiagnostics/1.0)',
        ]);
        $githubOk = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 500;
        if (!$githubOk) {
            $githubError = curl_error($ch) ?: ('HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE));
        }
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'method' => 'HEAD'], 'https' => ['timeout' => 15, 'method' => 'HEAD']]);
        $headers = @get_headers($testUrl, 0, $ctx);
        if ($headers && !str_contains(implode("\n", $headers), ' 5')) {
            $githubOk = true;
        } else {
            $githubError = 'Unable to reach GitHub via file_get_contents';
        }
    } else {
        $githubError = 'No outbound HTTP transport available (need curl or allow_url_fopen)';
    }
    $diag['github_reachable'] = $githubOk;
    $diag['github_error'] = $githubError;

    $diag['can_install'] = $diag['zip'] && ($diag['curl'] || $diag['allow_url_fopen']) && $githubOk;

    $recommendations = [];
    if (!$diag['zip']) {
        $recommendations[] = 'Enable the PHP <code>zip</code> extension so packages can be extracted.';
    }
    if (!$diag['curl'] && !$diag['allow_url_fopen']) {
        $recommendations[] = 'Enable <code>curl</code> or set <code>allow_url_fopen = On</code> so the server can download packages.';
    }
    if (!$githubOk) {
        $recommendations[] = 'The server cannot reach GitHub (' . escape($githubError) . '). Outbound HTTPS is required for one-click install.';
    }
    if ($diag['git']) {
        $recommendations[] = 'Git is available — installs will use it directly.';
    } elseif ($diag['can_install']) {
        $recommendations[] = t('all_requirements_met');
    }

    include __DIR__ . '/../../../views/admin_diagnostics.php';
    return true;
}
