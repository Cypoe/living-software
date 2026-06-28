<?php
$stateFile = __DIR__ . '/../kernel/heartbeat.json';
$now = gmdate('c');

$state = [
    'service' => 'living-software',
    'heartbeat_at' => $now,
    'note' => 'cron heartbeat placeholder; extend to fetch GitHub, compare latest admissible commit, and self-update'
];

file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
echo "heartbeat: {$now}\n";
