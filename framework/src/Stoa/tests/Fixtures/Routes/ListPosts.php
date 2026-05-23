<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

final class ListPosts implements Scopeable
{
    /** @return array{posts: list<mixed>} */
    public function __invoke(Scope $scope): array
    {
        return ['posts' => []];
    }
}
