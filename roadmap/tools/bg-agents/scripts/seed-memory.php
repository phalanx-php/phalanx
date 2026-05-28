<?php

declare(strict_types=1);

/**
 * Seed a memory record directly into daemon8 — useful for testing the RAG
 * retrieval path without going through the bookkeeper promotion flow first.
 *
 * Usage: php scripts/seed-memory.php "<topic>" "<summary>" [tag1,tag2]
 */

$topic = $argv[1] ?? null;
$summary = $argv[2] ?? null;
$tagsRaw = $argv[3] ?? '';
$baseUrl = rtrim($argv[4] ?? 'http://localhost:8888', '/');

if ($topic === null || $summary === null) {
    fwrite(STDERR, "usage: php scripts/seed-memory.php <topic> <summary> [tag1,tag2] [daemon8-url]\n");
    exit(2);
}

$tags = $tagsRaw === '' ? [] : array_map('trim', explode(',', $tagsRaw));

$envelope = [
    'schema' => 'phalanx.swarm.v1',
    'event_id' => 'ev_' . uniqid(),
    'trace_id' => null,
    'causation_id' => null,
    'workspace' => 'bg-agents',
    'session' => 'seed',
    'from' => 'bookkeeper',
    'addressed_to' => null,
    'kind' => 'blackboard_post',
    'payload' => [
        'bg_kind' => 'bg.memory.record',
        'topic' => $topic,
        'summary' => $summary,
        'tags' => $tags,
        'supersedes' => [],
        'source_observations' => [],
        'created_at_ns' => (int) (microtime(true) * 1e9),
    ],
];

$body = [
    'kind' => 'custom',
    'channel' => 'swarm_message',
    'severity' => 'info',
    'app' => 'bg-agents',
    'tags' => array_merge(['bg.memory'], $tags),
    'data' => $envelope,
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "{$baseUrl}/ingest",
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code >= 200 && $code < 300) {
    echo "seeded memory: {$topic}\n";
    exit(0);
}

fwrite(STDERR, "ingest failed (HTTP {$code}): {$resp}\n");
exit(1);
