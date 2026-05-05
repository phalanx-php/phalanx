<?php

declare(strict_types=1);

namespace Phalanx\Athena\Http;

final class Url
{
    public static function join(string $baseUrl, string $endpoint): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
    }
}
