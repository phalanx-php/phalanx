<?php

declare(strict_types=1);

namespace Phalanx\Testing\Lenses;

use Phalanx\Config\Config;
use PHPUnit\Framework\Assert;

final readonly class ConfigExpectation
{
    public function __construct(public Config $config)
    {
    }

    public function assertConfigured(): self
    {
        Assert::assertTrue($this->config->configured, 'Expected config to report itself as configured.');

        return $this;
    }

    public function assertNotConfigured(): self
    {
        Assert::assertFalse($this->config->configured, 'Expected config to report itself as not configured.');

        return $this;
    }
}
