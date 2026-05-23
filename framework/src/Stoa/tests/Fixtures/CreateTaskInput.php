<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures;

use Phalanx\Stoa\Contract\Validatable;

final readonly class CreateTaskInput implements Validatable
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public TaskPriority $priority = TaskPriority::Normal,
    ) {
    }

    public function validate(): array
    {
        $errors = [];

        if (strlen($this->title) === 0) {
            $errors['title'][] = 'Title is required';
        }

        if (strlen($this->title) > 255) {
            $errors['title'][] = 'Title must be 255 characters or less';
        }

        if ($this->description !== null && strlen($this->description) > 5000) {
            $errors['description'][] = 'Description must be 5000 characters or less';
        }

        return $errors;
    }
}
