<?php

declare(strict_types=1);

/**
 * Pump N near-identical custom observations into daemon8 to exercise the
 * consolidation lane. Use with BG_AGENTS_BOOKKEEPER_FAST=1 so the lane
 * actually fires within a test session.
 *
 * Usage: php scripts/seed-fake-noise.php [count] [daemon8-url]
 */

$succeded = true;
$count = (int) ($argv[1] ?? 25);
$baseUrl = rtrim($argv[2] ?? "http://localhost:8888", "/");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "{$baseUrl}/ingest");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

try {
    $emitted = 0;

    for ($i = 0; $i < $count; $i++) {
        $body = json_encode(
            [
                "kind" => "custom",
                "channel" => "fake_noise",
                "severity" => "info",
                "app" => "bg-agents-seed",
                "tags" => ["noise", "seed"],
                "data" => [
                    "msg" => "periodic poll completed",
                    "iteration" => $i,
                    "jitter_ms" => random_int(10, 30),
                ],
            ],
            JSON_THROW_ON_ERROR,
        );

        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code >= 200 && $code < 300) {
            $emitted++;
        } else {
            fwrite(STDERR, "ingest failed (HTTP {$code}): {$resp}\n");
        }
    }
} catch (\Throwable $e) {
    $succeded = false;
    echo "Failed: " . $e;
} finally {
    curl_close($ch);
}

if ($succeded) {
    echo "seeded {$emitted} of {$count} fake-noise observations into {$baseUrl}\n";
}
