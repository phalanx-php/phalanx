<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Tests\Unit;

use Phalanx\Application;
use Phalanx\Grammata\FilePool;
use Phalanx\Grammata\Files;
use Phalanx\Grammata\Grammata;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GrammataFacadeTest extends TestCase
{
    #[Test]
    public function servicesRegisterFilesFacadeAndPool(): void
    {
        $result = Application::starting()
            ->providers(Grammata::services())
            ->run(Task::named(
                'test.grammata.facade.services',
                static function (ExecutionScope $scope): array {
                    self::assertInstanceOf(Files::class, Grammata::files($scope));
                    self::assertInstanceOf(FilePool::class, $scope->service(FilePool::class));

                    return ['ok' => true];
                },
            ));

        self::assertSame(['ok' => true], $result);
    }
}
