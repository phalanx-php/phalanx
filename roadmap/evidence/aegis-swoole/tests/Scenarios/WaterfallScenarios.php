<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Fixtures\DisposableService;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class WaterfallScenarios
{
    public function register(Harness $h): void
    {
        $h->add('waterfall.previous.value.via.attribute', function (ExecutionScope $scope): Result {
            $value = $scope->waterfall([
                static fn(): int => 1,
                static fn(ExecutionScope $s): int => $s->attribute('_waterfall_previous') + 10,
                static fn(ExecutionScope $s): int => $s->attribute('_waterfall_previous') * 3,
            ]);
            return Assertions::equals(33, $value) === null
                ? Result::pass()
                : Result::fail("got {$value}");
        });

        $h->add('waterfall.child.scope.isolation', function (ExecutionScope $scope): Result {
            $svcA = $scope->service(DisposableService::class);
            $observed = [];
            $scope->waterfall([
                static function (ExecutionScope $s) use (&$observed, $svcA): int {
                    // every waterfall step runs in executeFresh -> child scope -> fresh scoped instance
                    $observed[] = $s->service(DisposableService::class) !== $svcA;
                    return 1;
                },
                static function (ExecutionScope $s) use (&$observed, $svcA): int {
                    $observed[] = $s->service(DisposableService::class) !== $svcA;
                    return 2;
                },
            ]);
            return Assertions::arrayEquals([true, true], $observed) === null
                ? Result::pass()
                : Result::fail((string) json_encode($observed));
        });
    }
}
