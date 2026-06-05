<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Provider;

use RuntimeException;

final class FactoryError extends RuntimeException
{
    public static function missingWireTranslator(string $providerId): self
    {
        return new self("Provider '{$providerId}' has no wire_translator configured");
    }

    public static function noConstructor(string $class): self
    {
        return new self("Wire translator '{$class}' has no constructor");
    }

    public static function missingApiKey(string $providerId): self
    {
        return new self("Provider '{$providerId}' requires an API key");
    }

    public static function missingParameter(string $providerId, string $param): self
    {
        return new self("Provider '{$providerId}': constructor parameter '{$param}' has no default and was not supplied");
    }
}
