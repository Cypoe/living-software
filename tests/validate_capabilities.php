<?php
/**
 * validate_capabilities.php — validates all capability JSON descriptors
 * against capability.schema.json using basic structural checks
 * (no external JSON Schema library required)
 */

$capDir    = __DIR__ . '/../capabilities';
$schemaFile = __DIR__ . '/../kernel/capability.schema.json';

if (!is_dir($capDir)) {
    echo "OK: no capabilities/ directory, nothing to validate\n";
    exit(0);
}

$schema   = json_decode(file_get_contents($schemaFile), true);
$required = $schema['required'] ?? [];
$errors   = [];

foreach (glob($capDir . '/*.json') as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        $errors[] = basename($file) . ': invalid JSON';
        continue;
    }
    foreach ($required as $field) {
        if (!array_key_exists($field, $data)) {
            $errors[] = basename($file) . ": missing required field '{$field}'";
        }
    }
    if (isset($data['impl_type'])) {
        $allowed = ['deterministic', 'stochastic', 'hybrid'];
        if (!in_array($data['impl_type'], $allowed, true)) {
            $errors[] = basename($file) . ": impl_type must be one of: " . implode(', ', $allowed);
        }
    }
}

if ($errors) {
    foreach ($errors as $e) {
        echo "FAIL: {$e}\n";
    }
    exit(1);
}

echo "OK: validate_capabilities — all descriptors valid\n";
