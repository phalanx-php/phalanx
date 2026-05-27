<?php

declare(strict_types=1);

return [
    'extensions' => [
        'openswoole',
        'openssl',
        'curl',
        'mbstring',
        'pcntl',
        'sockets',
        'posix',
    ],
    'optional_extensions' => [
        'pdo_pgsql',
        'pdo_sqlite',
        'redis',
    ],
    'build_root' => getenv('SPC_BUILD_ROOT') ?: '/tmp/spc-build',
    'php_version' => '8.4',
];
