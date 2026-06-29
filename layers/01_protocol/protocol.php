<?php
/**
 * protocol.php — Living Software record algebra (Layer 01)
 *
 * Six pure operations + chain_eval + partial_eval.
 * All read-only. None write to the DB.
 * Include this file; do not execute directly.
 *
 * Operations:
 *   proto_select($db, $type, $opts)   → record[]
 *   proto_filter($records, $pred)     → record[]
 *   proto_join($a, $b, $kA, $kB)     → record[]
 *   proto_transform($records, $fn)    → record[]
 *   proto_project($records, $fields)  → record[]
 *   proto_render($tpl, $record)       → string
 *   proto_search($db, $query, $opts)  → record[]   (FTS5)
 *   chain_eval($db, $steps, $ctx)     → mixed      (compose any of the above)
 *   partial_eval($db, $steps, $ctx, $until) → mixed (stop at step N)
 */

declare(strict_types=1);

// ─── Helpers ────────────────────────────────────────────────────────────────

function _ls_decode_meta(array $row): array {
    $row['metadata'] = json_decode($row['metadata_json'] ?? '{}', true) ?? [];
    return $row;
}

function _ls_bind(SQLite3Stmt $stmt, array $params): void {
    foreach ($params as $k => $v) {
        $type = is_int($v) ? SQLITE3_INTEGER : (is_float($v) ? SQLITE3_FLOAT : SQLITE3_TEXT);
        $stmt->bindValue($k, $v, $type);
    }
}

function _ls_fetch_all(SQLite3Result $res): array {
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = _ls_decode_meta($row);
    }
    return $rows;
}

// ─── 1. SELECT ──────────────────────────────────────────────────────────────

/**
 * Select entities by type with optional filters.
 *
 * $opts keys:
 *   limit        int    default 100
 *   offset       int    default 0
 *   status       string default 'active'
 *   visibility   string|null
 *   owner        string|null
 *   schema_ref   string|null
 *   order_by     string  'created_at'|'updated_at'|'label'  default 'created_at'
 *   order_dir    string  'ASC'|'DESC'  default 'DESC'
 */
