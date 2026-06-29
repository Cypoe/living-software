<?php
/**
 * cron.php — Living Software perpetual self-loop (Layer 02)
 *
 * Run every minute via cron:
 *   * * * * * php /path/to/living-software/public/cron.php >> /var/log/ls-cron.log 2>&1
 *
 * Responsibilities (in order):
 *   1. Heartbeat — update last_heartbeat in runtime_state
 *   2. Deploy watch — if deploy_method=git, pull + adopt new commit
 *   3. Health check — verify /health responds 200
 *   4. CI rules — eval any ci_rule entities whose trigger fired
 *   5. Materialized views — recompute any invalidated views
 *   6. Backup — if backup_enabled=1, rsync/ftp snapshot kernel.db
 *   7. Self-restart — if restart_needed=1, clear flag and exec restart_cmd
 *   8. Gossip — push runtime_state to peer instances
 */

declare(strict_types=1);

define('LS_ROOT', dirname(__DIR__, 2));
define('LS_TEST_MODE', false);
require LS_ROOT . '/kernel/init.php';
require LS_ROOT . '/layers/01_protocol/protocol.php';

$db  = ls_kernel_boot();
$now = (new DateTime())->format(DateTime::ATOM);

function ls_log(string $tag, string $msg): void {
    echo "[" . date('Y-m-d H:i:s') . "] [{$tag}] {$msg}" . PHP_EOL;
}

function rs_get(SQLite3 $db, string $key): string {
    return (string)$db->querySingle("SELECT value FROM runtime_state WHERE key='" . SQLite3::escapeString($key) . "'");
}

function rs_set(SQLite3 $db, string $key, string $value): void {
    $stmt = $db->prepare("INSERT OR REPLACE INTO runtime_state(key,value,updated_at) VALUES(:k,:v,strftime('%Y-%m-%dT%H:%M:%fZ','now'))");
    $stmt->bindValue(':k',$key); $stmt->bindValue(':v',$value); $stmt->execute();
}

// ─── 1. Heartbeat ────────────────────────────────────────────────────────────
rs_set($db, 'last_heartbeat', $now);
ls_log('heartbeat', $now);

// ─── 2. Deploy watch ─────────────────────────────────────────────────────────
$deploy_method = rs_get($db, 'deploy_method');
if ($deploy_method === 'git') {
    $repo_path = LS_ROOT;
    $out = [];
    exec("cd " . escapeshellarg($repo_path) . " && git fetch origin main 2>&1", $out, $rc);
    if ($rc === 0) {
        $remote_sha = trim(shell_exec("cd " . escapeshellarg($repo_path) . " && git rev-parse origin/main"));
        $local_sha  = trim(shell_exec("cd " . escapeshellarg($repo_path) . " && git rev-parse HEAD"));
        if ($remote_sha !== $local_sha) {
            exec("cd " . escapeshellarg($repo_path) . " && git reset --hard origin/main 2>&1", $out2, $rc2);
            if ($rc2 === 0) {
                rs_set($db, 'adopted_commit', $remote_sha);
                rs_set($db, 'last_deploy', $now);
                ls_log('deploy', "adopted {$remote_sha}");
            } else {
                ls_log('deploy', 'git reset failed: ' . implode(' ', $out2));
            }
        } else {
            ls_log('deploy', 'already at HEAD ' . $local_sha);
        }
    } else {
        ls_log('deploy', 'git fetch failed: ' . implode(' ', $out));
    }
}

