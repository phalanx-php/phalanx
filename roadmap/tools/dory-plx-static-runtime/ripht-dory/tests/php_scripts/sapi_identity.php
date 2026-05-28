<?php

declare(strict_types=1);

echo json_encode([
    'sapi_name' => php_sapi_name(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
], JSON_THROW_ON_ERROR);
