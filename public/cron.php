<?php
/**
 * cron.php — Living Software heartbeat + self-update loop
 *
 * Schedule: * * * * * (every minute via cron, or call manually)
 *
 * Responsibilities:
 *   1. Write heartbeat timestamp
 *   2. Fetch latest admissible commit from GitHub
 *   3. Compare to currently adopted commit
 *   4. Run local preflight (structural admissibility check)
 *   5. If valid, record the new commit in runtime_state
 *   6. If invalid, emit a candidate/conflict record for review
 */

$dbPath    = __DIR__ . '/../kernel/kernel.db';
$schemaPath = __DIR__ . '/../kernel/schema.sql';
$repoOwner = getenv('LS_REPO_OWNER') ?: 'Cypoe';
$repoName  = getenv('LS_REPO_NAME')  ?: 'living-software';
$branch    = getenv('LS_BRANCH')     ?: 'main';
$ghToken   = getenv('LS_GH_TOKEN')   ?: null;
$now = gmdate('c');

$db = new SQLite3($dbPath);
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec(file_get_contents($schemaPath));

function state_get(SQLite3 $db, string $key): ?string {
    $s = $db->prepare('select value from runtime_state where key = :k');
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : null;
    return $row ? $row['value'] : null;
}

function state_set(SQLite3 $db, string $key, string $value): void {
    $s = $db->prepare(
        'insert into runtime_state (key, value, updated_at)
         values (:k, :v, :t)
         on conflict(key) do update set value=excluded.value, updated_at=excluded.updated_at'
    );
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $s->bindValue(':v', $value, SQLITE3_TEXT);
    $s->bindValue(':t', gmdate('c'), SQLITE3_TEXT);
    $s->execute();
}

// 1. Heartbeat
state_set($db, 'last_heartbeat', $now);
echo "[{$now}] heartbeat\n";

// 2. Fetch latest commit from GitHub API
$apiUrl = "https://api.github.com/repos/{$repoOwner}/{$repoName}/commits/{$branch}";
$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'timeout' => 10,
    'header'  => implode("\r\n", array_filter([
        'User-Agent: living-software-cron/1.0',
        'Accept: application/vnd.github.v3+json',
        $ghToken ? "Authorization: Bearer {$ghToken}" : null,
    ])),
]]);

$response = @file_get_contents($apiUrl, false, $ctx);
if (!$response) {
    state_set($db, 'last_error', "github_unreachable:{$now}");
    echo "[{$now}] github unreachable, skipping\n";
    exit;
}

$data = json_decode($response, true);
$latestSha = $data['sha'] ?? null;
if (!$latestSha) {
    state_set($db, 'last_error', "bad_github_response:{$now}");
    echo "[{$now}] bad github response\n";
    exit;
}

$currentSha = state_get($db, 'adopted_commit');
echo "[{$now}] latest={$latestSha} current=" . ($currentSha ?: 'none') . "\n";

if ($latestSha === $currentSha) {
    echo "[{$now}] already on latest admissible commit, nothing to do\n";
    exit;
}

// 3. Check CI status of latestSha
$statusUrl = "https://api.github.com/repos/{$repoOwner}/{$repoName}/commits/{$latestSha}/check-runs";
$statusRes = @file_get_contents($statusUrl, false, $ctx);
$statusData = $statusRes ? json_decode($statusRes, true) : null;
$runs = $statusData['check_runs'] ?? [];
$allPassed = count($runs) > 0 && array_reduce($runs, function($carry, $run) {
    return $carry && $run['conclusion'] === 'success';
}, true);

if (!$allPassed) {
    state_set($db, 'last_skipped_sha', $latestSha);
    state_set($db, 'last_skipped_reason', 'ci_not_passed');
    echo "[{$now}] CI not passed for {$latestSha}, deferring adoption\n";
    // Emit candidate record for review
    $id = 'candidate_' . substr($latestSha, 0, 12) . '_' . time();
    $db->exec("insert or ignore into entities (id, type, metadata_json)
               values ('{$id}', 'update_candidate',
               '\"sha\":\"{$latestSha}\",\"reason\":\"ci_not_passed\",\"detected_at\":\"{$now}\"}')");
    exit;
}

// 4. Adopt
state_set($db, 'adopted_commit', $latestSha);
state_set($db, 'last_update', $now);
echo "[{$now}] adopted commit {$latestSha}\n";
