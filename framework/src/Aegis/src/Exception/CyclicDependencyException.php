<?php

declare(strict_types=1);

namespace Phalanx\Exception;

class CyclicDependencyException extends \LogicException
{
    /** @param list<string> $cycle */
    public function __construct(
        public readonly array $cycle,
        string $message = '',
    ) {
        $path = implode(' -> ', $cycle);
        parent::__construct($message ?: "Cyclic dependency detected: $path");
    }
}
