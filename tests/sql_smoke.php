<?php
/**
 * sql_smoke.php — boots SQLite from kernel/schema.sql and verifies tables exist
 */

$schemaPath = __DIR__ . '/../kernel/schema.sql';
$sql = file_get_contents($schemaPath);
assert($sql !== false, 'Cannot read schema.sql');

$db = new SQLite3(':memory:');
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec($sql);

$required = ['entities', 'schemas', 'carriers', 'capabilities', 'applications', 'runtime_state'];
foreach ($required as $table) {
    $r = $db->querySingle("select count(*) from sqlite_master where type='table' and name='{$table}'");
    if (!$r) {
        echo "FAIL: table '{$table}' missing\n";
        exit(1);
    }
}

echo "OK: sql_smoke — all required tables present\n";
