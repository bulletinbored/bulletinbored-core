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
 *   $t->run();  // prints results
 */

class Test
{
    private string $name;
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;

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

    public function run(): void
    {
        echo "\n";
        echo str_repeat('=', 60) . "\n";
        echo "Test: {$this->name}\n";
        echo str_repeat('=', 60) . "\n";

        foreach ($this->results as $r) {
            $icon = $r['status'] === 'PASS' ? '✓' : '✗';
            echo "  [{$icon}] {$r['desc']}\n";
        }

        echo str_repeat('-', 60) . "\n";
        echo "Results: {$this->passed} passed, {$this->failed} failed\n";
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

    public function getResults(): array
    {
        return $this->results;
    }
}

/**
 * TestSuite — runs multiple test files and aggregates results.
 */
class TestSuite
{
    private array $tests = [];
    private int $totalPassed = 0;
    private int $totalFailed = 0;

    public function addTest(Test $test): void
    {
        $this->tests[] = $test;
    }

    public function run(): void
    {
        echo "\n";
        echo str_repeat('#', 60) . "\n";
        echo "# TEST SUITE\n";
        echo str_repeat('#', 60) . "\n";

        foreach ($this->tests as $test) {
            $test->run();
            $this->totalPassed += $test->getPassed();
            $this->totalFailed += $test->getFailed();
        }

        echo "\n";
        echo str_repeat('#', 60) . "\n";
        echo "# TOTAL: {$this->totalPassed} passed, {$this->totalFailed} failed\n";
        echo str_repeat('#', 60) . "\n";

        if ($this->totalFailed > 0) {
            exit(1);
        }
    }
}
