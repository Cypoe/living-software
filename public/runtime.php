<?php
/**
 * runtime.php — Runtime notification and status endpoint
 * Handles POST /runtime/notify from GitHub Actions
 * Handles GET /runtime/status
 */

$dbPath    = __DIR__ . '/../kernel/kernel.db';
$schemaPath = __DIR__ . '/../kernel/schema.sql';
$db = new SQLite3($dbPath);
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec(file_get_contents($schemaPath));

header('Content-Type: application/json');

function state_get(SQLite3 $db, string $key): ?string {
    $s = $db->prepare('select value from runtime_state where key = :k');
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : null;
    return $row ? $row['value'] : null;
}

function state_set(SQLite3 $db, string $key, string $value): void {
    $s = $db->prepare(
        'insert into runtime_state (key, value, updated_at)
         values (:k, :v, :t)
         on conflict(key) do update set value=excluded.value, updated_at=excluded.updated_at'
    );
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $s->bindValue(':v', $value, SQLITE3_TEXT);
    $s->bindValue(':t', gmdate('c'), SQLITE3_TEXT);
    $s->execute();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/runtime/status' && $method === 'GET') {
    echo json_encode([
        'ok'             => true,
        'adopted_commit' => state_get($db, 'adopted_commit'),
        'last_heartbeat' => state_get($db, 'last_heartbeat'),
        'last_update'    => state_get($db, 'last_update'),
        'last_error'     => state_get($db, 'last_error'),
        'time'           => gmdate('c'),
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($path === '/runtime/notify' && $method === 'POST') {
    $token = getenv('LS_UPDATE_TOKEN') ?: null;
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($token && $auth !== "Bearer {$token}") {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $sha   = $input['sha'] ?? 'unknown';
    state_set($db, 'pending_sha', $sha);
    state_set($db, 'notified_at', gmdate('c'));
    echo json_encode(['ok' => true, 'pending_sha' => $sha, 'note' => 'cron will evaluate on next tick']);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'unknown_route']);
