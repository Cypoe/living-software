<?php
/**
 * index.php — Living Software HTTP surface
 *
 * Routes:
 *   GET  /health                        — heartbeat + adopted_commit
 *   POST /runtime/notify                — deploy ping (bearer token auth)
 *   GET  /records/{type}                — list entities of type
 *   GET  /records/{type}/{id}           — get single entity
 *   POST /records/{type}                — create entity
 *   POST /capability/{id}/invoke        — invoke capability
 *   GET  /schema                        — list all schema rows
 */

define('LS_START', microtime(true));
$root = dirname(__DIR__);

// Load .env
$envPath = "{$root}/.env";
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        if (trim($k) && !getenv(trim($k))) putenv(trim($k) . '=' . trim($v));
    }
}

// Boot DB
$dbPath = "{$root}/kernel/kernel.db";
if (!is_dir(dirname($dbPath))) mkdir(dirname($dbPath), 0755, true);
$db = new SQLite3($dbPath);
$db->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;');
$db->exec(file_get_contents("{$root}/kernel/schema.sql"));

// Helpers
function json_out(int $code, mixed $data): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
function state_get(SQLite3 $db, string $key): ?string {
    $s = $db->prepare('select value from runtime_state where key=:k');
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : null;
    return $row ? $row['value'] : null;
}
function state_set(SQLite3 $db, string $key, string $value): void {
    $s = $db->prepare(
        'insert into runtime_state(key,value,updated_at) values(:k,:v,:t)
         on conflict(key) do update set value=excluded.value,updated_at=excluded.updated_at'
    );
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $s->bindValue(':v', $value, SQLITE3_TEXT);
    $s->bindValue(':t', gmdate('c'), SQLITE3_TEXT);
    $s->execute();
}
function bearer_ok(): bool {
    $token   = getenv('LS_UPDATE_TOKEN') ?: '';
    $headers = getallheaders();
    $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    return $token && $auth === "Bearer {$token}";
}

// Route
$method = $_SERVER['REQUEST_METHOD'];
$uri    = strtok($_SERVER['REQUEST_URI'], '?');
$uri    = rtrim($uri, '/');
$parts  = array_values(array_filter(explode('/', $uri)));

// --- GET /health ---
if ($method === 'GET' && ($uri === '/health' || $uri === '')) {
    json_out(200, [
        'ok'             => true,
        'adopted_commit' => state_get($db, 'adopted_commit'),
        'last_heartbeat' => state_get($db, 'last_heartbeat'),
        'last_update'    => state_get($db, 'last_update'),
        'php'            => PHP_VERSION,
        'sqlite'         => SQLite3::version()['versionString'],
        'uptime_ms'      => round((microtime(true) - LS_START) * 1000, 2),
    ]);
}

// --- POST /runtime/notify ---
if ($method === 'POST' && $uri === '/runtime/notify') {
    if (!bearer_ok()) json_out(401, ['error' => 'unauthorized']);
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $sha  = $body['sha'] ?? null;
    if (!$sha) json_out(400, ['error' => 'sha required']);
    state_set($db, 'adopted_commit', $sha);
    state_set($db, 'last_update', gmdate('c'));
    state_set($db, 'last_deploy_method', $body['method'] ?? 'unknown');
    json_out(200, ['ok' => true, 'adopted_commit' => $sha]);
}

// --- GET /schema ---
if ($method === 'GET' && $uri === '/schema') {
    $rows = [];
    $res  = $db->query('select id, name, definition_json from schemas order by name');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['definition'] = json_decode($row['definition_json'] ?? '{}', true);
        unset($row['definition_json']);
        $rows[] = $row;
    }
    json_out(200, ['schemas' => $rows, 'count' => count($rows)]);
}

// --- GET /records/{type} ---
if ($method === 'GET' && count($parts) === 2 && $parts[0] === 'records') {
    $type = SQLite3::escapeString($parts[1]);
    $rows = [];
    $res  = $db->query("select * from entities where type='{$type}' order by created_at desc limit 100");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['metadata'] = json_decode($row['metadata_json'] ?? '{}', true);
        unset($row['metadata_json']);
        $rows[] = $row;
    }
    json_out(200, ['type' => $parts[1], 'records' => $rows, 'count' => count($rows)]);
}

// --- GET /records/{type}/{id} ---
if ($method === 'GET' && count($parts) === 3 && $parts[0] === 'records') {
    $id  = SQLite3::escapeString($parts[2]);
    $row = $db->querySingle("select * from entities where id='{$id}'", true);
    if (!$row) json_out(404, ['error' => 'not found']);
    $row['metadata'] = json_decode($row['metadata_json'] ?? '{}', true);
    unset($row['metadata_json']);
    json_out(200, $row);
}

// --- POST /records/{type} ---
if ($method === 'POST' && count($parts) === 2 && $parts[0] === 'records') {
    $type = $parts[1];
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id   = $body['id'] ?? ('rec_' . bin2hex(random_bytes(8)));
    $meta = json_encode($body['metadata'] ?? new stdClass());
    $owner = SQLite3::escapeString($body['owner'] ?? '');
    $eid   = SQLite3::escapeString($id);
    $etype = SQLite3::escapeString($type);
    $db->exec("insert into entities (id, type, owner, metadata_json)
               values ('{$eid}', '{$etype}', '{$owner}', '" . SQLite3::escapeString($meta) . "')");
    if ($db->lastErrorCode() !== 0) json_out(409, ['error' => $db->lastErrorMsg()]);
    json_out(201, ['ok' => true, 'id' => $id, 'type' => $type]);
}

// --- POST /capability/{id}/invoke ---
if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'capability' && $parts[2] === 'invoke') {
    $capId   = $parts[1];
    $capFile = dirname(__DIR__) . "/capabilities/{$capId}.php";
    if (!file_exists($capFile)) json_out(404, ['error' => "capability '{$capId}' not found"]);
    require_once $capFile;
    $fn   = str_replace('-', '_', $capId) . '_invoke';
    if (!function_exists($fn)) json_out(500, ['error' => "capability loaded but {$fn}() not defined"]);
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $result = $fn($input, $db);
    json_out(200, $result);
}

// --- 404 ---
json_out(404, ['error' => 'route not found', 'uri' => $uri, 'method' => $method]);
