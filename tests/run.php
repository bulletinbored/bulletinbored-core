<?php

/**
 * Test runner — executes all registered tests and reports results.
 *
 * Usage:
 *   php tests/run.php              # run all tests
 *   php tests/run.php Auth         # run only tests with 'Auth' in name
 *   php tests/run.php --verbose    # verbose output
 *   php tests/run.php --list       # list all available tests without running
 */

require_once __DIR__ . '/harness.php';

$filter = $argv[1] ?? '';
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);
$listOnly = in_array('--list', $argv);

$testFiles = array_merge(
    glob(__DIR__ . '/*Test.php'),
    glob(__DIR__ . '/../plugins/*/tests/*Test.php')
);
sort($testFiles);

$suite = get_test_suite();
$loaded = 0;
$registeredCount = 0;

foreach ($testFiles as $file) {
    $name = basename($file, 'Test.php');

    if ($filter && stripos($name, $filter) === 0) {
        // Filter matches
    } elseif ($filter && stripos($name, $filter) !== false) {
        // Filter matches
    } elseif ($filter) {
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
    foreach ($suite->getTests() as $test) {
        $testName = is_array($test) ? 'factory' : $test->getName();
        echo "  - {$testName}\n";
    }
    echo str_repeat('-', 40) . "\n";
    echo "Total: {$registeredCount} tests\n";
    exit(0);
}

echo "\n";
echo "Running {$registeredCount} test(s)...\n";
echo "\n";

$suite->run();
