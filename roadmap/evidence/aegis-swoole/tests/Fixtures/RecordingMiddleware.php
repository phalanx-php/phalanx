<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Scope\Scope;
use AegisSwoole\Service\ServiceTransformationMiddleware;
use Closure;

class RecordingMiddleware implements ServiceTransformationMiddleware
{
    /** @var list<string> */
    public array $order;

    /** @param list<string> $order shared trace buffer */
    public function __construct(public readonly string $tag, array &$order)
    {
        $this->order = &$order;
    }

    public function transform(string $type, Closure $next, Scope $scope): object
    {
        $this->order[] = "{$this->tag}:before";
        $instance = $next();
        $this->order[] = "{$this->tag}:after";
        return $instance;
    }
}
