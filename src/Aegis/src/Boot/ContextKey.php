<?php

declare(strict_types=1);

namespace Phalanx\Boot;

final class ContextKey
{
    private function __construct(
        private(set) string $name,
        private(set) string $description,
        private(set) bool $required,
        private(set) ?string $fallback = null,
        private(set) ?string $type = null,
        private(set) ?string $owner = null,
    ) {
    }

    public static function optional(
        string $name,
        ?string $fallback = null,
        ?string $description = null,
        ?string $type = null,
    ): self {
        return new self(
            name: $name,
            description: $description ?? sprintf('Optional environment variable "%s"', $name),
            required: false,
            fallback: $fallback,
            type: $type,
        );
    }

    public static function required(string $name, ?string $description = null, ?string $type = null): self
    {
        return new self(
            name: $name,
            description: $description ?? sprintf('Required environment variable "%s"', $name),
            required: true,
            type: $type,
        );
    }

    public function ownedBy(string $owner): self
    {
        $key = clone $this;
        $key->owner = $owner;

        return $key;
    }

    public function toRequirement(): BootRequirement
    {
        if ($this->required) {
            return Required::env($this->name, $this->description);
        }

        return Optional::env($this->name, fallback: $this->fallback, description: $this->description);
    }
}
