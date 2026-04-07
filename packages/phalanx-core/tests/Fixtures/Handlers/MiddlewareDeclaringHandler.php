<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Handlers;

use Phalanx\HasMiddleware;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

/**
 * Test fixture: declares per-instance middleware via HasMiddleware. Used to
 * verify the dispatcher reads the third middleware layer at handler-instance
 * level (innermost) and runs it.
 */
final class MiddlewareDeclaringHandler implements Scopeable, HasMiddleware
{
    /** @var list<class-string> */
    public array $middleware {
        get => [InstanceMiddleware::class];
    }

    public function __invoke(Scope $scope): string
    {
        return 'core';
    }
}
