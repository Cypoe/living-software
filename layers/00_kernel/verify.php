<?php
/**
 * Layer 00 — Kernel verification
 * Boots an in-memory DB from schema.sql and asserts the contract.
 */
$root = dirname(__DIR__, 2);
$db   = new SQLite3(':memory:');
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec(file_get_contents("{$root}/kernel/schema.sql"));

$fail = false;
function chk(bool $c, string $label): void {
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $label . "\n";
    if (!$c) $fail = true;
}

// Required tables
$tables = [];
$res = $db->query("select name from sqlite_master where type='table'");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $tables[] = $r['name'];

foreach (['entities','schemas','carriers','applications','runtime_state'] as $t) {
    chk(in_array($t, $tables), "table exists: {$t}");
}

// No views or triggers
$views = $db->querySingle("select count(*) from sqlite_master where type='view'");
chk((int)$views === 0, 'no views in kernel layer');
$triggers = $db->querySingle("select count(*) from sqlite_master where type='trigger'");
chk((int)$triggers === 0, 'no triggers in kernel layer');

// FK pragma active
$fk = $db->querySingle('PRAGMA foreign_keys');
chk((int)$fk === 1, 'foreign_keys pragma ON');

// entities columns
$cols = [];
$res  = $db->query('PRAGMA table_info(entities)');
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $cols[] = $r['name'];
foreach (['id','type','owner','body_ref','metadata_json','created_at'] as $c) {
    chk(in_array($c, $cols), "entities.{$c} exists");
}

if ($fail) { echo "LAYER 00 FAILED\n"; exit(1); }
echo "LAYER 00 OK\n";
