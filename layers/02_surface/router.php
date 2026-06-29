<?php
/**
 * router.php — Living Software HTTP gateway (Layer 02)
 *
 * Routes:
 *   GET  /health                          — liveness probe
 *   POST /setup                           — one-time owner claim
 *   GET  /r/{id}                          — serve entity as rendered carrier
 *   GET  /api/records?type=&limit=&offset= — proto_select
 *   GET  /api/records/{id}                — single record + carriers
 *   POST /api/records                     — create entity + carrier
 *   PUT  /api/records/{id}                — update entity metadata
 *   DELETE /api/records/{id}              — soft-delete (status=deleted)
 *   POST /api/chain                       — chain_eval over posted steps JSON
 *   POST /api/partial                     — partial_eval (steps + until)
 *   GET  /api/views/{id}                  — serve materialized view
 *   POST /api/views/{id}/recompute        — force recompute of materialized view
 *   GET  /api/search?q=&type=             — FTS5 search
 */

declare(strict_types=1);

define('LS_ROOT', dirname(__DIR__, 2));
require LS_ROOT . '/kernel/init.php';
require LS_ROOT . '/layers/01_protocol/protocol.php';

$db = ls_kernel_boot();

// ─── Auth middleware ─────────────────────────────────────────────────────────

function ls_auth(SQLite3 $db): bool {
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    if (empty($token)) return false;
    $owner = $db->querySingle(
        "SELECT value FROM runtime_state WHERE key = 'owner_token'"
    );
    return hash_equals((string)$owner, $token);
}

function ls_require_auth(SQLite3 $db): void {
    if (!ls_auth($db)) ls_error(401, 'Unauthorized');
}

// ─── Response helpers ────────────────────────────────────────────────────────

