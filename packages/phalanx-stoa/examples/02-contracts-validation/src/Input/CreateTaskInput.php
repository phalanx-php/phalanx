<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Contracts\Input;

use Phalanx\Stoa\Contract\Validatable;

final readonly class CreateTaskInput implements Validatable
{
    public function __construct(
        public string $title,
        public int $priority = 3,
    ) {}

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        $errors = [];

        if (strlen(trim($this->title)) < 8) {
            $errors['title'][] = 'Must be at least 8 characters';
        }

        if ($this->priority < 1 || $this->priority > 5) {
            $errors['priority'][] = 'Must be between 1 and 5';
        }

        return $errors;
    }
}
