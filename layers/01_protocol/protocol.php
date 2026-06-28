<?php
/**
 * protocol.php — Living Software record algebra (read + eval)
 *
 * Layer 01: pure read operations + transform chain evaluator.
 * All functions are stateless over the input. None write to the DB.
 *
 * Operations:
 *   proto_select       — fetch entity set by type
 *   proto_select_one   — fetch single entity by id
 *   proto_filter       — filter record set by metadata predicate
 *   proto_filter_sql   — filter at SQL level (indexed, fast)
 *   proto_join         — join two record sets on a key
 *   proto_transform    — map a callable over a record set
 *   proto_project      — keep only named fields
 *   proto_render       — {{field}} template substitution
 *   proto_search       — FTS5 full-text search
 *   proto_chain_eval   — evaluate a transform_chain record
 *   proto_partial_eval — evaluate a chain up to a named step (partial)
 */

declare(strict_types=1);

// ─── Primitives ──────────────────────────────────────────────────────────────

/**
 * Select all entities of a given type.
 * Returns decoded metadata under 'metadata' key.
 */
function proto_select(SQLite3 $db, string $type, int $limit = 100, int $offset = 0): array {
    $s = $db->prepare(
        'SELECT * FROM entities WHERE type=:t AND status != :del
         ORDER BY created_at DESC LIMIT :lim OFFSET :off'
    );
    $s->bindValue(':t',   $type,    SQLITE3_TEXT);
    $s->bindValue(':del', 'deleted',SQLITE3_TEXT);
    $s->bindValue(':lim', $limit,   SQLITE3_INTEGER);
    $s->bindValue(':off', $offset,  SQLITE3_INTEGER);
    return _fetch_all($s->execute());
}

/**
 * Fetch a single entity by id. Returns null if not found.
 */
function proto_select_one(SQLite3 $db, string $id): ?array {
    $s = $db->prepare('SELECT * FROM entities WHERE id=:id');
    $s->bindValue(':id', $id, SQLITE3_TEXT);
    $res = $s->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row) return null;
    return _decode_meta($row);
}

/**
 * Filter a record set by a metadata key/value predicate.
 * Supports operators: = (default), !=, >, <, >=, <=, contains, starts_with
 */
function proto_filter(array $records, string $key, mixed $value, string $op = '='): array {
    return array_values(array_filter($records, function ($r) use ($key, $value, $op) {
        $v = $r['metadata'][$key] ?? $r[$key] ?? null;
        return match ($op) {
            '='          => $v === $value,
            '!='         => $v !== $value,
            '>'          => $v >   $value,
            '<'          => $v <   $value,
            '>='         => $v >=  $value,
            '<='         => $v <=  $value,
            'contains'   => is_string($v) && str_contains($v, (string)$value),
            'starts_with'=> is_string($v) && str_starts_with($v, (string)$value),
            default      => $v === $value,
        };
    }));
}

/**
 * Filter at SQL level — uses prepared statement + index.
 * $col must be a real entity column (type, owner, status, visibility, label).
 */
function proto_filter_sql(SQLite3 $db, string $type, string $col, mixed $value, int $limit = 100): array {
    $allowed = ['type','owner','status','visibility','label'];
    if (!in_array($col, $allowed, true)) {
        throw new InvalidArgumentException("proto_filter_sql: column '{$col}' not allowed");
    }
    $s = $db->prepare(
        "SELECT * FROM entities WHERE type=:t AND {$col}=:v AND status != 'deleted'
         ORDER BY created_at DESC LIMIT :lim"
    );
    $s->bindValue(':t',   $type,  SQLITE3_TEXT);
    $s->bindValue(':v',   $value, SQLITE3_TEXT);
    $s->bindValue(':lim', $limit, SQLITE3_INTEGER);
    return _fetch_all($s->execute());
}

/**
 * Join two record sets on a key.
 * keyA / keyB can reference top-level fields OR metadata fields.
 */
function proto_join(array $a, array $b, string $keyA, string $keyB): array {
    $index = [];
    foreach ($b as $row) {
        $k = $row[$keyB] ?? $row['metadata'][$keyB] ?? null;
        if ($k !== null) $index[(string)$k] = $row;
    }
    $result = [];
    foreach ($a as $row) {
        $k = $row[$keyA] ?? $row['metadata'][$keyA] ?? null;
        if ($k !== null && isset($index[(string)$k])) {
            $result[] = array_merge($row, ['_joined' => $index[(string)$k]]);
        }
    }
    return $result;
}

