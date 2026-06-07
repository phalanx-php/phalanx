<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit;

use Phalanx\Filesystem\FilePool;
use Phalanx\Filesystem\Files;
use Phalanx\Filesystem\Filesystem;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class FilesystemTest extends PhalanxTestCase
{
    #[Test]
    public function servicesRegisterFilesAndPool(): void
    {
        $result = $this->testApp(bundles: Filesystem::services())
            ->scoped(Task::named(
                'test.filesystem.services',
                static function (ExecutionScope $scope): array {
                    self::assertInstanceOf(Files::class, Filesystem::files($scope));
                    self::assertInstanceOf(FilePool::class, $scope->service(FilePool::class));

                    return ['ok' => true];
                },
            ));

        self::assertSame(['ok' => true], $result);
    }
}
