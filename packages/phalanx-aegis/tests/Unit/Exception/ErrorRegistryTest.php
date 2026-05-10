<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Exception;

use Phalanx\Cancellation\AggregateException;
use Phalanx\Exception\ErrorHandler;
use Phalanx\Exception\ErrorRegistry;
use Phalanx\Scope\Scope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorRegistryTest extends TestCase
{
    public function testReportsToRegisteredHandlers(): void
    {
        $scope = $this->createMock(Scope::class);
        $exception = new RuntimeException('test');

        $handler1 = $this->createMock(ErrorHandler::class);
        $handler1->expects($this->once())
            ->method('report')
            ->with($scope, $exception);

        $handler2 = $this->createMock(ErrorHandler::class);
        $handler2->expects($this->once())
            ->method('report')
            ->with($scope, $exception);

        $registry = new ErrorRegistry([$handler1, $handler2]);
        $registry->report($scope, $exception);
    }

    public function testUnwrapsAggregateException(): void
    {
        $scope = $this->createMock(Scope::class);
        $error1 = new RuntimeException('error 1');
        $error2 = new RuntimeException('error 2');
        $aggregate = new AggregateException(['a' => $error1, 'b' => $error2]);

        $reportedErrors = [];
        $handler = $this->createMock(ErrorHandler::class);
        $handler->method('report')
            ->willReturnCallback(function (Scope $s, \Throwable $e) use ($scope, &$reportedErrors) {
                $this->assertSame($scope, $s);
                $reportedErrors[] = $e;
            });

        $registry = new ErrorRegistry([$handler]);
        $registry->report($scope, $aggregate);

        $this->assertCount(2, $reportedErrors);
        $this->assertSame($error1, $reportedErrors[0]);
        $this->assertSame($error2, $reportedErrors[1]);
    }

    public function testIsolatesReporterFailures(): void
    {
        $scope = $this->createMock(Scope::class);
        $exception = new RuntimeException('test');

        $handler1 = $this->createMock(ErrorHandler::class);
        $handler1->expects($this->once())
            ->method('report')
            ->willThrowException(new RuntimeException('reporter failure'));

        $handler2 = $this->createMock(ErrorHandler::class);
        $handler2->expects($this->once())
            ->method('report')
            ->with($scope, $exception);

        $registry = new ErrorRegistry([$handler1, $handler2]);
        
        // Should not throw
        $registry->report($scope, $exception);
    }
}
