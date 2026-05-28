<?php

echo "Dory Test Script Starting...\n";

/** @var \Phx\ScriptScope $dory */
$dory = $GLOBALS['dory'];

echo "Fetching concurrently via native Phalanx Iris HTTP Client...\n";

// Use $dory->http->get() natively instead of raw curl functions!
$results = $dory->concurrent(
    fetch1: static function() use ($dory) {
        echo "  [Coroutine 1] Starting fetch...\n";
        $response = $dory->http->get($dory, 'https://httpbin.org/delay/1');
        echo "  [Coroutine 1] Fetched: " . $response->status . "\n";
        return $response->body;
    },
    fetch2: static function() use ($dory) {
        echo "  [Coroutine 2] Starting fetch...\n";
        $response = $dory->http->get($dory, 'https://httpbin.org/delay/1');
        echo "  [Coroutine 2] Fetched: " . $response->status . "\n";
        return $response->body;
    }
);

echo "All tasks finished.\n";
