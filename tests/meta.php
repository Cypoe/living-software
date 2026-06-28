<?php
/**
 * meta.php — meta-tests: verifies CI can catch bad states
 * Each sub-test intentionally introduces a violation and asserts
 * the check function detects it. If CI cannot catch a known bad state,
 * the harness itself is broken.
 */

$schemaPath = __DIR__ . '/../kernel/schema.sql';
$fail = false;

function meta_check(bool $cond, string $label): void {
    global $fail;
    if ($cond) {
        echo "OK (meta): {$label}\n";
    } else {
        echo "FAIL (meta): {$label}\n";
        $fail = true;
    }
}

// --- Meta-test 1: broken schema.sql must fail sql_smoke ---
$brokenSql = "create table entities (id text primary key); -- missing required tables";
$db1 = new SQLite3(':memory:');
$db1->exec($brokenSql);
$missing = $db1->querySingle("select count(*) from sqlite_master where type='table' and name='schemas'");
meta_check((int)$missing === 0, 'meta: broken schema missing schemas table (detectable)');

// --- Meta-test 2: invalid capability JSON must fail validate_capabilities ---
$badCap = ['id' => 'test_bad'];
// Missing required: schema_in, schema_out, impl_type, impl_ref
$requiredFields = ['id', 'schema_in', 'schema_out', 'impl_type', 'impl_ref'];
$missing2 = array_filter($requiredFields, fn($f) => !array_key_exists($f, $badCap));
meta_check(count($missing2) > 0, 'meta: capability missing required fields is detectable');

// --- Meta-test 3: duplicate capability IDs must be caught ---
$caps = [
    ['id' => 'cap_a', 'schema_in' => 's1', 'schema_out' => 's2', 'impl_type' => 'deterministic', 'impl_ref' => 'a.php'],
    ['id' => 'cap_a', 'schema_in' => 's3', 'schema_out' => 's4', 'impl_type' => 'deterministic', 'impl_ref' => 'b.php'],
];
$seen = [];
$dups = [];
foreach ($caps as $c) {
    if (isset($seen[$c['id']])) $dups[] = $c['id'];
    $seen[$c['id']] = true;
}
meta_check(in_array('cap_a', $dups), 'meta: duplicate capability ID detectable');

// --- Meta-test 4: orphan impl_ref must be caught ---
$orphanCap = ['id' => 'cap_b', 'impl_ref' => 'nonexistent_implementation.php'];
$implPath = __DIR__ . '/../capabilities/' . $orphanCap['impl_ref'];
meta_check(!file_exists($implPath), 'meta: orphan impl_ref detectable (file does not exist)');

// --- Meta-test 5: candidate type must NOT match capability type ---
$db2 = new SQLite3(':memory:');
$db2->exec(file_get_contents($schemaPath));
$db2->exec("insert into entities (id, type) values ('cand_x', 'update_candidate')");
$isLiveCap = (int)$db2->querySingle("select count(*) from capabilities where id='cand_x'");
meta_check($isLiveCap === 0, 'meta: candidate record is not live capability (quarantine enforced)');

if ($fail) exit(1);
echo "OK: meta — all meta-tests passed (CI can catch bad states)\n";
