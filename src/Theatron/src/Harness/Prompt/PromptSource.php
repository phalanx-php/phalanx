<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Harness\Prompt;

use Phalanx\Scope\TaskScope;

interface PromptSource
{
    public string $id { get; }

    public function __invoke(TaskScope $scope): string;
}
