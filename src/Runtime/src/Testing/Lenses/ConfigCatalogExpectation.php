<?php

declare(strict_types=1);

namespace Phalanx\Testing\Lenses;

use Phalanx\Config\Config;
use Phalanx\Config\ConfigCatalog;
use PHPUnit\Framework\Assert;

final readonly class ConfigCatalogExpectation
{
    public function __construct(public ConfigCatalog $catalog)
    {
    }

    /** @param class-string<Config> $type */
    public function assertContains(string $type): self
    {
        Assert::assertContains(
            $type,
            $this->catalog->classes(),
            "Expected config catalog to contain {$type}.",
        );

        return $this;
    }

    /** @param class-string<Config> $type */
    public function assertNotContains(string $type): self
    {
        Assert::assertNotContains(
            $type,
            $this->catalog->classes(),
            "Expected config catalog not to contain {$type}.",
        );

        return $this;
    }
}
