<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final class ListPosts implements Scopeable
{
    /** @return array{posts: list<mixed>} */
    public function __invoke(Scope $scope): array
    {
        return ['posts' => []];
    }
}
