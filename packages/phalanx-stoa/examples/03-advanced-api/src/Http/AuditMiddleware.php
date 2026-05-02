<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Advanced\Http;

use Acme\StoaDemo\Advanced\Services\AuditLog;
use Closure;
use Phalanx\Stoa\Contract\Middleware;
use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final class AuditMiddleware implements Executable, Middleware
{
    public function __construct(private readonly AuditLog $audit) {}

    public function handle(RequestScope $scope, Closure $next): mixed
    {
        $this->audit->record($scope->method(), $scope->path());

        return $next($scope);
    }

    public function __invoke(RequestScope $scope): mixed
    {
        /** @var Scopeable|Executable $next */
        $next = $scope->attribute('handler.next');

        return $this->handle(
            $scope,
            static function (RequestScope $nextScope) use ($next): mixed {
                return $next($nextScope);
            },
        );
    }
}
