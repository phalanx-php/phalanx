<?php

declare(strict_types=1);

namespace Phalanx;

use React\Promise\PromiseInterface;

interface Suspendable
{
    public function await(PromiseInterface $promise): mixed;
}
