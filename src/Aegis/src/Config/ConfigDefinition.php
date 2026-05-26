<?php

declare(strict_types=1);

namespace Phalanx\Config;

/** @phpstan-type EntryList list<ConfigEntry> */
final readonly class ConfigDefinition
{
    /**
     * @param class-string<Config> $type
     * @param EntryList $entries
     */
    public function __construct(
        public string $type,
        public array $entries,
    ) {
    }
}
