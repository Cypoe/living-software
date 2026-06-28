<?php
/**
 * spawn.php — bootstrap a new Living Software instance from this repo
 *
 * Usage: php spawn/spawn.php [--target=/path/to/new/instance] [--name=my-instance]
 *
 * What it does:
 *   1. Copies the kernel layer (schema.sql, schema only — no data)
 *   2. Copies capabilities/
 *   3. Copies public/ (index.php, cron.php)
 *   4. Writes a fresh .env from .env.example (not filled in)
 *   5. Writes an instance identity record into the new kernel.db
 *   6. Does NOT copy .git, kernel/kernel.db, .env, tests/, docs/
 *
 * The spawned instance is a clean genome copy: same capabilities,
 * same schema, zero data. It inherits the protocol but starts its
 * own ontology.
 */

$opts   = getopt('', ['target:', 'name:']);
$target = rtrim($opts['target'] ?? (dirname(__DIR__) . '/../ls-instance-' . date('Ymd-His')), '/');
$name   = $opts['name'] ?? ('instance-' . substr(bin2hex(random_bytes(4)), 0, 8));
$src    = dirname(__DIR__);

echo "Spawning Living Software instance\n";
echo "  source : {$src}\n";
echo "  target : {$target}\n";
echo "  name   : {$name}\n\n";

// Layers to copy (genome, not state)
$copy = [
    'kernel/schema.sql',
    'kernel/boot.php',
    'capabilities',
    'public',
    'config',
    'spawn',
    'layers',
    '.env.example',
    'AGENTS.md',
];

function copy_path(string $src, string $dst): void {
    if (is_dir($src)) {
        if (!is_dir($dst)) mkdir($dst, 0755, true);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            copy_path("{$src}/{$f}", "{$dst}/{$f}");
        }
    } else {
        if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
        copy($src, $dst);
    }
}

if (!is_dir($target)) mkdir($target, 0755, true);
foreach ($copy as $rel) {
    $s = "{$src}/{$rel}";
    $d = "{$target}/{$rel}";
    if (file_exists($s)) {
        copy_path($s, $d);
        echo "  copied : {$rel}\n";
    } else {
        echo "  missing: {$rel} (skipped)\n";
    }
}

// Write blank .env
$envSrc = file_get_contents("{$src}/.env.example");
file_put_contents("{$target}/.env", $envSrc);
echo "  wrote  : .env (from .env.example, fill in before running)\n";

// Create kernel dir + boot DB with identity record
$kernelDir = "{$target}/kernel";
if (!is_dir($kernelDir)) mkdir($kernelDir, 0755, true);
$db = new SQLite3("{$kernelDir}/kernel.db");
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec(file_get_contents("{$target}/kernel/schema.sql"));

$instanceMeta = json_encode([
    'spawned_from' => $src,
    'spawned_at'   => gmdate('c'),
    'name'         => $name,
]);
$db->exec("insert into entities (id, type, metadata_json)
           values ('instance_identity', 'instance_identity', '"
           . SQLite3::escapeString($instanceMeta) . "')");
$db->exec("insert into runtime_state (key, value) values ('instance_name', '" . SQLite3::escapeString($name) . "')");
$db->close();

echo "  wrote  : kernel/kernel.db (identity record inserted)\n";
echo "\nDone. Next steps:\n";
echo "  1. cd {$target}\n";
echo "  2. Fill in .env (LS_GH_TOKEN, LS_ROOT, LS_DEPLOY_METHOD, ...)\n";
echo "  3. Point your webserver docroot at {$target}/public\n";
echo "  4. Add cron: * * * * * php {$target}/public/cron.php >> /var/log/ls.log 2>&1\n";
echo "  5. GET /health to verify\n";
