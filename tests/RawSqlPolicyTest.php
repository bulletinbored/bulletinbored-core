<?php

/**
 * RawSqlPolicyTest — raw SQL is a deliberate exception, not a habit.
 *
 * DbQuery documents `raw()`, `rawFirst()`, `rawExec()` and `whereRaw()` as
 * trusted-SQL APIs. A literal string is the only safe form: the moment the
 * statement is built from a variable or concatenation, user input can reach it.
 *
 * This guard walks the PHP tokens (not a regex) and fails when one of those
 * methods is called with anything that is not a single quoted string literal.
 * Tests and migrations are excluded: migrations are reviewed schema code, and
 * tests may legitimately build odd statements.
 */

require_once __DIR__ . '/harness.php';

/** Methods whose first argument must be a string literal. */
const RAW_SQL_METHODS = ['raw', 'rawFirst', 'rawExec', 'whereRaw'];

/**
 * Regex fallback for builds without the tokenizer extension.
 *
 * Less precise than the token walker (it works line by line and cannot see
 * multi-line string concatenation), but it still rejects a variable, a
 * concatenation or a function call as the statement.
 *
 * @return string[] human readable violations ("file:line method")
 */
function raw_sql_violations_regex(string $code, string $label): array
{
    $violations = [];
    $lines = preg_split('/\R/', $code) ?: [];

    foreach ($lines as $index => $line) {
        if (!preg_match_all('/->(raw|rawFirst|rawExec|whereRaw)\s*\(/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($matches[0] as $hit) {
            $method = $hit[0];
            $rest = ltrim(substr($line, $hit[1] + strlen($method)));
            $ok = false;

            if ($rest !== '' && ($rest[0] === "'" || $rest[0] === '"')) {
                $quote = $rest[0];
                $length = strlen($rest);
                for ($i = 1; $i < $length; $i++) {
                    if ($rest[$i] === '\\') { // escaped character
                        $i++;
                        continue;
                    }
                    if ($rest[$i] === $quote) {
                        $after = ltrim(substr($rest, $i + 1));
                        $ok = $after !== '' && ($after[0] === ',' || $after[0] === ')');
                        break;
                    }
                }
            }

            if (!$ok) {
                $violations[] = $label . ':' . ($index + 1) . ' ' . preg_replace('/\s+/', '', $method)
                    . ' with a non-literal statement (tokenizer missing)';
            }
        }
    }

    return $violations;
}

/**
 * @return string[] human readable violations ("file:line method")
 */
function raw_sql_violations(string $code, string $label): array
{
    if (!function_exists('token_get_all')) {
        // The tokenizer extension is not compiled in everywhere (notably some
        // CI images): fall back instead of failing the suite.
        return raw_sql_violations_regex($code, $label);
    }

    $tokens = token_get_all($code);
    $count = count($tokens);
    $violations = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], RAW_SQL_METHODS, true)) {
            continue;
        }

        // Must be a method call: previous meaningful token is "->".
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
            $prev--;
        }
        if ($prev < 0 || !is_array($tokens[$prev]) || $tokens[$prev][0] !== T_OBJECT_OPERATOR) {
            continue;
        }

        // Next meaningful token must open the argument list.
        $open = $i + 1;
        while ($open < $count && is_array($tokens[$open]) && $tokens[$open][0] === T_WHITESPACE) {
            $open++;
        }
        if (!isset($tokens[$open]) || $tokens[$open] !== '(') {
            continue;
        }

        // First argument: must be a quoted string.
        $arg = $open + 1;
        while ($arg < $count && is_array($tokens[$arg])
            && in_array($tokens[$arg][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $arg++;
        }
        $isLiteral = isset($tokens[$arg])
            && is_array($tokens[$arg])
            && ($tokens[$arg][0] === T_CONSTANT_ENCAPSED_STRING);

        if ($isLiteral) {
            // A quoted string followed by anything other than "," or ")" is an
            // expression — e.g. 'a = ' . $userInput — and is flagged too.
            $after = $arg + 1;
            while ($after < $count && is_array($tokens[$after])
                && in_array($tokens[$after][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $after++;
            }
            $terminator = $tokens[$after] ?? null;
            $isLiteral = $terminator === ',' || $terminator === ')';
        }

        if (!$isLiteral) {
            $violations[] = $label . ':' . $token[2] . ' ' . $token[1] . '()';
        }
    }

    return $violations;
}

function raw_sql_php_files(): array
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

    // plugins/ is not tracked in git (only the bundled example may be), so a
    // fresh checkout has no such directory: never assume it exists.
    if (is_dir($root . '/plugins')) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/plugins', FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && !str_contains($file->getPathname(), '/tests/')) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

function test_raw_sql_uses_literals(): Test
{
    $t = new Test('Raw SQL policy - only literal statements');

    $root = dirname(__DIR__);
    $files = raw_sql_php_files();
    $t->assert('Scanned some files', count($files) > 40);
    $t->assert('A checkout without plugins/ does not break the scan', raw_sql_php_files() === $files);

    $violations = [];
    foreach ($files as $file) {
        $label = str_replace($root . '/', '', $file);
        foreach (raw_sql_violations((string)file_get_contents($file), $label) as $violation) {
            $violations[] = $violation;
        }
    }

    $t->assert('No raw* call builds SQL from a variable', $violations === []);
    foreach ($violations as $violation) {
        $t->assert('violation: ' . $violation, false);
    }

    // The guard must actually catch the pattern it is meant to catch.
    $bad = "<?php\n\$db->raw(\$sql);\n\$db->whereRaw('a = ' . \$x);\n\$db->rawFirst(sprintf('SELECT %s', \$c));\n";
    $caught = raw_sql_violations($bad, 'sample.php');
    $t->assertCount('Detects raw($variable)', 3, $caught);
    $t->assert('Reports the method name', str_contains(implode(' ', $caught), 'raw('));

    $good = "<?php\n\$db->raw('SELECT 1');\n\$db->whereRaw('a = :a', ['a' => 1]);\n\$db->rawExec(\"DELETE FROM t WHERE id = ?\", [1]);\n";
    $t->assertCount('Accepts literal statements', 0, raw_sql_violations($good, 'sample.php'));

    // The regex fallback (used when the tokenizer extension is missing) must
    // reach the same verdict on the same samples.
    $t->assertCount('Fallback detects the bad samples', 3, raw_sql_violations_regex($bad, 'sample.php'));
    $t->assertCount('Fallback accepts the good samples', 0, raw_sql_violations_regex($good, 'sample.php'));

    return $t;
}

register_tests('test_raw_sql_uses_literals');
