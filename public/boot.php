<?php
$dbPath = __DIR__ . '/../kernel/kernel.db';
$schemaPath = __DIR__ . '/../kernel/schema.sql';

if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0777, true);
}

$db = new SQLite3($dbPath);
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec(file_get_contents($schemaPath));

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/' || $path === '/health') {
    echo json_encode([
        'ok' => true,
        'service' => 'living-software',
        'db' => file_exists($dbPath),
        'time' => gmdate('c')
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($path === '/record' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $stmt = $db->prepare('insert into entities (id, type, owner, body_ref, metadata_json) values (:id,:type,:owner,:body_ref,:metadata_json)');
    $stmt->bindValue(':id', $input['id'] ?? uniqid('ent_', true), SQLITE3_TEXT);
    $stmt->bindValue(':type', $input['type'] ?? 'unknown', SQLITE3_TEXT);
    $stmt->bindValue(':owner', $input['owner'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':body_ref', $input['body_ref'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':metadata_json', json_encode($input['metadata'] ?? new stdClass()), SQLITE3_TEXT);
    $ok = $stmt->execute();
    echo json_encode(['ok' => (bool)$ok], JSON_PRETTY_PRINT);
    exit;
}

if (preg_match('#^/record/(.+)$#', $path, $m) && $method === 'GET') {
    $stmt = $db->prepare('select * from entities where id = :id');
    $stmt->bindValue(':id', $m[1], SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_PRETTY_PRINT);
        exit;
    }
    echo json_encode(['ok' => true, 'record' => $row], JSON_PRETTY_PRINT);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'unknown_route', 'path' => $path], JSON_PRETTY_PRINT);
