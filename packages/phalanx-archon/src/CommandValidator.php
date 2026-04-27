<?php

declare(strict_types=1);

namespace Phalanx\Archon;

interface CommandValidator
{
    /** @throws InvalidInputException */
    public function validate(CommandInput $input, CommandConfig $config): void;
}
