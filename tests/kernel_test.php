<?php
/**
 * tests/kernel_test.php — kernel schema + boot tests
 */

require __DIR__ . '/framework.php';

test('test_db() boots without error', function() {
    $db = test_db();
    assert_true($db instanceof SQLite3);
});

test('entities table exists', function() {
    $db  = test_db();
    $res = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='entities'");
    assert_eq($res, 'entities');
});

test('schemas table exists', function() {
    $db  = test_db();
    $res = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='schemas'");
    assert_eq($res, 'schemas');
});

test('carriers table exists', function() {
    $db  = test_db();
    $res = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='carriers'");
    assert_eq($res, 'carriers');
});

test('runtime_state has schema_version seed', function() {
    $db  = test_db();
    $val = $db->querySingle("SELECT value FROM runtime_state WHERE key='schema_version'");
    assert_eq($val, '2');
});

test('runtime_state has deploy_method seed', function() {
    $db  = test_db();
    $val = $db->querySingle("SELECT value FROM runtime_state WHERE key='deploy_method'");
    assert_eq($val, 'git');
});

test('entity insert and select', function() {
    $db = test_db();
    $id = fixture_entity($db, ['type' => 'note', 'label' => 'Hello World']);
    $row = $db->querySingle("SELECT label FROM entities WHERE id='" . SQLite3::escapeString($id) . "'");
    assert_eq($row, 'Hello World');
});

test('carrier FK enforced', function() {
    $db = test_db();
    assert_throws(function() use ($db) {
        $db->exec("INSERT INTO carriers(id,entity_id,carrier_type) VALUES('c1','nonexistent','text')");
    }, Exception::class);
});

test('FTS5 trigger fires on entity insert', function() {
    $db = test_db();
    $id = fixture_entity($db, ['label' => 'searchable content', 'type' => 'note']);
    $found = $db->querySingle("SELECT id FROM entities_fts WHERE entities_fts MATCH 'searchable'");
    assert_eq($found, $id);
});

test('soft-delete via status field', function() {
    $db = test_db();
    $id = fixture_entity($db);
    $db->exec("UPDATE entities SET status='deleted' WHERE id='" . SQLite3::escapeString($id) . "'");
    $status = $db->querySingle("SELECT status FROM entities WHERE id='" . SQLite3::escapeString($id) . "'");
    assert_eq($status, 'deleted');
});

test('materialized_views table exists', function() {
    $db  = test_db();
    $res = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='materialized_views'");
    assert_eq($res, 'materialized_views');
});

test('identities table exists', function() {
    $db  = test_db();
    $res = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='identities'");
    assert_eq($res, 'identities');
});

run_tests();
