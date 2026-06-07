<?php

declare(strict_types=1);

namespace Phalanx\Testing\Lenses;

use Phalanx\Config\ValidationResult;
use PHPUnit\Framework\Assert;

final readonly class ConfigValidationExpectation
{
    public function __construct(public ValidationResult $result)
    {
    }

    public function assertClean(): self
    {
        Assert::assertFalse($this->result->hasErrors, 'Expected config validation to have no errors.');
        Assert::assertFalse($this->result->hasWarnings, 'Expected config validation to have no warnings.');
        Assert::assertFalse($this->result->blocksBoot, 'Expected config validation not to block boot.');

        return $this;
    }

    public function assertHasErrors(): self
    {
        Assert::assertTrue($this->result->hasErrors, 'Expected config validation to report errors.');

        return $this;
    }

    public function assertHasWarnings(): self
    {
        Assert::assertTrue($this->result->hasWarnings, 'Expected config validation to report warnings.');

        return $this;
    }

    public function assertBlocksBoot(): self
    {
        Assert::assertTrue($this->result->blocksBoot, 'Expected config validation to block boot.');

        return $this;
    }

    public function assertDoesNotBlockBoot(): self
    {
        Assert::assertFalse($this->result->blocksBoot, 'Expected config validation not to block boot.');

        return $this;
    }
}