// ─── 3. Health check ─────────────────────────────────────────────────────────
$health_url = rs_get($db, 'health_check_url');
if (!empty($health_url)) {
    $ch = curl_init($health_url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    rs_set($db, 'last_health_code', (string)$code);
    ls_log('health', "HTTP {$code} from {$health_url}");
    if ($code !== 200) {
        ls_log('health', 'WARN: health check failed');
        // Attempt restart
        $cmd = rs_get($db, 'restart_cmd');
        if (!empty($cmd)) { exec($cmd); ls_log('health', 'restart triggered'); }
    }
}

// ─── 4. CI rules ─────────────────────────────────────────────────────────────
$ci_rules = proto_select($db, 'ci_rule', ['status' => 'active']);
foreach ($ci_rules as $rule) {
    $meta    = $rule['metadata'];
    $trigger = $meta['trigger'] ?? 'cron';
    $steps   = json_decode($meta['steps_json'] ?? '[]', true);
    if (empty($steps)) continue;
    try {
        $result = chain_eval($db, $steps);
        ls_log('ci', "rule {$rule['id']} ok: " . json_encode($result));
        // Write result back as application log
        $aid = bin2hex(random_bytes(8));
        $stmt = $db->prepare(
            "INSERT INTO applications(id,capability_id,status,result_json,created_at)
             VALUES(:id,'ci_rule','ok',:res,strftime('%Y-%m-%dT%H:%M:%fZ','now'))"
        );
        $stmt->bindValue(':id',$aid);
        $stmt->bindValue(':res',json_encode(['result'=>$result]));
        $stmt->execute();
    } catch (Throwable $e) {
        ls_log('ci', "rule {$rule['id']} error: " . $e->getMessage());
    }
}

// ─── 5. Materialized views recompute ─────────────────────────────────────────
$views_res = $db->query("SELECT * FROM materialized_views WHERE invalidated = 1");
while ($view = $views_res->fetchArray(SQLITE3_ASSOC)) {
    try {
        $chain = $db->querySingle(
            "SELECT steps_json FROM transform_chains WHERE id='" . SQLite3::escapeString($view['source_chain_id'] ?? '') . "'"
        );
        $steps  = json_decode($chain ?: '[]', true);
        $result = chain_eval($db, $steps);
        $body   = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE);
        $t_now  = (new DateTime())->format(DateTime::ATOM);
        $stmt   = $db->prepare(
            "UPDATE materialized_views SET body=:body, invalidated=0, last_computed=:now, updated_at=:now WHERE id=:id"
        );
        $stmt->bindValue(':body',$body); $stmt->bindValue(':now',$t_now); $stmt->bindValue(':id',$view['id']);
        $stmt->execute();
        ls_log('views', "recomputed view {$view['id']}");
    } catch (Throwable $e) {
        ls_log('views', "view {$view['id']} error: " . $e->getMessage());
    }
}

// ─── 6. Backup ───────────────────────────────────────────────────────────────
$backup_enabled = rs_get($db, 'backup_enabled');
if ($backup_enabled === '1') {
    $backup_method = rs_get($db, 'backup_method'); // rsync | ftp | cp
    $backup_target = rs_get($db, 'backup_target');
    $db_path       = getenv('LS_DB_PATH') ?: LS_ROOT . '/data/kernel.db';
    if (!empty($backup_target)) {
        $cmd = match($backup_method) {
            'rsync' => "rsync -az " . escapeshellarg($db_path) . " " . escapeshellarg($backup_target),
            'cp'    => "cp " . escapeshellarg($db_path) . " " . escapeshellarg($backup_target . '/kernel.' . date('Ymd-His') . '.db'),
            default => null,
        };
        if ($cmd) {
            exec($cmd, $bout, $brc);
            ls_log('backup', $brc === 0 ? "ok -> {$backup_target}" : 'FAILED: ' . implode(' ',$bout));
        }
    }
}

// ─── 7. Self-restart flag ────────────────────────────────────────────────────
if (rs_get($db, 'restart_needed') === '1') {
    rs_set($db, 'restart_needed', '0');
    $cmd = rs_get($db, 'restart_cmd');
    if (!empty($cmd)) { exec($cmd); ls_log('restart', 'executed: ' . $cmd); }
}

// ─── 8. Gossip ───────────────────────────────────────────────────────────────
$peers_res = $db->query("SELECT * FROM entities WHERE type='peer_instance' AND status='active'");
while ($peer = $peers_res->fetchArray(SQLITE3_ASSOC)) {
    $meta = json_decode($peer['metadata_json'] ?? '{}', true);
    $url  = $meta['gossip_endpoint'] ?? null;
    if (!$url) continue;
    $state_res = $db->query("SELECT key,value FROM runtime_state");
    $state = [];
    while ($row = $state_res->fetchArray(SQLITE3_ASSOC)) $state[$row['key']] = $row['value'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['state' => $state, 'from' => rs_get($db,'instance_id')]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
    ]);
    $grc = curl_exec($ch);
    $gcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    ls_log('gossip', "peer {$peer['id']}: HTTP {$gcode}");
}

ls_log('loop', 'tick complete');
