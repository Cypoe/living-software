<?php
/**
 * tests/framework.php — minimal test framework for Living Software
 *
 * Zero dependencies. Every test file includes this.
 *
 * Usage:
 *   require __DIR__ . '/framework.php';
 *   test('my test', function() {
 *       assert_eq(1 + 1, 2);
 *       assert_true(is_string('hello'));
 *   });
 *   run_tests();
 *
 * Exit code: 0 = all pass, 1 = failures.
 */

declare(strict_types=1);
define('LS_TEST_MODE', true);

$_LS_TESTS   = [];
$_LS_RESULTS = [];

function test(string $name, callable $fn): void {
    global $_LS_TESTS;
    $_LS_TESTS[] = ['name' => $name, 'fn' => $fn];
}

function run_tests(): never {
    global $_LS_TESTS, $_LS_RESULTS;
    $pass = $fail = 0;
    foreach ($_LS_TESTS as $t) {
        try {
            ($t['fn'])();
            $_LS_RESULTS[] = ['name' => $t['name'], 'status' => 'PASS'];
            echo "  \e[32m✓\e[0m " . $t['name'] . PHP_EOL;
            $pass++;
        } catch (Throwable $e) {
            $_LS_RESULTS[] = ['name' => $t['name'], 'status' => 'FAIL', 'error' => $e->getMessage()];
            echo "  \e[31m✗\e[0m " . $t['name'] . PHP_EOL;
            echo "    → " . $e->getMessage() . PHP_EOL;
            $fail++;
        }
    }
    echo PHP_EOL . ($fail === 0 ? "\e[32m" : "\e[31m");
    echo "Tests: {$pass} passed, {$fail} failed\e[0m" . PHP_EOL;
    exit($fail > 0 ? 1 : 0);
}

// ─── Assertions ──────────────────────────────────────────────────────────────

function assert_eq(mixed $actual, mixed $expected, string $msg = ''): void {
    if ($actual !== $expected) {
        $a = json_encode($actual);  $e = json_encode($expected);
        throw new AssertionError(($msg ? "$msg: " : '') . "expected {$e}, got {$a}");
    }
}

function assert_neq(mixed $actual, mixed $unexpected, string $msg = ''): void {
    if ($actual === $unexpected) {
        throw new AssertionError(($msg ? "$msg: " : '') . "expected not " . json_encode($unexpected));
    }
}

function assert_true(mixed $val, string $msg = ''): void {
    if (!$val) throw new AssertionError($msg ?: 'Expected truthy, got ' . json_encode($val));
}

function assert_false(mixed $val, string $msg = ''): void {
    if ($val) throw new AssertionError($msg ?: 'Expected falsy, got ' . json_encode($val));
}

function assert_count(array $arr, int $count, string $msg = ''): void {
    assert_eq(count($arr), $count, $msg ?: "Array count");
}

function assert_contains(mixed $needle, array $haystack, string $msg = ''): void {
    if (!in_array($needle, $haystack, true))
        throw new AssertionError(($msg ?: 'Expected array to contain ') . json_encode($needle));
}

function assert_keys(array $arr, array $keys, string $msg = ''): void {
    foreach ($keys as $k) {
        if (!array_key_exists($k, $arr))
            throw new AssertionError(($msg ?: "Missing key: ") . $k);
    }
}

function assert_throws(callable $fn, string $class = Throwable::class, string $msg = ''): void {
    try { $fn(); }
    catch (Throwable $e) {
        if (!($e instanceof $class))
            throw new AssertionError("Expected {$class}, got " . get_class($e));
        return;
    }
    throw new AssertionError($msg ?: "Expected exception {$class} but none thrown");
}

// ─── In-memory test DB factory ───────────────────────────────────────────────

function test_db(): SQLite3 {
    static $schema = null;
    if ($schema === null) {
        $schema = file_get_contents(dirname(__DIR__) . '/kernel/schema.sql');
    }
    $db = new SQLite3(':memory:');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec($schema);
    return $db;
}

// ─── Fixture helpers ─────────────────────────────────────────────────────────

function fixture_entity(SQLite3 $db, array $over = []): string {
    $id    = $over['id']    ?? 'e-' . bin2hex(random_bytes(4));
    $type  = $over['type']  ?? 'note';
    $label = $over['label'] ?? 'Test entity';
    $meta  = json_encode($over['metadata'] ?? []);
    $vis   = $over['visibility'] ?? 'private';
    $db->exec("INSERT INTO entities(id,type,label,visibility,metadata_json) VALUES
        ('" . SQLite3::escapeString($id) . "',
         '" . SQLite3::escapeString($type) . "',
         '" . SQLite3::escapeString($label) . "',
         '" . SQLite3::escapeString($vis) . "',
         '" . SQLite3::escapeString($meta) . "')");
    return $id;
}

function fixture_carrier(SQLite3 $db, string $entity_id, array $over = []): string {
    $id      = $over['id']      ?? 'c-' . bin2hex(random_bytes(4));
    $ctype   = $over['carrier_type'] ?? 'text';
    $content = $over['content'] ?? '';
    $db->exec("INSERT INTO carriers(id,entity_id,carrier_type,content) VALUES
        ('" . SQLite3::escapeString($id) . "',
         '" . SQLite3::escapeString($entity_id) . "',
         '" . SQLite3::escapeString($ctype) . "',
         '" . SQLite3::escapeString($content) . "')");
    return $id;
}
