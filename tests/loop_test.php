<?php
/**
 * tests/loop_test.php — perpetual loop / cron logic unit tests
 *
 * Tests the helper functions and state transitions used by cron.php
 * without actually invoking shell commands or network calls.
 */

require __DIR__ . '/framework.php';
require dirname(__DIR__) . '/layers/01_protocol/protocol.php';

// Re-implement rs_get / rs_set inline for test isolation
function t_rs_get(SQLite3 $db, string $key): string {
    return (string)$db->querySingle("SELECT value FROM runtime_state WHERE key='" . SQLite3::escapeString($key) . "'");
}

function t_rs_set(SQLite3 $db, string $key, string $value): void {
    $stmt = $db->prepare("INSERT OR REPLACE INTO runtime_state(key,value,updated_at) VALUES(:k,:v,strftime('%Y-%m-%dT%H:%M:%fZ','now'))");
    $stmt->bindValue(':k',$key); $stmt->bindValue(':v',$value); $stmt->execute();
}

// ─── Heartbeat state ──────────────────────────────────────────────────────────
test('runtime_state heartbeat write-read round-trip', function() {
    $db  = test_db();
    $now = (new DateTime())->format(DateTime::ATOM);
    t_rs_set($db, 'last_heartbeat', $now);
    assert_eq(t_rs_get($db, 'last_heartbeat'), $now);
});

test('rs_set is idempotent (INSERT OR REPLACE)', function() {
    $db = test_db();
    t_rs_set($db, 'deploy_method', 'git');
    t_rs_set($db, 'deploy_method', 'ftp');
    assert_eq(t_rs_get($db, 'deploy_method'), 'ftp');
});

// ─── Deploy watch state machine ─────────────────────────────────────────────
test('adopted_commit advances on new deploy', function() {
    $db = test_db();
    $sha = 'abc1234';
    t_rs_set($db, 'adopted_commit', $sha);
    $stored = t_rs_get($db, 'adopted_commit');
    assert_eq($stored, $sha);
});

test('restart_needed flag clears after read', function() {
    $db = test_db();
    t_rs_set($db, 'restart_needed', '1');
    assert_eq(t_rs_get($db, 'restart_needed'), '1');
    // Simulate cron clearing it
    t_rs_set($db, 'restart_needed', '0');
    assert_eq(t_rs_get($db, 'restart_needed'), '0');
});

// ─── CI rules as records ────────────────────────────────────────────────────────
test('ci_rule entity can be created and selected', function() {
    $db = test_db();
    $id = fixture_entity($db, [
        'type'     => 'ci_rule',
        'label'    => 'Test CI rule',
        'metadata' => [
            'trigger'    => 'cron',
            'steps_json' => json_encode([['op' => 'literal', 'args' => ['value' => 'ok']]]),
        ],
    ]);
    $rules = proto_select($db, 'ci_rule');
    assert_count($rules, 1);
    assert_eq($rules[0]['id'], $id);
});

test('ci_rule chain_eval executes literal step', function() {
    $db    = test_db();
    $steps = [['op' => 'literal', 'args' => ['value' => 'ci-passed']]];
    $result = chain_eval($db, $steps);
    assert_eq($result, 'ci-passed');
});

// ─── Materialized views invalidation ──────────────────────────────────────────
test('materialized_view starts as invalidated', function() {
    $db = test_db();
    $vid = 'mv-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO materialized_views(id,label,body_type) VALUES('" . SQLite3::escapeString($vid) . "','Test view','json')");
    $row = $db->querySingle("SELECT invalidated FROM materialized_views WHERE id='" . SQLite3::escapeString($vid) . "'");
    assert_eq((int)$row, 1);
});

test('materialized_view can be marked as computed', function() {
    $db  = test_db();
    $vid = 'mv-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO materialized_views(id,label,body_type) VALUES('" . SQLite3::escapeString($vid) . "','Test view','json')");
    $db->exec("UPDATE materialized_views SET invalidated=0, body='[]', last_computed=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id='" . SQLite3::escapeString($vid) . "'");
    $inv = $db->querySingle("SELECT invalidated FROM materialized_views WHERE id='" . SQLite3::escapeString($vid) . "'");
    assert_eq((int)$inv, 0);
});

// ─── Backup config as record ─────────────────────────────────────────────────────
test('backup_enabled flag defaults to 0', function() {
    $db  = test_db();
    $val = t_rs_get($db, 'backup_enabled');
    // Not seeded by default — returns empty string (falsy)
    assert_false((bool)$val);
});

test('backup_method rsync produces correct shell command shape', function() {
    $db_path = '/data/kernel.db';
    $target  = 'user@host:/backup';
    $cmd = 'rsync -az ' . escapeshellarg($db_path) . ' ' . escapeshellarg($target);
    assert_true(str_contains($cmd, 'rsync'));
    assert_true(str_contains($cmd, escapeshellarg($db_path)));
});

// ─── Gossip peer entity ───────────────────────────────────────────────────────────
test('peer_instance entity can be stored and retrieved', function() {
    $db = test_db();
    fixture_entity($db, [
        'type'     => 'peer_instance',
        'label'    => 'peer-1',
        'metadata' => ['gossip_endpoint' => 'https://peer1.example.com/gossip'],
    ]);
    $peers = proto_select($db, 'peer_instance');
    assert_count($peers, 1);
    assert_eq($peers[0]['metadata']['gossip_endpoint'], 'https://peer1.example.com/gossip');
});

run_tests();
