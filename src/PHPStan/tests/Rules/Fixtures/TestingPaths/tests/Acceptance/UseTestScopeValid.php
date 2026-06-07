<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance;

use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;

final class UseTestScopeValid extends PhalanxTestCase
{
    public function managedScopeRun(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $scope->delay(Mark::ms(1));
        });
    }

    public function helperNamedCreateScope(): void
    {
        $scope = $this->createScope(['page' => '1']);
    }

    /** @param array<string, string> $query */
    private function createScope(array $query): FixtureExecutionContext
    {
        return new FixtureExecutionContext($query);
    }
}

final class FixtureExecutionContext
{
    /** @param array<string, string> $query */
    public function __construct(public array $query)
    {
    }
}
