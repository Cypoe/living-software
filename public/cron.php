<?php
/**
 * cron.php — Living Software heartbeat + self-deploy loop
 *
 * Cron: * * * * * php /path/to/public/cron.php >> /var/log/ls.log 2>&1
 *
 * Steps:
 *   1. Heartbeat
 *   2. Load .env if present
 *   3. Fetch latest admissible commit from GitHub
 *   4. Compare to adopted_commit in runtime_state
 *   5. If newer + CI passed: pull/deploy via configured method
 *   6. Record result in runtime_state
 */

define('LS_START', microtime(true));

$root       = dirname(__DIR__);
$dbPath     = "{$root}/kernel/kernel.db";
$schemaPath = "{$root}/kernel/schema.sql";
$envPath    = "{$root}/.env";

// --- Load .env ---
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if ($k && !getenv($k)) putenv("{$k}={$v}");
    }
}

$repoOwner    = getenv('LS_REPO_OWNER')    ?: 'Cypoe';
$repoName     = getenv('LS_REPO_NAME')     ?: 'living-software';
$branch       = getenv('LS_BRANCH')        ?: 'main';
$ghToken      = getenv('LS_GH_TOKEN')      ?: '';
$deployMethod = getenv('LS_DEPLOY_METHOD') ?: 'git';
$lsRoot       = getenv('LS_ROOT')          ?: $root;
$now          = gmdate('c');

// --- Boot DB ---
if (!is_dir(dirname($dbPath))) mkdir(dirname($dbPath), 0755, true);
$db = new SQLite3($dbPath);
$db->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;');
$db->exec(file_get_contents($schemaPath));

function state_get(SQLite3 $db, string $key): ?string {
    $s = $db->prepare('select value from runtime_state where key=:k');
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : null;
    return $row ? $row['value'] : null;
}
function state_set(SQLite3 $db, string $key, string $value): void {
    $s = $db->prepare(
        'insert into runtime_state(key,value,updated_at) values(:k,:v,:t)
         on conflict(key) do update set value=excluded.value,updated_at=excluded.updated_at'
    );
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $s->bindValue(':v', $value, SQLITE3_TEXT);
    $s->bindValue(':t', gmdate('c'), SQLITE3_TEXT);
    $s->execute();
}
function log_line(string $msg): void {
    echo '[' . gmdate('c') . '] ' . $msg . PHP_EOL;
}

// --- 1. Heartbeat ---
state_set($db, 'last_heartbeat', $now);
log_line('heartbeat');

// --- 2. Fetch latest commit ---
$apiUrl = "https://api.github.com/repos/{$repoOwner}/{$repoName}/commits/{$branch}";
$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'timeout' => 10,
    'header'  => implode("\r\n", array_filter([
        'User-Agent: living-software-cron/1.0',
        'Accept: application/vnd.github.v3+json',
        $ghToken ? "Authorization: Bearer {$ghToken}" : null,
    ])),
    'ignore_errors' => true,
]]);
$response = @file_get_contents($apiUrl, false, $ctx);
if (!$response) {
    state_set($db, 'last_error', "github_unreachable:{$now}");
    log_line('github unreachable, skipping'); exit;
}
$data      = json_decode($response, true);
$latestSha = $data['sha'] ?? null;
if (!$latestSha) {
    state_set($db, 'last_error', "bad_github_response:{$now}");
    log_line('bad github response'); exit;
}
$currentSha = state_get($db, 'adopted_commit');
log_line("latest={$latestSha} current=" . ($currentSha ?: 'none'));
if ($latestSha === $currentSha) { log_line('up to date'); exit; }

// --- 3. Verify CI passed ---
$checkUrl = "https://api.github.com/repos/{$repoOwner}/{$repoName}/commits/{$latestSha}/check-runs";
$checkRes = @file_get_contents($checkUrl, false, $ctx);
$checkData = $checkRes ? json_decode($checkRes, true) : [];
$runs = $checkData['check_runs'] ?? [];
$allPassed = count($runs) > 0 && array_reduce($runs, fn($c, $r) => $c && $r['conclusion'] === 'success', true);
if (!$allPassed) {
    log_line("CI not passed for {$latestSha}, deferring");
    state_set($db, 'last_skipped_sha', $latestSha);
    state_set($db, 'last_skipped_reason', 'ci_not_passed');
    exit;
}