/**
 * Apply a transform callable to each record in a set.
 */
function proto_transform(array $records, callable $fn): array {
    return array_map($fn, $records);
}

/**
 * Project: keep only named fields from each record.
 * Use dot notation for metadata fields: 'metadata.title'
 */
function proto_project(array $records, array $fields): array {
    return array_map(function ($r) use ($fields) {
        $out = [];
        foreach ($fields as $f) {
            if (str_starts_with($f, 'metadata.')) {
                $mk = substr($f, 9);
                $out[$f] = $r['metadata'][$mk] ?? null;
            } else {
                $out[$f] = $r[$f] ?? null;
            }
        }
        return $out;
    }, $records);
}

/**
 * Render a {{field}} template against a single record.
 * Merges top-level fields and metadata. HTML-escapes by default.
 */
function proto_render(string $template, array $record, bool $escape = true): string {
    $flat = array_merge($record, $record['metadata'] ?? []);
    return preg_replace_callback('/\{\{([\w.]+)\}\}/', function ($m) use ($flat, $escape) {
        $val = $flat[$m[1]] ?? '';
        return $escape ? htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : (string)$val;
    }, $template);
}

/**
 * Full-text search via FTS5. Returns matching entities.
 */
function proto_search(SQLite3 $db, string $query, int $limit = 20): array {
    $s = $db->prepare(
        'SELECT e.* FROM entities e
         JOIN entities_fts f ON e.rowid = f.rowid
         WHERE entities_fts MATCH :q
         ORDER BY rank LIMIT :lim'
    );
    $s->bindValue(':q',   $query, SQLITE3_TEXT);
    $s->bindValue(':lim', $limit, SQLITE3_INTEGER);
    return _fetch_all($s->execute());
}

// ─── Transform chain evaluator ───────────────────────────────────────────────

/**
 * Evaluate a stored transform_chain record by id.
 * Returns the final record set (or rendered string for 'render' terminus).
 *
 * Step spec (steps_json array element):
 *   {"op": "select",      "args": {"type": "task"}}
 *   {"op": "filter",      "args": {"key": "done", "value": false}}
 *   {"op": "filter_sql",  "args": {"type": "task", "col": "owner", "value": "fabian"}}
 *   {"op": "join",        "args": {"keyA": "id", "keyB": "task_ref"}}
 *   {"op": "project",     "args": {"fields": ["id","metadata.title"]}}
 *   {"op": "transform",   "args": {"capability": "my_cap_id"}}
 *   {"op": "render",      "args": {"template": "<li>{{title}}</li>"}}
 *   {"op": "search",      "args": {"query": "foo bar"}}
 */
function proto_chain_eval(SQLite3 $db, string $chainId, array $ctx = []): mixed {
    $row = $db->querySingle("SELECT steps_json FROM transform_chains WHERE id='" . SQLite3::escapeString($chainId) . "'", true);
    if (!$row) throw new RuntimeException("transform_chain '{$chainId}' not found");
    $steps = json_decode($row['steps_json'], true) ?: [];
    return _run_steps($db, $steps, $ctx);
}

/**
 * Partial evaluation: run chain up to (and including) step at $stopIndex.
 * Useful for debugging a chain mid-flight or for LLM step injection.
 */
function proto_partial_eval(SQLite3 $db, string $chainId, int $stopIndex, array $ctx = []): mixed {
    $row = $db->querySingle("SELECT steps_json FROM transform_chains WHERE id='" . SQLite3::escapeString($chainId) . "'", true);
    if (!$row) throw new RuntimeException("transform_chain '{$chainId}' not found");
    $steps = array_slice(json_decode($row['steps_json'], true) ?: [], 0, $stopIndex + 1);
    return _run_steps($db, $steps, $ctx);
}

/**
 * Run an inline steps array (no DB lookup). Used by chain_eval and partial_eval.
 */
function proto_steps_eval(SQLite3 $db, array $steps, array $ctx = []): mixed {
    return _run_steps($db, $steps, $ctx);
}

// ─── Materialized view helpers ────────────────────────────────────────────────

/**
 * Get a materialized view, recomputing it if invalidated.
 */
