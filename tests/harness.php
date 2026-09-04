<?php

/**
 * Minimal test harness — zero dependencies.
 *
 * Usage:
 *   $t = new Test('MyTest');
 *   $t->assert('description', $expected === $actual);
 *   $t->assertEquals('description', $expected, $actual);
 *   $t->assertNull('description', $value);
 *   $t->assertNotNull('description', $value);
 *   $t->assertTrue('description', $value);
 *   $t->assertFalse('description', $value);
 *   $t->assertThrows('description', fn() => some_function(), Exception::class);
 *   $t->assertNotThrows('description', fn() => some_function());
 *   $t->run();  // prints results
 *
 * Test files should register tests but NOT call run() automatically.
 * Use: register_tests(MyTest::class) at the end of each test file.
 */

class Test
{
    private string $name;
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private int $errors = 0;

    public function __construct(string $name = '')
    {
        $this->name = $name;
    }

    public function assert(string $desc, bool $condition): void
    {
        if ($condition) {
            $this->passed++;
            $this->results[] = ['status' => 'PASS', 'desc' => $desc];
        } else {
            $this->failed++;
            $this->results[] = ['status' => 'FAIL', 'desc' => $desc];
        }
    }

    public function assertEquals(string $desc, $expected, $actual): void
    {
        $this->assert("{$desc} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")", $expected === $actual);
    }

    public function assertNotEquals(string $desc, $expected, $actual): void
    {
        $this->assert("{$desc} (expected NOT: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")", $expected !== $actual);
    }

    public function assertNull(string $desc, $value): void
    {
        $this->assert($desc, $value === null);
    }

    public function assertNotNull(string $desc, $value): void
    {
        $this->assert($desc, $value !== null);
    }

    public function assertTrue(string $desc, $value): void
    {
        $this->assert($desc, $value === true);
    }

    public function assertFalse(string $desc, $value): void
    {
        $this->assert($desc, $value === false);
    }

    public function assertCount(string $desc, int $expected, array $array): void
    {
        $this->assertEquals($desc, $expected, count($array));
    }

    public function assertContains(string $desc, $needle, array $haystack): void
    {
        $this->assert($desc, in_array($needle, $haystack, true));
    }

    public function assertInstanceOf(string $desc, string $class, $object): void
    {
        $this->assert($desc, $object instanceof $class);
    }

    /**
     * Assert that callable throws an exception of specified type.
     * If $exceptionClass is null, asserts that ANY exception is thrown.
     */
    public function assertThrows(string $desc, callable $callable, ?string $exceptionClass = null): void
    {
        try {
            $callable();
            $this->failed++;
            $this->results[] = ['status' => 'FAIL', 'desc' => $desc . ' (expected exception but none thrown)'];
        } catch (\Throwable $e) {
            if ($exceptionClass === null || $e instanceof $exceptionClass) {
                $this->passed++;
                $this->results[] = ['status' => 'PASS', 'desc' => $desc];
            } else {
                $this->failed++;
                $this->results[] = ['status' => 'FAIL', 'desc' => $desc . ' (wrong exception type: ' . get_class($e) . ')'];
            }
        }
    }

    /**
     * Assert that callable does NOT throw an exception.
     */
    public function assertNotThrows(string $desc, callable $callable): void
    {
        try {
            $callable();
            $this->passed++;
            $this->results[] = ['status' => 'PASS', 'desc' => $desc];
        } catch (\Throwable $e) {
            $this->failed++;
            $this->results[] = ['status' => 'FAIL', 'desc' => $desc . ' (unexpected exception: ' . $e->getMessage() . ')'];
        }
    }

    /**
     * Assert that callable returns a value that matches the predicate.
     * Example: $t->assertThat('valid email', fn() => filter_var($email, FILTER_VALIDATE_EMAIL), fn($v) => $v !== false);
     */
    public function assertThat(string $desc, callable $callable, callable $predicate): void
    {
        try {
            $value = $callable();
            if ($predicate($value)) {
                $this->passed++;
                $this->results[] = ['status' => 'PASS', 'desc' => $desc];
            } else {
                $this->failed++;
                $this->results[] = ['status' => 'FAIL', 'desc' => $desc . ' (predicate failed for: ' . var_export($value, true) . ')'];
            }
        } catch (\Throwable $e) {
            $this->failed++;
            $this->results[] = ['status' => 'FAIL', 'desc' => $desc . ' (exception: ' . $e->getMessage() . ')'];
        }
    }

