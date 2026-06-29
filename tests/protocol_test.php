<?php
/**
 * tests/protocol_test.php — protocol algebra tests
 */

require __DIR__ . '/framework.php';
require dirname(__DIR__) . '/layers/01_protocol/protocol.php';

// ─── proto_select ────────────────────────────────────────────────────────────

test('proto_select returns active entities of given type', function() {
    $db = test_db();
    fixture_entity($db, ['type' => 'note']);
    fixture_entity($db, ['type' => 'note']);
    fixture_entity($db, ['type' => 'task']);
    $res = proto_select($db, 'note');
    assert_count($res, 2);
    foreach ($res as $r) assert_eq($r['type'], 'note');
});

test('proto_select respects limit and offset', function() {
    $db = test_db();
    for ($i = 0; $i < 5; $i++) fixture_entity($db, ['type' => 'note']);
    $page1 = proto_select($db, 'note', ['limit' => 3, 'offset' => 0]);
    $page2 = proto_select($db, 'note', ['limit' => 3, 'offset' => 3]);
    assert_count($page1, 3);
    assert_count($page2, 2);
});

test('proto_select excludes deleted entities', function() {
    $db = test_db();
    $id = fixture_entity($db, ['type' => 'note']);
    $db->exec("UPDATE entities SET status='deleted' WHERE id='" . SQLite3::escapeString($id) . "'");
    $res = proto_select($db, 'note');
    assert_count($res, 0);
});

// ─── proto_filter ────────────────────────────────────────────────────────────

test('proto_filter with callable', function() {
    $records = [
        ['type' => 'note', 'metadata' => ['done' => false]],
        ['type' => 'note', 'metadata' => ['done' => true]],
    ];
    $res = proto_filter($records, fn($r) => $r['metadata']['done'] === true);
    assert_count($res, 1);
});

test('proto_filter with array shorthand', function() {
    $records = [
        ['type' => 'note', 'metadata' => ['priority' => 'high']],
        ['type' => 'note', 'metadata' => ['priority' => 'low']],
    ];
    $res = proto_filter($records, ['metadata.priority' => 'high']);
    assert_count($res, 1);
    assert_eq($res[0]['metadata']['priority'], 'high');
});

// ─── proto_join ──────────────────────────────────────────────────────────────

test('proto_join inner join', function() {
    $a = [['id' => '1', 'metadata' => []], ['id' => '2', 'metadata' => []]];
    $b = [['entity_id' => '1', 'content' => 'hello', 'metadata' => []]];
    $res = proto_join($a, $b, 'id', 'entity_id');
    assert_count($res, 1);
    assert_eq($res[0]['_joined']['content'], 'hello');
});

test('proto_join left join includes unmatched left rows', function() {
    $a = [['id' => '1', 'metadata' => []], ['id' => '2', 'metadata' => []]];
    $b = [['entity_id' => '1', 'content' => 'hello', 'metadata' => []]];
    $res = proto_join($a, $b, 'id', 'entity_id', 'left');
    assert_count($res, 2);
    assert_true($res[1]['_joined'] === null);
});

// ─── proto_transform ─────────────────────────────────────────────────────────

test('proto_transform with callable', function() {
    $records = [['id' => '1', 'metadata' => ['x' => 1]]];
    $res = proto_transform($records, fn($r) => array_merge($r, ['doubled' => $r['metadata']['x'] * 2]));
    assert_eq($res[0]['doubled'], 2);
});

test('proto_transform flatten_metadata built-in', function() {
    $records = [['id' => '1', 'metadata' => ['title' => 'Hello']]];
    $res = proto_transform($records, 'flatten_metadata');
    assert_eq($res[0]['title'], 'Hello');
});

test('proto_transform strip_internal built-in', function() {
    $records = [['id' => '1', 'metadata_json' => '{"x":1}', '_joined' => ['foo'], 'metadata' => []]];
    $res = proto_transform($records, 'strip_internal');
    assert_false(array_key_exists('metadata_json', $res[0]));
    assert_false(array_key_exists('_joined', $res[0]));
});

// ─── proto_project ───────────────────────────────────────────────────────────

