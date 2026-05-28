<?php

declare(strict_types=1);

// Level 0: Does Swoole even initialize under the embed SAPI?
//
// Expected output:
//   sapi: embed (or "cli" if ripht overrides the SAPI name)
//   swoole loaded: yes
//   swoole version: 26.x.x
//   fiber support: yes
//   pcntl loaded: yes
//
// If swoole loaded: no -- the extension didn't initialize.
// Check if Swoole gates on php_sapi_name() === 'cli'.

echo 'sapi: ' . php_sapi_name() . "\n";
echo 'swoole loaded: ' . (extension_loaded('swoole') ? 'yes' : 'no') . "\n";

if (extension_loaded('swoole')) {
    echo 'swoole version: ' . phpversion('swoole') . "\n";
}

echo 'fiber support: ' . (class_exists(\Fiber::class) ? 'yes' : 'no') . "\n";
echo 'pcntl loaded: ' . (extension_loaded('pcntl') ? 'yes' : 'no') . "\n";

// Check if Swoole's coroutine class is available
echo 'Co class exists: ' . (class_exists(\Swoole\Coroutine::class) ? 'yes' : 'no') . "\n";
echo 'Server class exists: ' . (class_exists(\Swoole\Http\Server::class) ? 'yes' : 'no') . "\n";

// Check swoole ini settings
$fiberContext = ini_get('swoole.use_fiber_context');
echo 'swoole.use_fiber_context: ' . ($fiberContext ?: '(not set)') . "\n";

echo "\nlevel 0: " . (extension_loaded('swoole') ? 'PASS' : 'FAIL') . "\n";
