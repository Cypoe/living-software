<?php
/**
 * protocol_write.php — Living Software write operations (Layer 01)
 *
 * All mutations go through these functions.
 * Callers must include protocol.php first.
 *
 * Functions:
 *   proto_create        — insert a new entity
 *   proto_update        — update metadata / fields on an entity
 *   proto_upsert        — insert or update by id
 *   proto_soft_delete   — set status = 'deleted'
 *   proto_schema_put    — create or update a schema definition
 *   proto_carrier_put   — attach a carrier to an entity
 *   proto_chain_put     — create or update a transform chain
 *   proto_view_put      — create or update a materialized view definition
 *   proto_capability_put— register a capability
 *   proto_app_log       — record a capability invocation
 */

declare(strict_types=1);

/**
 * Create a new entity. Returns the entity id.
 * $data keys: id, type, owner, label, schema_ref, visibility, metadata
 */
function proto_create(SQLite3 $db, array $data): string {
    $id       = $data['id']         ?? 'ent_' . bin2hex(random_bytes(8));
    $type     = $data['type']       ?? throw new InvalidArgumentException('type required');
    $owner    = $data['owner']      ?? '';
    $label    = $data['label']      ?? '';
    $schemaRef= $data['schema_ref'] ?? '';
    $vis      = $data['visibility'] ?? 'private';
    $meta     = json_encode($data['metadata'] ?? new stdClass(), JSON_UNESCAPED_UNICODE);

    $s = $db->prepare(
        'INSERT INTO entities (id, type, owner, label, schema_ref, visibility, metadata_json)
         VALUES (:id, :type, :owner, :label, :sref, :vis, :meta)'
    );
    $s->bindValue(':id',    $id,       SQLITE3_TEXT);
    $s->bindValue(':type',  $type,     SQLITE3_TEXT);
    $s->bindValue(':owner', $owner,    SQLITE3_TEXT);
    $s->bindValue(':label', $label,    SQLITE3_TEXT);
    $s->bindValue(':sref',  $schemaRef,SQLITE3_TEXT);
    $s->bindValue(':vis',   $vis,      SQLITE3_TEXT);
    $s->bindValue(':meta',  $meta,     SQLITE3_TEXT);
    $s->execute();

    if ($db->lastErrorCode() !== 0) {
        throw new RuntimeException('proto_create failed: ' . $db->lastErrorMsg());
    }
    return $id;
}

/**
 * Update an existing entity's metadata and/or top-level fields.
 * Only provided keys are changed (deep merge into metadata).
 */
