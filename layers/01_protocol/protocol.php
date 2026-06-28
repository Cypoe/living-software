<?php
/**
 * protocol.php — record algebra over the kernel
 *
 * Six pure operations. All return plain arrays. None write.
 * Include this file to get the algebra; do not execute directly.
 */

/** Select all entities of a given type. */
function proto_select(SQLite3 $db, string $type, int $limit = 100): array {
    $t   = SQLite3::escapeString($type);
    $rows = [];
    $res  = $db->query("select * from entities where type='{$t}' order by created_at desc limit {$limit}");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['metadata'] = json_decode($row['metadata_json'] ?? '{}', true);
        $rows[] = $row;
    }
    return $rows;
}

/** Filter a record set by a metadata key/value pair. */
function proto_filter(array $records, string $key, mixed $value): array {
    return array_values(array_filter($records, fn($r) => ($r['metadata'][$key] ?? null) === $value));
}

/** Join two record sets on a shared field. */
function proto_join(array $a, array $b, string $keyA, string $keyB): array {
    $index = [];
    foreach ($b as $row) $index[$row[$keyB] ?? $row['metadata'][$keyB] ?? ''] = $row;
    $result = [];
    foreach ($a as $row) {
        $k = $row[$keyA] ?? $row['metadata'][$keyA] ?? '';
        if (isset($index[$k])) $result[] = array_merge($row, ['_joined' => $index[$k]]);
    }
    return $result;
}

/** Apply a transform callable to each record. */
function proto_transform(array $records, callable $fn): array {
    return array_map($fn, $records);
}

/** Project: keep only specified fields from each record. */
function proto_project(array $records, array $fields): array {
    return array_map(fn($r) => array_intersect_key($r, array_flip($fields)), $records);
}

/** Render: apply a template string to a record ({{field}} substitution). */
function proto_render(string $template, array $record): string {
    $flat = array_merge($record, $record['metadata'] ?? []);
    return preg_replace_callback('/\{\{(\w+)\}\}/', function($m) use ($flat) {
        return htmlspecialchars((string)($flat[$m[1]] ?? ''), ENT_QUOTES);
    }, $template);
}
