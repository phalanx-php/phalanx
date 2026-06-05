<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit;

use Phalanx\Application;
use Phalanx\Filesystem\FilePool;
use Phalanx\Filesystem\Files;
use Phalanx\Filesystem\Filesystem;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilesystemFacadeTest extends TestCase
{
    #[Test]
    public function servicesRegisterFilesFacadeAndPool(): void
    {
        $result = Application::starting()
            ->providers(Filesystem::services())
            ->run(Task::named(
                'test.filesystem.facade.services',
                static function (ExecutionScope $scope): array {
                    self::assertInstanceOf(Files::class, Filesystem::files($scope));
                    self::assertInstanceOf(FilePool::class, $scope->service(FilePool::class));

                    return ['ok' => true];
                },
            ));

        self::assertSame(['ok' => true], $result);
    }
}
