<?php

declare(strict_types=1);

namespace Symfony\Component\Runtime;

class GenericRuntime
{
    /** @param array<string, mixed> $options */
    public function __construct(array $options = []) {}

    public function getRunner(?object $application): RunnerInterface
    {
        throw new \LogicException('Stub only');
    }
}
