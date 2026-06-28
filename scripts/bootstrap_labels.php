<?php
/**
 * bootstrap_labels.php
 * Creates the required GitHub labels on the repo if they don't exist.
 * Run once: php scripts/bootstrap_labels.php
 *
 * Requires: LS_GH_TOKEN env var with repo write access
 */

$token = getenv('LS_GH_TOKEN') ?: '';
$owner = getenv('LS_REPO_OWNER') ?: 'Cypoe';
$repo  = getenv('LS_REPO_NAME')  ?: 'living-software';

$labels = [
    ['name' => 'Living Software', 'color' => '0e7fc0', 'description' => 'Core label for all Living Software system records'],
    ['name' => 'candidate',       'color' => 'f9c74f', 'description' => 'LLM or instance-proposed change, pending admissibility'],
    ['name' => 'conflict',        'color' => 'e63946', 'description' => 'Gossip merge conflict, requires resolution'],
    ['name' => 'admissible',      'color' => '57cc99', 'description' => 'Passed CI admissibility check, ready to adopt'],
    ['name' => 'adopted',         'color' => '80b918', 'description' => 'Incorporated into running instance genome'],
];

foreach ($labels as $label) {
    $url  = "https://api.github.com/repos/{$owner}/{$repo}/labels";
    $opts = ['http' => [
        'method'  => 'POST',
        'timeout' => 10,
        'header'  => implode("\r\n", [
            'User-Agent: living-software/1.0',
            'Accept: application/vnd.github.v3+json',
            'Content-Type: application/json',
            "Authorization: Bearer {$token}",
        ]),
        'content' => json_encode($label),
        'ignore_errors' => true,
    ]];
    $res  = @file_get_contents($url, false, stream_context_create($opts));
    $data = $res ? json_decode($res, true) : [];
    if (isset($data['id'])) {
        echo "created: {$label['name']}\n";
    } elseif (($data['errors'][0]['code'] ?? '') === 'already_exists') {
        echo "exists:  {$label['name']}\n";
    } else {
        echo "error:   {$label['name']} — " . json_encode($data) . "\n";
    }
}
