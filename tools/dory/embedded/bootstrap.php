<?php

echo "Dory runtime booted from embedded bytes.\n";
echo "SAPI Name: " . php_sapi_name() . "\n";
echo "Swoole Extension Loaded: " . (extension_loaded('swoole') ? 'Yes' : 'No') . "\n";

if (class_exists('Swoole\Coroutine')) {
    \Swoole\Coroutine\run(static function (): void {
        echo "Coroutine Scheduler started.\n";
        \Swoole\Coroutine::sleep(0.1);
        echo "Coroutine woke up.\n";
    });
} else {
    echo "Swoole extension is missing or not fully initialized!\n";
}

echo "Dory bootstrap complete.\n";
