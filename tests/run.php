<?php

/**
 * Test runner — executes all registered tests and reports results.
 *
 * Usage:
 *   php tests/run.php              # run all tests
 *   php tests/run.php Auth         # run only tests with 'Auth' in name
 *   php tests/run.php --verbose    # verbose output
 *   php tests/run.php --list       # list all available tests without running
 *   php tests/run.php --coverage   # report source coverage after the run
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/bootstrap.php';

$filter = '';
$verbose = false;
$listOnly = false;
$coverage = false;

foreach ($argv as $arg) {
    if ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
    } elseif ($arg === '--list') {
        $listOnly = true;
    } elseif ($arg === '--coverage') {
        $coverage = true;
    } elseif ($arg !== __FILE__ && $arg !== 'run.php' && $arg !== 'tests/run.php') {
        $filter = $arg;
    }
}

// Optional line coverage: prefer Xdebug (executable-line totals), then PCOV
// (executed lines only), then fall back to a dependency-free "loaded files"
// report after the run.
$xdebugCoverage = $coverage && extension_loaded('xdebug') && function_exists('xdebug_start_code_coverage');
$pcovCoverage = $coverage && !$xdebugCoverage && extension_loaded('pcov') && function_exists('\pcov\start');
if ($xdebugCoverage) {
    xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
} elseif ($pcovCoverage) {
    \pcov\start();
}

$testFiles = array_merge(
    glob(__DIR__ . '/*Test.php'),
    glob(__DIR__ . '/../plugins/*/tests/*Test.php')
);
sort($testFiles);

$suite = get_test_suite();
$loaded = 0;
$registeredCount = 0;
$validationErrors = [];

foreach ($testFiles as $file) {
    $name = basename($file, 'Test.php');

    if ($filter && stripos($name, $filter) === false) {
        continue;
    }

    $previousCount = $suite->getTestCount();

    require_once $file;

    $newCount = $suite->getTestCount();
    $registeredCount += ($newCount - $previousCount);
    $loaded++;
}

if ($loaded === 0) {
    echo "No test files found matching filter: {$filter}\n";
    exit(1);
}

foreach ($suite->getTests() as $testOrFactory) {
    if (is_array($testOrFactory)) {
        $factory = $testOrFactory['factory'] ?? null;
        if (!is_callable($factory)) {
            $validationErrors[] = 'Invalid test factory registration';
            continue;
        }
        // Do NOT execute factory here - validation only checks callable exists
    } else {
        // Direct Test object - valid
        if (!$testOrFactory instanceof Test) {
            $validationErrors[] = 'Invalid test registration: not a Test instance or factory';
        }
    }
}

if ($validationErrors !== []) {
    foreach ($validationErrors as $error) {
        echo "Invalid test registration: {$error}\n";
    }
    exit(1);
}

echo "\n";
echo str_repeat('*', 60) . "\n";
echo "* bulletinbored test suite\n";
echo "* Loaded {$loaded} test file(s)\n";
if ($filter) {
    echo "* Filter: {$filter}\n";
}
echo str_repeat('*', 60) . "\n";

if ($listOnly) {
    echo "\nRegistered tests:\n";
    echo str_repeat('-', 40) . "\n";
    foreach ($suite->getTests() as $testOrFactory) {
        $name = $testOrFactory['name'] ?? 'unknown';
        echo "  - {$name}\n";
    }
    echo str_repeat('-', 40) . "\n";
    echo "Total: {$registeredCount} tests\n";
    exit(0);
}

echo "\n";
echo "Running {$registeredCount} test(s)...\n";
echo "\n";

$suite->run();

if ($coverage) {
    print_source_coverage($xdebugCoverage, $pcovCoverage);
}

/**
 * Report coverage for the src/ tree: line coverage with Xdebug, executed-line
 * counts with PCOV, otherwise which src/ files were loaded by the suite.
 */
function print_source_coverage(bool $xdebugCoverage, bool $pcovCoverage = false): void
{
    $root = dirname(__DIR__);
    $srcFiles = [];
    if (is_dir($root . '/src')) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $real = str_replace('\\', '/', $file->getRealPath());
                $srcFiles[$real] = str_replace(str_replace('\\', '/', $root) . '/', '', $real);
            }
        }
    }
    ksort($srcFiles);

    echo "\n" . str_repeat('#', 60) . "\n";
    echo "# SOURCE COVERAGE (src/)\n";
    echo str_repeat('#', 60) . "\n";

    if ($xdebugCoverage && function_exists('xdebug_get_code_coverage')) {
        $data = xdebug_get_code_coverage();
        $totalExecutable = 0;
        $totalCovered = 0;
        foreach ($srcFiles as $real => $rel) {
            $executable = 0;
            $covered = 0;
            foreach ($data[$real] ?? [] as $state) {
                if ($state === -1) {
                    continue; // dead code
                }
                $executable++;
                if ($state > 0) {
                    $covered++;
                }
            }
            $totalExecutable += $executable;
            $totalCovered += $covered;
            printf("  %-46s %5.1f%% (%d/%d)\n", $rel, $executable ? 100 * $covered / $executable : 0.0, $covered, $executable);
        }
        echo str_repeat('-', 60) . "\n";
        printf("  Line coverage: %.1f%% (%d/%d executable lines)\n", $totalExecutable ? 100 * $totalCovered / $totalExecutable : 0.0, $totalCovered, $totalExecutable);
    } elseif ($pcovCoverage && function_exists('\pcov\collect')) {
        \pcov\stop();
        $data = \pcov\collect(\pcov\all);
        $totalCovered = 0;
        foreach ($srcFiles as $real => $rel) {
            $covered = 0;
            foreach ($data[$real] ?? [] as $state) {
                if ((int)$state > 0) {
                    $covered++;
                }
            }
            $totalCovered += $covered;
            printf("  %-46s %d covered lines\n", $rel, $covered);
        }
        echo str_repeat('-', 60) . "\n";
        printf("  Covered lines (pcov): %d\n", $totalCovered);
        echo "  (pcov reports executed lines; exact % of executable lines requires Xdebug.)\n";
    } else {
        echo "  No coverage driver (Xdebug/PCOV) is available — reporting which src/ files were loaded.\n";
        echo "  Enable Xdebug (XDEBUG_MODE=coverage) or PCOV for line coverage.\n\n";
        $included = array_map(fn($f) => str_replace('\\', '/', (string)realpath($f)), get_included_files());
        $loadedCount = 0;
        foreach ($srcFiles as $real => $rel) {
            if (in_array($real, $included, true)) {
                $loadedCount++;
            } else {
                echo "  [not loaded] {$rel}\n";
            }
        }
        printf("\n  Files loaded: %d/%d (%.1f%%)\n", $loadedCount, count($srcFiles), count($srcFiles) ? 100 * $loadedCount / count($srcFiles) : 0.0);
    }
    echo str_repeat('#', 60) . "\n";
}