function proto_view_get(SQLite3 $db, string $viewId): ?array {
    $v = $db->querySingle("SELECT * FROM materialized_views WHERE id='" . SQLite3::escapeString($viewId) . "'", true);
    if (!$v) return null;
    if ((int)$v['invalidated'] === 1 && $v['source_chain_id']) {
        $body = proto_chain_eval($db, $v['source_chain_id']);
        $encoded = is_string($body) ? $body : json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $s = $db->prepare(
            'UPDATE materialized_views SET body=:b, invalidated=0, last_computed=:t, updated_at=:t WHERE id=:id'
        );
        $s->bindValue(':b',   $encoded,              SQLITE3_TEXT);
        $s->bindValue(':t',   gmdate('Y-m-d\TH:i:s\Z'), SQLITE3_TEXT);
        $s->bindValue(':id',  $viewId,               SQLITE3_TEXT);
        $s->execute();
        $v['body']        = $encoded;
        $v['invalidated'] = 0;
    }
    return $v;
}

/**
 * Mark all views that depend on entities of $type as invalidated.
 */
function proto_view_invalidate(SQLite3 $db, string $entityId): void {
    // Simple strategy: invalidate all views that reference this entity id
    $db->exec(
        "UPDATE materialized_views SET invalidated=1, updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now')
         WHERE entity_refs LIKE '%" . SQLite3::escapeString($entityId) . "%'"
    );
}

// ─── Internal helpers ─────────────────────────────────────────────────────────

function _fetch_all(SQLite3Result|false $res): array {
    if (!$res) return [];
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = _decode_meta($row);
    }
    return $rows;
}

function _decode_meta(array $row): array {
    $row['metadata'] = json_decode($row['metadata_json'] ?? '{}', true) ?: [];
    unset($row['metadata_json']);
    return $row;
}

function _run_steps(SQLite3 $db, array $steps, array $ctx): mixed {
    $set = $ctx['seed'] ?? [];
    foreach ($steps as $i => $step) {
        $op   = $step['op']   ?? '';
        $args = $step['args'] ?? [];
        $set  = match ($op) {
            'select'      => proto_select($db, $args['type'] ?? '', $args['limit'] ?? 100),
            'select_one'  => [proto_select_one($db, $args['id'] ?? '')],
            'filter'      => proto_filter($set, $args['key'] ?? '', $args['value'] ?? null, $args['op'] ?? '='),
            'filter_sql'  => proto_filter_sql($db, $args['type'] ?? '', $args['col'] ?? '', $args['value'] ?? '', $args['limit'] ?? 100),
            'join'        => proto_join($set, proto_select($db, $args['join_type'] ?? ''), $args['keyA'] ?? '', $args['keyB'] ?? ''),
            'project'     => proto_project($set, $args['fields'] ?? []),
            'transform'   => _step_transform($db, $set, $args),
            'render'      => implode('', array_map(fn($r) => proto_render($args['template'] ?? '', $r, $args['escape'] ?? true), $set)),
            'search'      => proto_search($db, $args['query'] ?? '', $args['limit'] ?? 20),
            'limit'       => array_slice($set, 0, (int)($args['n'] ?? 10)),
            'sort'        => _step_sort($set, $args),
            default       => throw new RuntimeException("Unknown chain op '{$op}' at step {$i}"),
        };
    }
    return $set;
}

function _step_transform(SQLite3 $db, array $set, array $args): array {
    $capId   = $args['capability'] ?? null;
    if (!$capId) return $set;
    $capFile = dirname(__DIR__, 2) . "/capabilities/{$capId}.php";
    if (!file_exists($capFile)) throw new RuntimeException("capability '{$capId}' not found for transform step");
    require_once $capFile;
    $fn = str_replace('-', '_', $capId) . '_invoke';
    if (!function_exists($fn)) throw new RuntimeException("{$fn}() not defined");
    return array_map(fn($r) => $fn($r, $db), $set);
}

function _step_sort(array $set, array $args): array {
    $key = $args['key'] ?? 'created_at';
    $dir = strtolower($args['dir'] ?? 'desc') === 'asc' ? 1 : -1;
    usort($set, function ($a, $b) use ($key, $dir) {
        $av = $a[$key] ?? $a['metadata'][$key] ?? '';
        $bv = $b[$key] ?? $b['metadata'][$key] ?? '';
        return $dir * strcmp((string)$av, (string)$bv);
    });
    return $set;
}