function ls_json(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ls_error(int $status, string $msg): never {
    ls_json(['error' => $msg], $status);
}

function ls_html(string $html, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

// ─── Request parsing ─────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = rtrim($uri, '/');
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ─── Route matching ──────────────────────────────────────────────────────────

// GET /health
if ($method === 'GET' && $uri === '/health') {
    $heartbeat = $db->querySingle("SELECT value FROM runtime_state WHERE key='last_heartbeat'");
    $boot      = $db->querySingle("SELECT value FROM runtime_state WHERE key='boot_count'");
    ls_json(['status' => 'ok', 'boot_count' => (int)$boot, 'last_heartbeat' => $heartbeat]);
}

// POST /setup — one-time owner claim
if ($method === 'POST' && $uri === '/setup') {
    $complete = $db->querySingle("SELECT value FROM runtime_state WHERE key='setup_complete'");
    if ($complete === '1') ls_error(403, 'Setup already complete');
    $secret = getenv('LS_AUTH_SECRET') ?: '';
    if (empty($secret)) ls_error(500, 'LS_AUTH_SECRET not set');
    if (!hash_equals($secret, (string)($body['secret'] ?? ''))) ls_error(403, 'Wrong secret');
    $token = bin2hex(random_bytes(32));
    $stmt  = $db->prepare("UPDATE runtime_state SET value=:v, updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE key=:k");
    foreach ([['owner_token',$token],['setup_complete','1']] as [$k,$v]) {
        $stmt->bindValue(':k',$k); $stmt->bindValue(':v',$v); $stmt->execute(); $stmt->reset();
    }
    ls_json(['token' => $token, 'note' => 'Copy this token — it will not be shown again']);
}

// GET /r/{id} — serve entity carrier
if ($method === 'GET' && preg_match('#^/r/([\w-]+)$#', $uri, $m)) {
    $id  = $m[1];
    $ent = $db->querySingle("SELECT * FROM entities WHERE id='" . SQLite3::escapeString($id) . "' AND status='active'", true);
    if (!$ent) ls_error(404, 'Not found');
    if ($ent['visibility'] !== 'public') ls_require_auth($db);
    $carrier = $db->querySingle(
        "SELECT * FROM carriers WHERE entity_id='" . SQLite3::escapeString($id) . "' ORDER BY created_at DESC LIMIT 1",
        true
    );
    if (!$carrier) ls_error(404, 'No carrier');
    $body_type = $carrier['carrier_type'] ?? 'text';
    $content   = $carrier['content'] ?? '';
    if ($body_type === 'html') {
        // Render template against entity metadata
        $record = array_merge($ent, ['metadata' => json_decode($ent['metadata_json'] ?? '{}', true)]);
        ls_html(proto_render($content, $record));
    }
    $mime = $carrier['mime_type'] ?? 'text/plain';
    header('Content-Type: ' . $mime);
    echo $content;
    exit;
}

// GET /api/search
if ($method === 'GET' && $uri === '/api/search') {
    ls_require_auth($db);
    $q    = $_GET['q']    ?? '';
    $type = $_GET['type'] ?? null;
    if (empty($q)) ls_error(400, 'q required');
    $results = proto_search($db, $q, ['type' => $type, 'limit' => (int)($_GET['limit'] ?? 20)]);
    ls_json(['results' => $results, 'count' => count($results)]);
}

// GET /api/records
if ($method === 'GET' && $uri === '/api/records') {
    ls_require_auth($db);
    $type = $_GET['type'] ?? '';
    if (empty($type)) ls_error(400, 'type required');
    $records = proto_select($db, $type, [
        'limit'  => (int)($_GET['limit']  ?? 100),
        'offset' => (int)($_GET['offset'] ?? 0),
    ]);
    ls_json(['records' => $records, 'count' => count($records)]);
}

// GET /api/records/{id}
if ($method === 'GET' && preg_match('#^/api/records/([\w-]+)$#', $uri, $m)) {
    ls_require_auth($db);
    $id  = $m[1];
    $ent = $db->querySingle("SELECT * FROM entities WHERE id='" . SQLite3::escapeString($id) . "'", true);
    if (!$ent) ls_error(404, 'Not found');
    $ent['metadata'] = json_decode($ent['metadata_json'] ?? '{}', true);
    $carriers = _ls_fetch_all($db->query("SELECT * FROM carriers WHERE entity_id='" . SQLite3::escapeString($id) . "'"));
    ls_json(['entity' => $ent, 'carriers' => $carriers]);
}

// POST /api/records — create
if ($method === 'POST' && $uri === '/api/records') {
    ls_require_auth($db);
    $id    = $body['id']    ?? bin2hex(random_bytes(8));
    $type  = $body['type']  ?? ls_error(400, 'type required');
    $label = $body['label'] ?? '';
    $meta  = json_encode($body['metadata'] ?? []);
    $vis   = $body['visibility'] ?? 'private';
    $owner = $body['owner'] ?? $db->querySingle("SELECT value FROM runtime_state WHERE key='owner_token'");
    $stmt  = $db->prepare(
        "INSERT INTO entities(id,type,label,owner,visibility,metadata_json) VALUES(:id,:type,:label,:owner,:vis,:meta)"
    );
    $stmt->bindValue(':id',$id); $stmt->bindValue(':type',$type);
    $stmt->bindValue(':label',$label); $stmt->bindValue(':owner',$owner);
    $stmt->bindValue(':vis',$vis); $stmt->bindValue(':meta',$meta);
    $stmt->execute();
    // Optional inline carrier
    if (isset($body['carrier'])) {
        $cid   = bin2hex(random_bytes(8));
        $ctype = $body['carrier']['type'] ?? 'text';
        $cont  = $body['carrier']['content'] ?? '';
        $cstmt = $db->prepare(
            "INSERT INTO carriers(id,entity_id,carrier_type,content,mime_type) VALUES(:id,:eid,:ctype,:cont,:mime)"
        );
        $cstmt->bindValue(':id',$cid); $cstmt->bindValue(':eid',$id);
        $cstmt->bindValue(':ctype',$ctype); $cstmt->bindValue(':cont',$cont);
        $cstmt->bindValue(':mime',$body['carrier']['mime_type'] ?? 'text/plain');
        $cstmt->execute();
    }
    ls_json(['id' => $id, 'created' => true], 201);
}

// PUT /api/records/{id}
if ($method === 'PUT' && preg_match('#^/api/records/([\w-]+)$#', $uri, $m)) {
    ls_require_auth($db);
    $id   = $m[1];
    $meta = json_encode($body['metadata'] ?? []);
    $stmt = $db->prepare(
        "UPDATE entities SET metadata_json=:meta, label=:label, updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id=:id"
    );
    $stmt->bindValue(':meta',$meta);
    $stmt->bindValue(':label',$body['label'] ?? '');
    $stmt->bindValue(':id',$id);
    $stmt->execute();
    ls_json(['id' => $id, 'updated' => true]);
}

// DELETE /api/records/{id}
if ($method === 'DELETE' && preg_match('#^/api/records/([\w-]+)$#', $uri, $m)) {
    ls_require_auth($db);
    $id   = $m[1];
    $stmt = $db->prepare("UPDATE entities SET status='deleted', updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id=:id");
    $stmt->bindValue(':id',$id);
    $stmt->execute();
    ls_json(['id' => $id, 'deleted' => true]);
}

// POST /api/chain
if ($method === 'POST' && $uri === '/api/chain') {
    ls_require_auth($db);
    $steps = $body['steps'] ?? ls_error(400, 'steps required');
    try {
        $result = chain_eval($db, $steps, $body['ctx'] ?? null);
        ls_json(['result' => $result]);
    } catch (Throwable $e) {
        ls_error(422, $e->getMessage());
    }
}

// POST /api/partial
if ($method === 'POST' && $uri === '/api/partial') {
    ls_require_auth($db);
    $steps = $body['steps'] ?? ls_error(400, 'steps required');
    $until = (int)($body['until'] ?? 1);
    try {
        $result = partial_eval($db, $steps, $body['ctx'] ?? null, $until);
        ls_json(['result' => $result, 'steps_executed' => $until]);
    } catch (Throwable $e) {
        ls_error(422, $e->getMessage());
    }
}

// GET /api/views/{id}
if ($method === 'GET' && preg_match('#^/api/views/([\w-]+)$#', $uri, $m)) {
    ls_require_auth($db);
    $id  = $m[1];
    $view = $db->querySingle("SELECT * FROM materialized_views WHERE id='" . SQLite3::escapeString($id) . "'", true);
    if (!$view) ls_error(404, 'View not found');
    if ($view['invalidated']) {
        // Recompute inline
        $view = _ls_recompute_view($db, $view);
    }
    $mime = $view['body_type'] === 'html' ? 'text/html' : 'application/json';
    header('Content-Type: ' . $mime . '; charset=utf-8');
    echo $view['body'];
    exit;
}

// POST /api/views/{id}/recompute
if ($method === 'POST' && preg_match('#^/api/views/([\w-]+)/recompute$#', $uri, $m)) {
    ls_require_auth($db);
    $id   = $m[1];
    $view = $db->querySingle("SELECT * FROM materialized_views WHERE id='" . SQLite3::escapeString($id) . "'", true);
    if (!$view) ls_error(404, 'View not found');
    $view = _ls_recompute_view($db, $view);
    ls_json(['id' => $id, 'recomputed' => true, 'last_computed' => $view['last_computed']]);
}

function _ls_recompute_view(SQLite3 $db, array $view): array {
    $chain = $db->querySingle(
        "SELECT steps_json FROM transform_chains WHERE id='" . SQLite3::escapeString($view['source_chain_id'] ?? '') . "'"
    );
    $steps  = json_decode($chain ?: '[]', true);
    $result = chain_eval($db, $steps);
    $body   = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE);
    $now    = (new DateTime())->format(DateTime::ATOM);
    $stmt   = $db->prepare(
        "UPDATE materialized_views SET body=:body, invalidated=0, last_computed=:now, updated_at=:now WHERE id=:id"
    );
    $stmt->bindValue(':body',$body); $stmt->bindValue(':now',$now); $stmt->bindValue(':id',$view['id']);
    $stmt->execute();
    $view['body'] = $body; $view['invalidated'] = 0; $view['last_computed'] = $now;
    return $view;
}

ls_error(404, 'No route matched');
