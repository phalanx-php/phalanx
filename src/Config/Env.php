<?php

declare(strict_types=1);

namespace Phalanx\Config;

use Attribute;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Env
{
    public function __construct(
        public string $key,
        public ?string $description = null,
        public bool $secret = false,
        public ?string $group = null,
        public ?string $example = null,
    ) {
    }

    public static function fromParameter(ReflectionParameter $parameter): ?self
    {
        $attributes = $parameter->getAttributes(self::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }
}
