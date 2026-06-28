<?php
/**
 * Layer 03 — Capabilities verification
 * For each {id}.json in capabilities/, checks that a matching {id}.php
 * exists, parses, and defines {id}_invoke().
 */
$root    = dirname(__DIR__, 2);
$capDir  = "{$root}/capabilities";

$fail = false;
function chk(bool $c, string $l): void { global $fail; echo ($c?'OK: ':'FAIL: ').$l."\n"; if(!$c)$fail=true; }

$descriptors = glob("{$capDir}/*.json") ?: [];
chk(count($descriptors) > 0, 'at least one capability descriptor exists');

foreach ($descriptors as $jsonFile) {
    $id  = basename($jsonFile, '.json');
    $php = "{$capDir}/{$id}.php";

    chk(file_exists($php), "{$id}: .php exists");
    if (!file_exists($php)) continue;

    $lint = shell_exec('php -l ' . escapeshellarg($php) . ' 2>&1');
    chk(str_contains($lint ?? '', 'No syntax errors'), "{$id}: syntax OK");

    $fn  = str_replace('-', '_', $id) . '_invoke';
    $src = file_get_contents($php);
    chk(str_contains($src, "function {$fn}"), "{$id}: defines {$fn}()");
}

if ($fail) { echo "LAYER 03 FAILED\n"; exit(1); }
echo "LAYER 03 OK\n";
