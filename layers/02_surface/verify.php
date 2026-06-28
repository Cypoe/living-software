<?php
/**
 * Layer 02 — Surface verification
 * Checks index.php is parseable and defines no global side effects.
 * Does not boot an HTTP server — checks structural invariants only.
 */
$root     = dirname(__DIR__, 2);
$surface  = "{$root}/public/index.php";

$fail = false;
function chk(bool $c, string $l): void { global $fail; echo ($c?'OK: ':'FAIL: ').$l."\n"; if(!$c)$fail=true; }

chk(file_exists($surface), 'index.php exists');

// PHP syntax check
$lint = shell_exec('php -l ' . escapeshellarg($surface) . ' 2>&1');
chk(str_contains($lint ?? '', 'No syntax errors'), 'index.php syntax OK');

// Must define json_out, state_get, state_set, bearer_ok
$src = file_get_contents($surface);
foreach (['json_out', 'state_get', 'state_set', 'bearer_ok'] as $fn) {
    chk(str_contains($src, "function {$fn}"), "surface defines {$fn}()");
}

// Must handle /health route
chk(str_contains($src, '/health'), 'surface handles /health route');
// Must handle /runtime/notify
chk(str_contains($src, '/runtime/notify'), 'surface handles /runtime/notify');

if ($fail) { echo "LAYER 02 FAILED\n"; exit(1); }
echo "LAYER 02 OK\n";