test('proto_project keeps only specified fields', function() {
    $records = [['id' => '1', 'type' => 'note', 'label' => 'hi', 'metadata' => ['x' => 1]]];
    $res = proto_project($records, ['id', 'label']);
    assert_keys($res[0], ['id', 'label']);
    assert_false(array_key_exists('type', $res[0]));
});

test('proto_project supports dot-notation', function() {
    $records = [['id' => '1', 'metadata' => ['title' => 'My Title']]];
    $res = proto_project($records, ['id', 'metadata.title']);
    assert_eq($res[0]['title'], 'My Title');
});

// ─── proto_render ────────────────────────────────────────────────────────────

test('proto_render basic substitution', function() {
    $out = proto_render('<h1>{{label}}</h1>', ['label' => 'Hello', 'metadata' => []]);
    assert_eq($out, '<h1>Hello</h1>');
});

test('proto_render escapes HTML', function() {
    $out = proto_render('{{label}}', ['label' => '<script>', 'metadata' => []]);
    assert_eq($out, '&lt;script&gt;');
});

test('proto_render raw block skips escaping', function() {
    $out = proto_render('{{raw:label}}', ['label' => '<b>bold</b>', 'metadata' => []]);
    assert_eq($out, '<b>bold</b>');
});

test('proto_render if block shows on truthy', function() {
    $out = proto_render('{{if:show}}visible{{/if:show}}', ['show' => true, 'metadata' => []]);
    assert_eq($out, 'visible');
});

test('proto_render if block hides on falsy', function() {
    $out = proto_render('{{if:show}}visible{{/if:show}}', ['show' => false, 'metadata' => []]);
    assert_eq($out, '');
});

test('proto_render each block loops', function() {
    $out = proto_render('{{each:items}}<li>{{item.name}}</li>{{/each:items}}', [
        'items' => [['name' => 'A'], ['name' => 'B']],
        'metadata' => [],
    ]);
    assert_eq($out, '<li>A</li><li>B</li>');
});

// ─── proto_search ────────────────────────────────────────────────────────────

test('proto_search returns matching entities', function() {
    $db = test_db();
    fixture_entity($db, ['label' => 'unique frobnicator term', 'type' => 'note']);
    fixture_entity($db, ['label' => 'something unrelated', 'type' => 'note']);
    $res = proto_search($db, 'frobnicator');
    assert_count($res, 1);
    assert_true(str_contains($res[0]['label'], 'frobnicator'));
});

// ─── chain_eval ──────────────────────────────────────────────────────────────

test('chain_eval select + project', function() {
    $db = test_db();
    fixture_entity($db, ['type' => 'note', 'label' => 'Chain test']);
    $result = chain_eval($db, [
        ['op' => 'select',  'args' => ['type' => 'note']],
        ['op' => 'project', 'args' => ['fields' => ['id', 'label']]],
    ]);
    assert_true(count($result) >= 1);
    assert_keys($result[0], ['id', 'label']);
});

test('chain_eval literal op returns value', function() {
    $db  = test_db();
    $res = chain_eval($db, [['op' => 'literal', 'args' => ['value' => 42]]]);
    assert_eq($res, 42);
});

test('chain_eval unknown op throws', function() {
    $db = test_db();
    assert_throws(fn() => chain_eval($db, [['op' => 'nonexistent', 'args' => []]]), InvalidArgumentException::class);
});

// ─── partial_eval ────────────────────────────────────────────────────────────

test('partial_eval stops at step N', function() {
    $db = test_db();
    fixture_entity($db, ['type' => 'note', 'label' => 'Partial test']);
    $steps = [
        ['op' => 'select',  'args' => ['type' => 'note']],
        ['op' => 'project', 'args' => ['fields' => ['id', 'label', 'type']]],
    ];
    // After step 1 (just select), result should have 'type' key
    $after1 = partial_eval($db, $steps, null, 1);
    assert_true(count($after1) >= 1);
    assert_keys($after1[0], ['id', 'type', 'label']);
    // After step 2 (select + project), 'type' still present (it was included in fields)
    $after2 = partial_eval($db, $steps, null, 2);
    assert_keys($after2[0], ['id', 'label', 'type']);
});

run_tests();
