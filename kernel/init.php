<?php
/**
 * kernel/init.php — boot the Living Software kernel
 *
 * Usage:
 *   $db = require __DIR__ . '/init.php';
 *
 * Returns a configured SQLite3 instance with WAL, FK, FTS5 schema applied.
 * Idempotent: safe to call on every request.
 */

declare(strict_types=1);

function ls_kernel_boot(?string $db_path = null): SQLite3 {
    $path = $db_path ?? (getenv('LS_DB_PATH') ?: __DIR__ . '/../data/kernel.db');

    // Ensure data dir exists
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0750, true);

    $db = new SQLite3($path);

    // Performance + safety pragmas
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA cache_size = -8000');  // 8 MB page cache
    $db->exec('PRAGMA temp_store = MEMORY');
    $db->exec('PRAGMA mmap_size = 134217728'); // 128 MB mmap

    // Apply schema (idempotent)
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    $db->exec($schema);

    // Seed required runtime_state keys (idempotent via INSERT OR IGNORE in schema)
    // Additional boot-time seeds not in schema:
    $db->exec("INSERT OR IGNORE INTO runtime_state(key,value) VALUES
        ('boot_count','0'),
        ('instance_id', lower(hex(randomblob(8))))
    ");

    // Increment boot counter
    $db->exec("UPDATE runtime_state SET value = CAST(value AS INTEGER) + 1,
               updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
               WHERE key = 'boot_count'");

    return $db;
}

// Auto-boot when included directly (not in test context)
if (!defined('LS_TEST_MODE') && basename(__FILE__) !== basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // included as library — do not auto-execute
}
if (defined('LS_BOOT_ON_INCLUDE') && LS_BOOT_ON_INCLUDE) {
    return ls_kernel_boot();
}

return ls_kernel_boot();
