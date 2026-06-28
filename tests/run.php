<?php
/**
 * tests/run.php — Living Software test runner
 *
 * Usage:
 *   php tests/run.php              # run all test cases
 *   php tests/run.php golden       # run only golden.php
 *   php tests/run.php protocol_read protocol_write   # run named cases
 *
 * Exit codes:
 *   0  — all passed
 *   1  — one or more failed
 *
 * Each test case in tests/cases/ must expose:
 *   run_tests(SQLite3 $db, Harness $h): void
 */

declare(strict_types=1);

// ─── Harness ─────────────────────────────────────────────────────────────────

class Harness {
    private int $pass = 0;
    private int $fail = 0;
    private array $failures = [];
    public string $suite = '';

    public function ok(bool $cond, string $label): void {
        if ($cond) {
            echo "  ✓ {$label}\n";
            $this->pass++;
        } else {
            echo "  ✗ {$label}\n";
            $this->fail++;
            $this->failures[] = "{$this->suite}: {$label}";
        }
    }

    public function eq(mixed $a, mixed $b, string $label): void {
        $this->ok($a === $b, "{$label} [expected " . json_encode($b) . ", got " . json_encode($a) . "]");
    }

    public function throws(callable $fn, string $label): void {
        try { $fn(); $this->ok(false, "{$label} [no exception thrown]"); }
        catch (Throwable) { $this->ok(true, $label); }
    }

    public function summary(): bool {
        $total = $this->pass + $this->fail;
        echo "\n" . ($this->fail === 0 ? '✓' : '✗') . " {$this->pass}/{$total} passed";
        if ($this->fail > 0) {
            echo " — FAILURES:\n";
            foreach ($this->failures as $f) echo "    - {$f}\n";
        }
        echo "\n";
        return $this->fail === 0;
    }
}

