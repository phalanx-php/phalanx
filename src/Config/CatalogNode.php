<?php

declare(strict_types=1);

namespace Phalanx\Config;

final readonly class CatalogNode
{
    /**
     * @param class-string<Config> $type
     * @param list<ConfigEntry> $entries
     * @param list<self> $children
     */
    public function __construct(
        public string $type,
        public string $path,
        public array $entries,
        public array $children,
    ) {
    }
}
