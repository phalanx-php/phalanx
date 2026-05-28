<?php
// This script is compiled directly into the Dory Rust binary.
// It is the first PHP code executed when Dory boots.

echo "Dory Engine booted from embedded bytes.\n";
echo "SAPI Name: " . php_sapi_name() . "\n";
echo "Swoole Extension Loaded: " . (extension_loaded('swoole') ? 'Yes' : 'No') . "\n";

// Example coroutine to prove the event loop and coroutines are active
if (class_exists('Swoole\Coroutine')) {
    \Swoole\Coroutine\run(function () {
        echo "Coroutine Scheduler started.\n";
        \Swoole\Coroutine::sleep(0.1);
        echo "Coroutine woke up.\n";
    });
} else {
    echo "Swoole extension is missing or not fully initialized!\n";
}

echo "Dory bootstrap complete.\n";
