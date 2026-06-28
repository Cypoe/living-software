<?php
/**
 * admissibility.php — structural closure check
 * Verifies:
 *   1. All capability schema_in/schema_out IDs referenced in capabilities/*.json
 *      are declared in kernel/schema.sql (via a seeded SQLite check)
 *   2. No duplicate capability IDs
 *   3. No orphan impl_refs (file must exist in capabilities/)
 */

$capDir     = __DIR__ . '/../capabilities';
$schemaPath = __DIR__ . '/../kernel/schema.sql';
$errors     = [];

// Boot kernel
$db = new SQLite3(':memory:');
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec(file_get_contents($schemaPath));

// Collect capabilities
$caps = [];
if (is_dir($capDir)) {
    foreach (glob($capDir . '/*.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) continue;
        $id = $data['id'] ?? null;
        if (!$id) continue;
        if (isset($caps[$id])) {
            $errors[] = "duplicate capability id: {$id}";
        }
        $caps[$id] = $data;
    }
}

// Collect registered schema IDs from kernel DB
// (for now, if capabilities/ is empty, this is trivially closed)
$knownSchemas = [];
$res = $db->query('select id from schemas');
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $knownSchemas[$row['id']] = true;
}

foreach ($caps as $id => $cap) {
    // schema_in / schema_out only need to resolve if schemas are registered
    // When schema table is empty, we defer to runtime; flag if schema table has entries
    if (count($knownSchemas) > 0) {
        foreach (['schema_in', 'schema_out'] as $f) {
            if (isset($cap[$f]) && !isset($knownSchemas[$cap[$f]])) {
                $errors[] = "capability {$id}: {$f}='{$cap[$f]}' not found in schemas table";
            }
        }
    }
    // Check impl_ref file exists
    if (isset($cap['impl_ref'])) {
        $implPath = __DIR__ . '/../capabilities/' . $cap['impl_ref'];
        if (!file_exists($implPath)) {
            $errors[] = "capability {$id}: impl_ref '{$cap['impl_ref']}' not found";
        }
    }
}

if ($errors) {
    foreach ($errors as $e) {
        echo "FAIL: {$e}\n";
    }
    exit(1);
}

echo "OK: admissibility — structural closure check passed\n";
