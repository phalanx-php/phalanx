<?php

require __DIR__ . '/compat.php';

if (class_exists('OpenSwoole\Runtime')) {
    echo "OpenSwoole\Runtime exists.\n";
} else {
    echo "OpenSwoole\Runtime DOES NOT exist.\n";
    exit(1);
}

if (class_exists('OpenSwoole\Coroutine')) {
    echo "OpenSwoole\Coroutine exists.\n";
} else {
    echo "OpenSwoole\Coroutine DOES NOT exist.\n";
    exit(1);
}
