<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Advanced\Input;

use Phalanx\Stoa\Contract\Validatable;

final readonly class CreateJobInput implements Validatable
{
    public function __construct(
        public string $name,
        public int $priority = 3,
    ) {
    }

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        $errors = [];

        if (!preg_match('/^[a-z0-9-]{6,64}$/', $this->name)) {
            $errors['name'][] = 'Use 6-64 lowercase letters, numbers, or dashes';
        }

        if ($this->priority < 1 || $this->priority > 5) {
            $errors['priority'][] = 'Must be between 1 and 5';
        }

        return $errors;
    }
}