// --- 4. Deploy ---
log_line("deploying via {$deployMethod}");
$deployOk = false;
$deployLog = '';

if ($deployMethod === 'git') {
    // Requires: git installed, SSH key or HTTPS token configured on host
    $cmd = "cd " . escapeshellarg($lsRoot) . " && git fetch origin && git reset --hard origin/{$branch} 2>&1";
    $deployLog = shell_exec($cmd) ?: '';
    $deployOk = str_contains($deployLog, 'HEAD is now at') || str_contains($deployLog, 'Already up to date');

} elseif ($deployMethod === 'ftp') {
    // Uses PHP's built-in ftp extension
    $host      = getenv('LS_FTP_HOST')       ?: '';
    $user      = getenv('LS_FTP_USER')       ?: '';
    $pass      = getenv('LS_FTP_PASS')       ?: '';
    $remoteDir = getenv('LS_FTP_REMOTE_DIR') ?: '/public_html/living-software';
    $deployOk  = ftp_push_dir($lsRoot, $host, $user, $pass, $remoteDir, $deployLog);

} elseif ($deployMethod === 'sftp') {
    // Uses rsync over SSH (requires rsync + SSH key on host, no password)
    $sftpHost  = getenv('LS_SFTP_HOST')       ?: '';
    $sftpPort  = getenv('LS_SFTP_PORT')       ?: '22';
    $sftpUser  = getenv('LS_SFTP_USER')       ?: '';
    $sftpKey   = getenv('LS_SFTP_KEY_PATH')   ?: '';
    $remoteDir = getenv('LS_SFTP_REMOTE_DIR') ?: '/var/www/living-software';
    $excludes  = '--exclude=kernel/kernel.db --exclude=.env --exclude=.git';
    $cmd = "rsync -az --delete {$excludes}"
         . " -e " . escapeshellarg("ssh -p {$sftpPort} -i {$sftpKey} -o StrictHostKeyChecking=no")
         . " " . escapeshellarg(rtrim($lsRoot, '/') . '/')
         . " " . escapeshellarg("{$sftpUser}@{$sftpHost}:{$remoteDir}") . " 2>&1";
    $deployLog = shell_exec($cmd) ?: '';
    $deployOk  = ($deployLog === '' || !str_contains(strtolower($deployLog), 'error'));
}

// --- 5. Record result ---
if ($deployOk) {
    state_set($db, 'adopted_commit', $latestSha);
    state_set($db, 'last_update', $now);
    log_line("adopted {$latestSha}");
} else {
    state_set($db, 'last_error', "deploy_failed:{$latestSha}:{$now}");
    log_line("deploy failed: {$deployLog}");
}

// --- FTP helper ---
function ftp_push_dir(string $localBase, string $host, string $user, string $pass, string $remoteBase, string &$log): bool {
    if (!function_exists('ftp_connect')) { $log = 'ftp extension not loaded'; return false; }
    $conn = @ftp_connect($host, 21, 10);
    if (!$conn) { $log = 'ftp connect failed'; return false; }
    if (!@ftp_login($conn, $user, $pass)) { $log = 'ftp login failed'; ftp_close($conn); return false; }
    ftp_pasv($conn, true);

    $skip = ['.git', '.env', 'kernel/kernel.db', 'kernel/heartbeat.json'];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($localBase, FilesystemIterator::SKIP_DOTS)
    );
    $ok = true;
    foreach ($iter as $file) {
        $rel = ltrim(str_replace($localBase, '', $file->getPathname()), '/');
        foreach ($skip as $s) { if (str_starts_with($rel, $s)) continue 2; }
        $remotePath = rtrim($remoteBase, '/') . '/' . $rel;
        $remoteDir  = dirname($remotePath);
        @ftp_mkdir($conn, $remoteDir); // ignore if exists
        if (!@ftp_put($conn, $remotePath, $file->getPathname(), FTP_BINARY)) {
            $log .= "failed: {$rel}\n"; $ok = false;
        }
    }
    ftp_close($conn);
    return $ok;
}