function proto_select(SQLite3 $db, string $type, array $opts = []): array {
    $limit    = (int)($opts['limit']    ?? 100);
    $offset   = (int)($opts['offset']   ?? 0);
    $status   = $opts['status']         ?? 'active';
    $order_by = in_array($opts['order_by'] ?? '', ['created_at','updated_at','label'], true)
                ? $opts['order_by'] : 'created_at';
    $order_dir = strtoupper($opts['order_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $where = ['type = :type', 'status = :status'];
    $params = [':type' => $type, ':status' => $status];

    if (isset($opts['owner'])) {
        $where[] = 'owner = :owner';
        $params[':owner'] = $opts['owner'];
    }
    if (isset($opts['visibility'])) {
        $where[] = 'visibility = :visibility';
        $params[':visibility'] = $opts['visibility'];
    }
    if (isset($opts['schema_ref'])) {
        $where[] = 'schema_ref = :schema_ref';
        $params[':schema_ref'] = $opts['schema_ref'];
    }

    $sql = sprintf(
        'SELECT * FROM entities WHERE %s ORDER BY %s %s LIMIT :limit OFFSET :offset',
        implode(' AND ', $where), $order_by, $order_dir
    );
    $stmt = $db->prepare($sql);
    _ls_bind($stmt, $params);
    $stmt->bindValue(':limit',  $limit,  SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    return _ls_fetch_all($stmt->execute());
}

// ─── 2. FILTER ──────────────────────────────────────────────────────────────

/**
 * Filter a record set with a predicate.
 * $pred can be:
 *   - a callable(record) => bool
 *   - an assoc array of field => value (equality, supports dot-notation into metadata)
 */
function proto_filter(array $records, mixed $pred): array {
    if (is_callable($pred)) {
        return array_values(array_filter($records, $pred));
    }
    // array shorthand: ['metadata.done' => true, 'type' => 'task']
    return array_values(array_filter($records, function($r) use ($pred) {
        foreach ($pred as $key => $val) {
            $actual = _ls_dot_get($r, $key);
            if ($actual !== $val) return false;
        }
        return true;
    }));
}

function _ls_dot_get(array $record, string $key): mixed {
    if (str_contains($key, '.')) {
        [$top, $rest] = explode('.', $key, 2);
        $sub = $record[$top] ?? [];
        return is_array($sub) ? _ls_dot_get($sub, $rest) : null;
    }
    return $record[$key] ?? ($record['metadata'][$key] ?? null);
}

// ─── 3. JOIN ────────────────────────────────────────────────────────────────

/**
 * Hash-join two record sets on matching field values.
 * Supports dot-notation for nested metadata fields.
 * Returns merged records; right-side is nested under '_joined'.
 *
 * $mode: 'inner' (default) | 'left'
 */
function proto_join(array $a, array $b, string $keyA, string $keyB, string $mode = 'inner'): array {
    $index = [];
    foreach ($b as $row) {
        $k = _ls_dot_get($row, $keyB);
        if ($k !== null) $index[(string)$k] = $row;
    }
    $result = [];
    foreach ($a as $row) {
        $k = (string)_ls_dot_get($row, $keyA);
        if (isset($index[$k])) {
            $result[] = array_merge($row, ['_joined' => $index[$k]]);
        } elseif ($mode === 'left') {
            $result[] = array_merge($row, ['_joined' => null]);
        }
    }
    return $result;
}

// ─── 4. TRANSFORM ───────────────────────────────────────────────────────────

/**
 * Map a callable or a named built-in transform over each record.
 *
 * Named built-ins:
 *   'flatten_metadata'  — merge metadata keys into top level
 *   'timestamps_iso'    — ensure created_at/updated_at are ISO strings
 *   'strip_internal'    — remove metadata_json, _joined from output
 */
function proto_transform(array $records, mixed $fn): array {
    if (is_string($fn)) $fn = _ls_builtin_transform($fn);
    return array_map($fn, $records);
}

function _ls_builtin_transform(string $name): callable {
    return match($name) {
        'flatten_metadata' => fn($r) => array_merge($r, $r['metadata'] ?? []),
        'timestamps_iso'   => function($r) {
            foreach (['created_at','updated_at'] as $f) {
                if (isset($r[$f]) && !str_contains($r[$f], 'T')) {
                    $r[$f] = (new DateTime($r[$f]))->format(DateTime::ATOM);
                }
            }
            return $r;
        },
        'strip_internal'   => function($r) {
            unset($r['metadata_json'], $r['_joined']);
            return $r;
        },
        default => throw new InvalidArgumentException("Unknown built-in transform: {$name}"),
    };
}

// ─── 5. PROJECT ─────────────────────────────────────────────────────────────

/**
 * Keep only specified fields from each record.
 * Supports dot-notation: 'metadata.title' extracts into top-level 'title'.
 */
function proto_project(array $records, array $fields): array {
    return array_map(function($r) use ($fields) {
        $out = [];
        foreach ($fields as $f) {
            if (str_contains($f, '.')) {
                $key = explode('.', $f)[1];
                $out[$key] = _ls_dot_get($r, $f);
            } else {
                if (array_key_exists($f, $r)) $out[$f] = $r[$f];
            }
        }
        return $out;
    }, $records);
}

// ─── 6. RENDER ──────────────────────────────────────────────────────────────

/**
 * Render a template string against a record.
 * Supports:
 *   {{field}}           — HTML-escaped substitution
 *   {{raw:field}}       — unescaped (use with care)
 *   {{if:field}}...{{/if:field}} — conditional block (truthy check)
 *   {{each:field}}...{{/each:field}} — loop over array field (sub-record as {{item.subfield}})
 */
function proto_render(string $tpl, array $record): string {
    $flat = array_merge($record, $record['metadata'] ?? []);

    // Conditional blocks
    $tpl = preg_replace_callback('/\{\{if:([\w.]+)\}\}(.*?)\{\{\/if:\1\}\}/s', function($m) use ($flat) {
        $val = _ls_dot_get($flat, $m[1]);
        return ($val ? $m[2] : '');
    }, $tpl);

    // Each blocks
    $tpl = preg_replace_callback('/\{\{each:([\w.]+)\}\}(.*?)\{\{\/each:\1\}\}/s', function($m) use ($flat) {
        $list = _ls_dot_get($flat, $m[1]);
        if (!is_array($list)) return '';
        $out = '';
        foreach ($list as $item) {
            $block = $m[2];
            $itemFlat = is_array($item) ? $item : ['item' => $item];
            $block = preg_replace_callback('/\{\{item\.([\w]+)\}\}/', fn($im) =>
                htmlspecialchars((string)($itemFlat[$im[1]] ?? ''), ENT_QUOTES), $block);
            $out .= $block;
        }
        return $out;
    }, $tpl);

    // Raw substitution
    $tpl = preg_replace_callback('/\{\{raw:([\w.]+)\}\}/', fn($m) =>
        (string)(_ls_dot_get($flat, $m[1]) ?? ''), $tpl);

    // Escaped substitution
    $tpl = preg_replace_callback('/\{\{([\w.]+)\}\}/', fn($m) =>
        htmlspecialchars((string)(_ls_dot_get($flat, $m[1]) ?? ''), ENT_QUOTES), $tpl);

    return $tpl;
}

// ─── 7. SEARCH (FTS5) ───────────────────────────────────────────────────────

/**
 * Full-text search over entities_fts.
 * Returns matching entity records, hydrated from entities table.
 */
function proto_search(SQLite3 $db, string $query, array $opts = []): array {
    $limit  = (int)($opts['limit']  ?? 20);
    $type   = $opts['type'] ?? null;
    $escaped = SQLite3::escapeString($query);

    $typeClause = $type ? "AND e.type = '" . SQLite3::escapeString($type) . "'" : '';
    $sql = "SELECT e.* FROM entities e
            JOIN entities_fts f ON f.id = e.id
            WHERE entities_fts MATCH '{$escaped}'
            {$typeClause}
            AND e.status = 'active'
            ORDER BY rank
            LIMIT {$limit}";
    $res = $db->query($sql);
    return _ls_fetch_all($res);
}

// ─── 8. CHAIN EVAL ──────────────────────────────────────────────────────────

/**
 * Evaluate a chain of protocol steps.
 *
 * $steps = [
 *   ['op' => 'select',    'args' => ['type' => 'note', 'opts' => [...]]],
 *   ['op' => 'filter',    'args' => ['pred' => ['metadata.done' => false]]],
 *   ['op' => 'transform', 'args' => ['fn' => 'flatten_metadata']],
 *   ['op' => 'project',   'args' => ['fields' => ['id','label','title']]],
 *   ['op' => 'render',    'args' => ['template' => '<h2>{{label}}</h2>']],
 *   ['op' => 'search',    'args' => ['query' => 'foo', 'opts' => [...]]],
 *   ['op' => 'join',      'args' => ['b' => [...], 'keyA' => 'id', 'keyB' => 'entity_id']],
 *   ['op' => 'cap',       'args' => ['capability_id' => '...', 'input_ref' => '...']],
 * ]
 *
 * $ctx: optional seed value (overrides first select's result)
 *
 * Returns the final accumulated value (array or string).
 */
function chain_eval(SQLite3 $db, array $steps, mixed $ctx = null): mixed {
    return partial_eval($db, $steps, $ctx, count($steps));
}

// ─── 9. PARTIAL EVAL ────────────────────────────────────────────────────────

/**
 * Like chain_eval but stops after $until steps (1-indexed).
 * Useful for preview, debugging, and LLM-guided step-by-step execution.
 */
function partial_eval(SQLite3 $db, array $steps, mixed $ctx = null, int $until = PHP_INT_MAX): mixed {
    $acc = $ctx;
    $n   = 0;
    foreach ($steps as $step) {
        if ($n >= $until) break;
        $op   = $step['op']   ?? '';
        $args = $step['args'] ?? [];
        $acc  = _ls_apply_step($db, $op, $args, $acc);
        $n++;
    }
    return $acc;
}

function _ls_apply_step(SQLite3 $db, string $op, array $args, mixed $acc): mixed {
    return match($op) {
        'select'    => proto_select($db, $args['type'], $args['opts'] ?? []),
        'filter'    => proto_filter(
                           is_array($acc) ? $acc : [],
                           $args['pred'] ?? $args['fn'] ?? fn() => true
                       ),
        'join'      => proto_join(
                           is_array($acc) ? $acc : [],
                           $args['b'] ?? [],
                           $args['keyA'] ?? 'id',
                           $args['keyB'] ?? 'id',
                           $args['mode'] ?? 'inner'
                       ),
        'transform' => proto_transform(
                           is_array($acc) ? $acc : [],
                           $args['fn'] ?? fn($r) => $r
                       ),
        'project'   => proto_project(
                           is_array($acc) ? $acc : [],
                           $args['fields'] ?? []
                       ),
        'render'    => is_array($acc)
                           ? array_map(fn($r) => proto_render($args['template'] ?? '', $r), $acc)
                           : proto_render($args['template'] ?? '', is_array($acc) ? $acc : []),
        'search'    => proto_search($db, $args['query'] ?? '', $args['opts'] ?? []),
        'cap'       => _ls_apply_capability($db, $args['capability_id'] ?? '', $args),
        'literal'   => $args['value'] ?? null,
        default     => throw new InvalidArgumentException("Unknown protocol op: {$op}"),
    };
}

// ─── Capability application ──────────────────────────────────────────────────

function _ls_apply_capability(SQLite3 $db, string $cap_id, array $args): mixed {
    $row = $db->querySingle(
        "SELECT * FROM capabilities WHERE id = '" . SQLite3::escapeString($cap_id) . "' AND is_enabled = 1",
        true
    );
    if (!$row) throw new RuntimeException("Capability not found or disabled: {$cap_id}");
    $impl = $row['impl_ref'];
    if (!file_exists($impl)) throw new RuntimeException("Capability impl missing: {$impl}");
    // Capabilities receive ($db, $args) and return a value
    return (require $impl)($db, $args);
}