    public function run(): void
    {
        echo "\n";
        echo str_repeat('=', 60) . "\n";
        echo "Test: {$this->name}\n";
        echo str_repeat('=', 60) . "\n";

        foreach ($this->results as $r) {
            $icon = $r['status'] === 'PASS' ? '✓' : ($r['status'] === 'ERROR' ? '!' : '✗');
            echo "  [{$icon}] {$r['desc']}\n";
        }

        echo str_repeat('-', 60) . "\n";
        echo "Results: {$this->passed} passed, {$this->failed} failed";
        if ($this->errors > 0) {
            echo ", {$this->errors} errors";
        }
        echo "\n";
        echo str_repeat('=', 60) . "\n";
    }

    public function getPassed(): int
    {
        return $this->passed;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function getErrors(): int
    {
        return $this->errors;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

/**
 * TestSuite — runs multiple tests and aggregates results.
 * Supports registration pattern: tests are registered but NOT executed until run() is called.
 */
class TestSuite
{
    private array $tests = [];
    private int $totalPassed = 0;
    private int $totalFailed = 0;
    private int $totalErrors = 0;

    public function addTest(Test $test): void
    {
        $this->tests[] = $test;
    }

    public function addTestFactory(callable $factory): void
    {
        $this->tests[] = ['factory' => $factory];
    }

    public function run(): void
    {
        echo "\n";
        echo str_repeat('#', 60) . "\n";
        echo "# TEST SUITE\n";
        echo str_repeat('#', 60) . "\n";

        foreach ($this->tests as $testOrFactory) {
            if (is_array($testOrFactory) && isset($testOrFactory['factory'])) {
                $test = $testOrFactory['factory']();
            } else {
                $test = $testOrFactory;
            }

            try {
                $test->run();
                $this->totalPassed += $test->getPassed();
                $this->totalFailed += $test->getFailed();
                $this->totalErrors += $test->getErrors();
            } catch (\Throwable $e) {
                $this->totalErrors++;
                echo "\n";
                echo "  [!!] ERROR in {$test->getName()}: " . $e->getMessage() . "\n";
                echo "       File: " . $e->getFile() . ":" . $e->getLine() . "\n";
            }
        }

        echo "\n";
        echo str_repeat('#', 60) . "\n";
        echo "# TOTAL: {$this->totalPassed} passed, {$this->totalFailed} failed";
        if ($this->totalErrors > 0) {
            echo ", {$this->totalErrors} errors";
        }
        echo "\n";
        echo str_repeat('#', 60) . "\n";

        if ($this->totalFailed > 0 || $this->totalErrors > 0) {
            exit(1);
        }
    }

    public function getTotalPassed(): int
    {
        return $this->totalPassed;
    }

    public function getTotalFailed(): int
    {
        return $this->totalFailed;
    }

    public function getTotalErrors(): int
    {
        return $this->totalErrors;
    }

    public function getTestCount(): int
    {
        return count($this->tests);
    }

    public function getTests(): array
    {
        return $this->tests;
    }
}

/**
 * Global test suite instance for registration pattern.
 */
$GLOBALS['__test_suite'] = new TestSuite();

function get_test_suite(): TestSuite
{
    return $GLOBALS['__test_suite'];
}

/**
 * Register a test function to be executed by the runner.
 * Usage in test files:
 *   function test_foo(): Test { ... }
 *   register_test('test_foo');
 */
function register_test(string $functionName): void
{
    $suite = get_test_suite();
    $suite->addTestFactory(function() use ($functionName) {
        return $functionName();
    });
}

/**
 * Register multiple test functions.
 */
function register_tests(string ...$functionNames): void
{
    foreach ($functionNames as $name) {
        register_test($name);
    }
}
