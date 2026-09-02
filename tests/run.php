<?php

/**
 * Test runner — executes all test files and reports results.
 *
 * Usage:
 *   php tests/run.php              # run all tests
 *   php tests/run.php DbQuery      # run only DbQuery tests
 *   php tests/run.php --verbose    # verbose output
 */

require_once __DIR__ . '/harness.php';

$filter = $argv[1] ?? '';
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);

$testFiles = array_merge(
    glob(__DIR__ . '/*Test.php'),
    glob(__DIR__ . '/../plugins/*/tests/*Test.php')
);
sort($testFiles);

$suite = new TestSuite();
$loaded = 0;

foreach ($testFiles as $file) {
    $name = basename($file, 'Test.php');

    if ($filter && stripos($name, $filter) === 0) {
        // Filter matches
    } elseif ($filter && stripos($name, $filter) !== false) {
        // Filter matches
    } elseif ($filter) {
        continue;
    }

    require_once $file;
    $loaded++;
}

if ($loaded === 0) {
    echo "No test files found matching filter: {$filter}\n";
    exit(1);
}

echo "\n";
echo str_repeat('*', 60) . "\n";
echo "* bulletinbored test suite\n";
echo "* Running {$loaded} test file(s)\n";
if ($filter) {
    echo "* Filter: {$filter}\n";
}
echo str_repeat('*', 60) . "\n";

// Run the suite
$suite->run();
