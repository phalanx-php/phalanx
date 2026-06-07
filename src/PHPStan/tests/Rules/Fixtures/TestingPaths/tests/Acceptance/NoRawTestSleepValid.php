<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance;

use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;

final class NoRawTestSleepValid extends PhalanxTestCase
{
    public function managedDelay(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $scope->delay(Mark::ms(1));
        });
    }

    public function subprocessFixtureString(): string
    {
        return '<?php sleep(1); usleep(1000);';
    }
}
