<?php

/**
 * SessionVersionPolicyTest — the session contract is enforced, not just
 * documented.
 *
 * Everything that changes a user's credentials, role or status must bump
 * users.session_version, otherwise the affected account keeps working with the
 * old privileges (or keeps a password that was just replaced). This guard walks
 * the SQL literals in the shipped code and fails on an UPDATE users statement
 * that touches a privileged column without the bump.
 *
 * A statement whose column list is assembled dynamically ("UPDATE users SET "
 * . implode(...)) cannot be verified statically: it must carry the
 * @session-version-reviewed marker, which forces a human decision.
 */

require_once __DIR__ . '/harness.php';

const SESSION_PRIVILEGED_COLUMNS = ['role', 'status', 'password', 'suspension_time'];
const SESSION_REVIEW_MARKER = '@session-version-reviewed';

/**
 * @return string[] violations, e.g. "src/x.php:12 UPDATE users without session_version"
 */
function session_version_violations(string $code, string $label): array
{
    if (!function_exists('token_get_all')) {
        return [];
    }

    $tokens = token_get_all($code);
    $violations = [];
    $offset = 0;

    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $isStringLiteral = is_array($token)
            && ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE);

        if ($isStringLiteral && stripos($text, 'UPDATE users') !== false) {
            $between = trim($text, " \t\n\r\0\x0B'\"");
            $line = is_array($token) ? $token[2] : 0;

            // Only the SET clause matters: a WHERE clause naming "role"
            // (e.g. WHERE role = 'admin') changes nothing.
            $setClause = $between;
            $setPos = stripos($between, 'SET');
            if ($setPos !== false) {
                $setClause = substr($between, $setPos + 3);
                $wherePos = stripos($setClause, 'WHERE');
                if ($wherePos !== false) {
                    $setClause = substr($setClause, 0, $wherePos);
                }
            }

            $privileged = false;
            foreach (SESSION_PRIVILEGED_COLUMNS as $column) {
                if (preg_match('/\b' . $column . '\b/i', $setClause)) {
                    $privileged = true;
                    break;
                }
            }
            $hasBump = str_contains(strtolower($between), 'session_version');
            // "UPDATE users SET " with nothing after it: the columns are built
            // at runtime and cannot be checked statically.
            $dynamic = (bool)preg_match('/\bSET\s*$/i', $between);
            $reviewed = str_contains(substr($code, max(0, $offset - 400), 400), SESSION_REVIEW_MARKER);

            if ($dynamic && !$reviewed) {
                $violations[] = $label . ':' . $line . ' dynamic UPDATE users without ' . SESSION_REVIEW_MARKER;
            } elseif ($privileged && !$hasBump && !$reviewed) {
                $violations[] = $label . ':' . $line . ' UPDATE users changes a privileged column without session_version';
            }
        }

        $offset += strlen($text);
    }

    return $violations;
}

function session_version_php_files(): array
{
    $root = dirname(__DIR__);
    $files = [];

    foreach (['src', 'lib', 'api'] as $dir) {
        $path = $root . '/' . $dir;
        if (!is_dir($path)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    foreach (glob($root . '/*.php') ?: [] as $file) {
        $files[] = $file;
    }

    return $files;
}

function test_every_privileged_update_bumps_session_version(): Test
{
    $t = new Test('Session policy - privileged user updates bump session_version');

    if (!function_exists('token_get_all')) {
        $t->assert('Skipped: tokenizer extension not available', true);
        return $t;
    }

    $root = dirname(__DIR__);
    $files = session_version_php_files();
    $t->assert('Scanned some files', count($files) > 40);
    if (!function_exists('token_get_all')) {
        $t->assert('Skipped: tokenizer extension not available (CI enables it)', true);
        return $t;
    }

    $violations = [];
    foreach ($files as $file) {
        $label = str_replace($root . '/', '', $file);
        foreach (session_version_violations((string)file_get_contents($file), $label) as $violation) {
            $violations[] = $violation;
        }
    }

    $t->assert('No privileged update skips the bump', $violations === []);
    foreach ($violations as $violation) {
        $t->assert('violation: ' . $violation, false);
    }

    return $t;
}

function test_session_version_policy_self_check(): Test
{
    $t = new Test('Session policy - the guard detects the patterns it targets');

    $bad = "<?php\n\$pdo->prepare(\"UPDATE users SET role = ? WHERE id = ?\")->execute([1, 2]);\n";
    $violations = session_version_violations($bad, 'sample.php');
    $t->assertCount('Role change without bump detected', 1, $violations);

    $badPassword = "<?php\n\$pdo->prepare('UPDATE users SET password = ? WHERE id = ?');\n";
    $t->assertCount('Password change without bump detected', 1, session_version_violations($badPassword, 'sample.php'));

    $badStatus = "<?php\n\$pdo->prepare('UPDATE users SET status = ? WHERE id = ?');\n";
    $t->assertCount('Status change without bump detected', 1, session_version_violations($badStatus, 'sample.php'));

    $good = "<?php\n\$pdo->prepare('UPDATE users SET role = ?, session_version = COALESCE(session_version, 0) + 1 WHERE id = ?');\n";
    $t->assertCount('Statement with the bump accepted', 0, session_version_violations($good, 'sample.php'));

    $harmless = "<?php\n\$pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?');\n";
    $t->assertCount('Non-privileged column needs no bump', 0, session_version_violations($harmless, 'sample.php'));

    $dynamic = "<?php\n\$sql = 'UPDATE users SET ' . implode(', ', \$updates);\n";
    $t->assertCount('Dynamic column list requires a review marker', 1, session_version_violations($dynamic, 'sample.php'));

    $dynamicReviewed = "<?php\n// @session-version-reviewed: bump appended above\n\$sql = 'UPDATE users SET ' . implode(', ', \$updates);\n";
    $t->assertCount('Reviewed dynamic statement accepted', 0, session_version_violations($dynamicReviewed, 'sample.php'));

    return $t;
}

register_tests(
    'test_every_privileged_update_bumps_session_version',
    'test_session_version_policy_self_check'
);