function proto_update(SQLite3 $db, string $id, array $data): void {
    $existing = proto_select_one($db, $id);
    if (!$existing) throw new RuntimeException("proto_update: entity '{$id}' not found");

    $meta    = array_merge($existing['metadata'] ?? [], $data['metadata'] ?? []);
    $metaEnc = json_encode($meta, JSON_UNESCAPED_UNICODE);

    $label  = $data['label']      ?? $existing['label'];
    $vis    = $data['visibility'] ?? $existing['visibility'];
    $status = $data['status']     ?? $existing['status'];

    $s = $db->prepare(
        'UPDATE entities SET label=:label, visibility=:vis, status=:status,
         metadata_json=:meta, updated_at=strftime(\'%Y-%m-%dT%H:%M:%fZ\',\'now\')
         WHERE id=:id'
    );
    $s->bindValue(':label',  $label,   SQLITE3_TEXT);
    $s->bindValue(':vis',    $vis,     SQLITE3_TEXT);
    $s->bindValue(':status', $status,  SQLITE3_TEXT);
    $s->bindValue(':meta',   $metaEnc, SQLITE3_TEXT);
    $s->bindValue(':id',     $id,      SQLITE3_TEXT);
    $s->execute();
    proto_view_invalidate($db, $id);
}

/**
 * Upsert: insert if id not present, update metadata if it is.
 */
function proto_upsert(SQLite3 $db, array $data): string {
    $id = $data['id'] ?? null;
    if ($id && proto_select_one($db, $id)) {
        proto_update($db, $id, $data);
        return $id;
    }
    return proto_create($db, $data);
}

/**
 * Soft-delete: sets status = 'deleted'. Does not remove the row.
 */
function proto_soft_delete(SQLite3 $db, string $id): void {
    proto_update($db, $id, ['status' => 'deleted']);
}

/**
 * Create or update a schema definition.
 */
function proto_schema_put(SQLite3 $db, string $id, string $name, array $definition, string $version = '1'): void {
    $def = json_encode($definition, JSON_UNESCAPED_UNICODE);
    $s   = $db->prepare(
        'INSERT INTO schemas (id, name, version, definition_json)
         VALUES (:id, :name, :ver, :def)
         ON CONFLICT(id) DO UPDATE SET name=excluded.name, version=excluded.version,
           definition_json=excluded.definition_json,
           updated_at=strftime(\'%Y-%m-%dT%H:%M:%fZ\',\'now\')'
    );
    $s->bindValue(':id',   $id,      SQLITE3_TEXT);
    $s->bindValue(':name', $name,    SQLITE3_TEXT);
    $s->bindValue(':ver',  $version, SQLITE3_TEXT);
    $s->bindValue(':def',  $def,     SQLITE3_TEXT);
    $s->execute();
}

/**
 * Attach a carrier to an entity.
 */
function proto_carrier_put(SQLite3 $db, string $entityId, string $carrierType, ?string $locator, ?string $content, array $meta = []): string {
    $id = 'car_' . bin2hex(random_bytes(8));
    $s  = $db->prepare(
        'INSERT INTO carriers (id, entity_id, carrier_type, locator, content, metadata_json)
         VALUES (:id, :eid, :ct, :loc, :content, :meta)'
    );
    $s->bindValue(':id',      $id,                                     SQLITE3_TEXT);
    $s->bindValue(':eid',     $entityId,                               SQLITE3_TEXT);
    $s->bindValue(':ct',      $carrierType,                            SQLITE3_TEXT);
    $s->bindValue(':loc',     $locator,                                SQLITE3_TEXT);
    $s->bindValue(':content', $content,                                SQLITE3_TEXT);
    $s->bindValue(':meta',    json_encode($meta, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
    $s->execute();
    // Update entity body_ref to latest carrier
    $db->exec("UPDATE entities SET body_ref='" . SQLite3::escapeString($id) . "' WHERE id='" . SQLite3::escapeString($entityId) . "'");
    return $id;
}

/**
 * Create or update a transform chain.
 */
function proto_chain_put(SQLite3 $db, string $id, string $label, array $steps): void {
    $s = $db->prepare(
        'INSERT INTO transform_chains (id, label, steps_json)
         VALUES (:id, :label, :steps)
         ON CONFLICT(id) DO UPDATE SET label=excluded.label, steps_json=excluded.steps_json'
    );
    $s->bindValue(':id',    $id,                                        SQLITE3_TEXT);
    $s->bindValue(':label', $label,                                     SQLITE3_TEXT);
    $s->bindValue(':steps', json_encode($steps, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
    $s->execute();
}

/**
 * Create or update a materialized view definition.
 * Set $chainId to trigger auto-recompute on next proto_view_get call.
 */
function proto_view_put(SQLite3 $db, string $id, string $label, string $bodyType, ?string $chainId, array $entityRefs = []): void {
    $s = $db->prepare(
        'INSERT INTO materialized_views (id, label, source_chain_id, body_type, entity_refs, invalidated)
         VALUES (:id, :label, :chain, :bt, :refs, 1)
         ON CONFLICT(id) DO UPDATE SET label=excluded.label, source_chain_id=excluded.source_chain_id,
           body_type=excluded.body_type, entity_refs=excluded.entity_refs, invalidated=1,
           updated_at=strftime(\'%Y-%m-%dT%H:%M:%fZ\',\'now\')'
    );
    $s->bindValue(':id',    $id,                                              SQLITE3_TEXT);
    $s->bindValue(':label', $label,                                           SQLITE3_TEXT);
    $s->bindValue(':chain', $chainId,                                         SQLITE3_TEXT);
    $s->bindValue(':bt',    $bodyType,                                        SQLITE3_TEXT);
    $s->bindValue(':refs',  json_encode($entityRefs, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
    $s->execute();
}

/**
 * Register or update a capability descriptor.
 */
function proto_capability_put(SQLite3 $db, array $cap): void {
    $s = $db->prepare(
        'INSERT INTO capabilities (id, label, schema_in, schema_out, impl_type, impl_ref, contract_json)
         VALUES (:id, :label, :sin, :sout, :itype, :iref, :contract)
         ON CONFLICT(id) DO UPDATE SET label=excluded.label, schema_in=excluded.schema_in,
           schema_out=excluded.schema_out, impl_type=excluded.impl_type, impl_ref=excluded.impl_ref,
           contract_json=excluded.contract_json'
    );
    $s->bindValue(':id',       $cap['id']       ?? throw new InvalidArgumentException('id required'), SQLITE3_TEXT);
    $s->bindValue(':label',    $cap['label']    ?? '',  SQLITE3_TEXT);
    $s->bindValue(':sin',      $cap['schema_in']  ?? '',SQLITE3_TEXT);
    $s->bindValue(':sout',     $cap['schema_out'] ?? '',SQLITE3_TEXT);
    $s->bindValue(':itype',    $cap['impl_type']  ?? 'php', SQLITE3_TEXT);
    $s->bindValue(':iref',     $cap['impl_ref']   ?? '',    SQLITE3_TEXT);
    $s->bindValue(':contract', json_encode($cap['contract'] ?? new stdClass()), SQLITE3_TEXT);
    $s->execute();
}

/**
 * Log a capability invocation.
 */
function proto_app_log(SQLite3 $db, string $capabilityId, ?string $inputRef, mixed $result, ?string $ancestryRef = null, float $durationMs = 0.0): string {
    $id = 'app_' . bin2hex(random_bytes(8));
    $s  = $db->prepare(
        'INSERT INTO applications (id, capability_id, input_ref, status, result_json, ancestry_ref, duration_ms)
         VALUES (:id, :cap, :inp, :status, :result, :anc, :dur)'
    );
    $ok     = !isset($result['error']);
    $s->bindValue(':id',     $id,                                             SQLITE3_TEXT);
    $s->bindValue(':cap',    $capabilityId,                                   SQLITE3_TEXT);
    $s->bindValue(':inp',    $inputRef,                                       SQLITE3_TEXT);
    $s->bindValue(':status', $ok ? 'ok' : 'error',                           SQLITE3_TEXT);
    $s->bindValue(':result', json_encode($result, JSON_UNESCAPED_UNICODE),    SQLITE3_TEXT);
    $s->bindValue(':anc',    $ancestryRef,                                    SQLITE3_TEXT);
    $s->bindValue(':dur',    $durationMs,                                     SQLITE3_FLOAT);
    $s->execute();
    return $id;
}
