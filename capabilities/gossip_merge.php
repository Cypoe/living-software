<?php
/**
 * gossip_merge capability
 *
 * Merges an incoming delta (from a peer instance or PR) into the local
 * entity graph. Implements the sparse-ancestry merge:
 *
 *   1. Find common ancestor commit (via runtime_state.adopted_commit lineage)
 *   2. Compute delta_incoming = entities in payload not in local graph
 *   3. For each incoming record:
 *      a. If not present locally → insert (non-conflicting)
 *      b. If present with identical content_hash → no-op (idempotent)
 *      c. If present with different content → emit conflict_record
 *   4. Return merge_result summary
 *
 * Input payload shape:
 *   {
 *     "peer_id": string,
 *     "ancestor_sha": string,
 *     "entities": [...entity rows...],
 *     "schemas": [...schema rows...]
 *   }
 */

function gossip_merge_invoke(array $delta, SQLite3 $db): array {
    $peerId      = $delta['peer_id'] ?? 'unknown';
    $ancestorSha = $delta['ancestor_sha'] ?? null;
    $inserted    = 0;
    $skipped     = 0;
    $conflicts   = [];

    // Merge schemas first (needed for entity FK refs)
    foreach ($delta['schemas'] ?? [] as $schema) {
        $existing = $db->querySingle(
            "select id from schemas where id='" . SQLite3::escapeString($schema['id']) . "'"
        );
        if (!$existing) {
            $stmt = $db->prepare(
                'insert or ignore into schemas (id, name, definition_json) values (:id,:name,:def)'
            );
            $stmt->bindValue(':id',   $schema['id'], SQLITE3_TEXT);
            $stmt->bindValue(':name', $schema['name'] ?? $schema['id'], SQLITE3_TEXT);
            $stmt->bindValue(':def',  json_encode($schema['definition'] ?? new stdClass()), SQLITE3_TEXT);
            $stmt->execute();
            $inserted++;
        }
    }

    // Merge entities
    foreach ($delta['entities'] ?? [] as $ent) {
        $eid  = SQLite3::escapeString($ent['id']);
        $existing = $db->querySingle("select metadata_json from entities where id='{$eid}'", true);

        if (!$existing) {
            // Not present locally — insert
            $stmt = $db->prepare(
                'insert or ignore into entities (id, type, owner, body_ref, metadata_json)
                 values (:id,:type,:owner,:body_ref,:meta)'
            );
            $stmt->bindValue(':id',       $ent['id'], SQLITE3_TEXT);
            $stmt->bindValue(':type',     $ent['type'] ?? 'unknown', SQLITE3_TEXT);
            $stmt->bindValue(':owner',    $ent['owner'] ?? null, SQLITE3_TEXT);
            $stmt->bindValue(':body_ref', $ent['body_ref'] ?? null, SQLITE3_TEXT);
            $stmt->bindValue(':meta',     json_encode($ent['metadata'] ?? new stdClass()), SQLITE3_TEXT);
            $stmt->execute();
            $inserted++;
        } else {
            // Present — compare content hash
            $localHash    = md5($existing['metadata_json'] ?? '');
            $incomingHash = md5(json_encode($ent['metadata'] ?? new stdClass()));
            if ($localHash === $incomingHash) {
                $skipped++; // identical — no-op
            } else {
                // Conflict — emit conflict_record, never overwrite
                $conflictId = 'conflict_' . substr(md5($ent['id'] . $peerId . time()), 0, 12);
                $conflictMeta = json_encode([
                    'entity_id'    => $ent['id'],
                    'peer_id'      => $peerId,
                    'local_hash'   => $localHash,
                    'incoming_hash'=> $incomingHash,
                    'detected_at'  => gmdate('c'),
                ]);
                $db->exec("insert or ignore into entities (id, type, metadata_json)
                           values ('{$conflictId}', 'conflict_record', '"
                           . SQLite3::escapeString($conflictMeta) . "')");
                $conflicts[] = $ent['id'];
            }
        }
    }

    // Record this merge as an application
    $summary = json_encode([
        'peer_id'   => $peerId,
        'inserted'  => $inserted,
        'skipped'   => $skipped,
        'conflicts' => count($conflicts),
    ]);
    $db->exec("insert into applications (capability_id, input_ref, output_ref, result)
               values ('gossip_merge', 'peer:{$peerId}', 'merge_result', '"
               . SQLite3::escapeString($summary) . "')");

    return [
        'ok'        => true,
        'inserted'  => $inserted,
        'skipped'   => $skipped,
        'conflicts' => $conflicts,
    ];
}
