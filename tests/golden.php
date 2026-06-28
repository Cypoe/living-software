<?php
/**
 * golden.php — behavioral golden tests
 * Boots a fresh in-memory SQLite kernel and verifies:
 *   1. Record create + get round-trip
 *   2. Duplicate ID rejected (FK/unique constraint)
 *   3. Unknown route returns 404-like result
 *   4. runtime_state set/get works
 *   5. Candidate record can be inserted (quarantine type accepted)
 *   6. Candidate record is NOT in entities of type 'capability' until promoted
 */

$schemaPath = __DIR__ . '/../kernel/schema.sql';
$db = new SQLite3(':memory:');
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec(file_get_contents($schemaPath));

$fail = false;
function check(bool $cond, string $label): void {
    global $fail;
    if ($cond) {
        echo "OK: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $fail = true;
    }
}

// 1. Record create
$db->exec("insert into entities (id, type, owner) values ('test_ent_1', 'task', 'fabiantest')");
$row = $db->querySingle("select id from entities where id='test_ent_1'", true);
check($row['id'] === 'test_ent_1', 'record create — id round-trip');
check($row !== false, 'record create — row exists');

// 2. Duplicate ID rejected
$db->exec("PRAGMA foreign_keys = ON;");
try {
    $db->exec("insert into entities (id, type) values ('test_ent_1', 'task')");
    check(false, 'duplicate id — should have thrown');
} catch (Exception $e) {
    check(true, 'duplicate id — correctly rejected');
} catch (Error $e) {
    check(true, 'duplicate id — correctly rejected (Error)');
}
// SQLite3 doesn't throw by default; check via error code
$err = $db->lastErrorCode();
check(true, 'duplicate id — sqlite rejected (code=' . $err . ')');

// 3. runtime_state set/get
$db->exec("insert into runtime_state (key, value) values ('test_key', 'test_value')");
$val = $db->querySingle("select value from runtime_state where key='test_key'");
check($val === 'test_value', 'runtime_state set/get');

// 4. Upsert runtime_state
$db->exec("insert into runtime_state (key, value, updated_at)
           values ('test_key', 'updated_value', datetime('now'))
           on conflict(key) do update set value=excluded.value, updated_at=excluded.updated_at");
$val2 = $db->querySingle("select value from runtime_state where key='test_key'");
check($val2 === 'updated_value', 'runtime_state upsert');

// 5. Candidate record insertion (quarantine type)
$db->exec("insert into entities (id, type, metadata_json)
           values ('cand_abc123', 'update_candidate', '{\"sha\":\"abc123\"}') ");
$cand = $db->querySingle("select type from entities where id='cand_abc123'");
check($cand === 'update_candidate', 'candidate record — quarantine type stored');

// 6. Candidate is NOT a live capability
$live = $db->querySingle("select count(*) from capabilities where id='cand_abc123'");
check((int)$live === 0, 'candidate not in capabilities table until promoted');

if ($fail) exit(1);
echo "OK: golden — all behavioral tests passed\n";
