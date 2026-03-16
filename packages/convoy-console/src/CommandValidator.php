<?php

declare(strict_types=1);

namespace Convoy\Console;

interface CommandValidator
{
    /** @throws InvalidInputException */
    public function validate(CommandInput $input, CommandConfig $config): void;
}
