<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TransactionScope;
use Phalanx\Supervisor\TransactionLease;

final class TransactionCallbackScopeFixture
{
    public function __invoke(ExecutionScope $scope, TransactionLease $lease): void
    {
        $scope->transaction($lease, static function (TransactionScope $tx): void {
        });
        $scope->transaction($lease, static function (ExecutionScope $tx): void {
        });
    }
}
