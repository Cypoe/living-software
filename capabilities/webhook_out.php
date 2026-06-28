<?php
/**
 * webhook_out capability
 * Input:  a candidate_record entity (from entities table, type=update_candidate or schema_candidate)
 * Output: creates a GitHub Issue tagged 'Living Software' + 'candidate'
 * Idempotent: checks for existing open issue with same title before creating.
 *
 * Env vars required:
 *   LS_GH_TOKEN   — GitHub PAT with issues:write scope
 *   LS_REPO_OWNER — default Cypoe
 *   LS_REPO_NAME  — default living-software
 */

function webhook_out_invoke(array $candidate, SQLite3 $db): array {
    $token     = getenv('LS_GH_TOKEN') ?: '';
    $owner     = getenv('LS_REPO_OWNER') ?: 'Cypoe';
    $repo      = getenv('LS_REPO_NAME')  ?: 'living-software';

    $id       = $candidate['id'] ?? 'unknown';
    $type     = $candidate['type'] ?? 'candidate';
    $meta     = json_decode($candidate['metadata_json'] ?? '{}', true) ?: [];
    $title    = "[{$type}] {$id}";
    $body     = "Emitted by running instance.\n\n```json\n" . json_encode($meta, JSON_PRETTY_PRINT) . "\n```\n";

    // Check for existing open issue with same title (idempotency)
    $searchUrl = "https://api.github.com/search/issues?q=" . urlencode("repo:{$owner}/{$repo} is:issue is:open in:title {$title}");
    $existing  = gh_api('GET', $searchUrl, null, $token);
    if (($existing['total_count'] ?? 0) > 0) {
        $issueNumber = $existing['items'][0]['number'];
        return ['ok' => true, 'deduped' => true, 'issue_number' => $issueNumber];
    }

    // Create issue
    $issueUrl = "https://api.github.com/repos/{$owner}/{$repo}/issues";
    $payload  = [
        'title'  => $title,
        'body'   => $body,
        'labels' => ['Living Software', 'candidate'],
    ];
    $result = gh_api('POST', $issueUrl, $payload, $token);
    $number  = $result['number'] ?? null;

    if (!$number) {
        return ['ok' => false, 'error' => $result['message'] ?? 'unknown'];
    }

    // Record application
    $db->exec("insert into applications (capability_id, input_ref, output_ref, result)
               values ('webhook_out', '{$id}', 'issue:{$number}', 'ok')");

    return ['ok' => true, 'issue_number' => $number];
}

function gh_api(string $method, string $url, ?array $body, string $token): array {
    $opts = [
        'http' => [
            'method'  => $method,
            'timeout' => 10,
            'header'  => implode("\r\n", array_filter([
                'User-Agent: living-software/1.0',
                'Accept: application/vnd.github.v3+json',
                'Content-Type: application/json',
                $token ? "Authorization: Bearer {$token}" : null,
            ])),
            'content' => $body ? json_encode($body) : null,
            'ignore_errors' => true,
        ]
    ];
    $res = @file_get_contents($url, false, stream_context_create($opts));
    return $res ? (json_decode($res, true) ?: []) : [];
}
