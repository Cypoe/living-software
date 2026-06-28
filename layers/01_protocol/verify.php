<?php
/**
 * Layer 01 — Protocol verification
 * Boots in-memory DB, inserts test records, runs all six operations.
 */
$root = dirname(__DIR__, 2);
$db   = new SQLite3(':memory:');
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec(file_get_contents("{$root}/kernel/schema.sql"));
require_once __DIR__ . '/protocol.php';

$fail = false;
function chk(bool $c, string $l): void { global $fail; echo ($c?'OK: ':'FAIL: ').$l."\n"; if(!$c)$fail=true; }

$db->exec("insert into entities (id,type,owner,metadata_json) values ('e1','task','alice','{\"priority\":\"high\"}')");
$db->exec("insert into entities (id,type,owner,metadata_json) values ('e2','task','bob','{\"priority\":\"low\"}')");
$db->exec("insert into entities (id,type,owner,metadata_json) values ('e3','note','alice','{}') ");

$tasks = proto_select($db, 'task');
chk(count($tasks) === 2, 'proto_select returns 2 tasks');

$high = proto_filter($tasks, 'priority', 'high');
chk(count($high) === 1 && $high[0]['id'] === 'e1', 'proto_filter: priority=high');

$notes = proto_select($db, 'note');
$joined = proto_join($tasks, $notes, 'owner', 'owner');
chk(count($joined) === 1, 'proto_join: alice task joins alice note');

$upper = proto_transform($tasks, fn($r) => array_merge($r, ['id' => strtoupper($r['id'])]));
chk($upper[0]['id'] === 'E1' || $upper[1]['id'] === 'E1', 'proto_transform applies fn');

$projected = proto_project($tasks, ['id','type']);
chk(isset($projected[0]['id']) && !isset($projected[0]['owner']), 'proto_project keeps only specified fields');

$rendered = proto_render('Task {{id}} by {{owner}} ({{priority}})', $tasks[0]);
chk(str_contains($rendered, 'e') && str_contains($rendered, 'alice'), 'proto_render substitutes fields');

if ($fail) { echo "LAYER 01 FAILED\n"; exit(1); }
echo "LAYER 01 OK\n";
