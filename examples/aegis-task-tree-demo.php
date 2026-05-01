<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\PoolLease;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\TaskTreeFormatter;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Executable;
use Phalanx\Trace\Trace;

final class DemoTask implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        return null;
    }
}

final class DemoScope implements Scope
{
    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function service(string $type): object
    {
        throw new RuntimeException('DemoScope does not resolve services.');
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function withAttribute(string $key, mixed $value): Scope
    {
        return $this;
    }

    public function trace(): Trace
    {
        return new Trace();
    }
}

$supervisor = new Supervisor(new InProcessLedger(), new Trace());
$scope = new DemoScope();

$root = $supervisor->start(
    new DemoTask(),
    $scope,
    DispatchMode::Inline,
    'AppHandler::handle',
);
$supervisor->markRunning($root);

$fetchUser = $supervisor->start(
    new DemoTask(),
    $scope,
    DispatchMode::Concurrent,
    'FetchUser(7)',
    $root->id,
);
$supervisor->markRunning($fetchUser);
$supervisor->beginWait(
    $fetchUser,
    WaitReason::postgres('SELECT * FROM users WHERE id = $1'),
);

$auditWrite = $supervisor->start(
    new DemoTask(),
    $scope,
    DispatchMode::Concurrent,
    'AuditWrite(login)',
    $root->id,
);
$supervisor->markRunning($auditWrite);
$supervisor->registerLease(
    $auditWrite,
    PoolLease::open('redis/cache', '3'),
);

$flushBuffer = $supervisor->start(
    new DemoTask(),
    $scope,
    DispatchMode::Concurrent,
    'FlushBuffer',
    $auditWrite->id,
);
$supervisor->markRunning($flushBuffer);

usleep(15_000);

echo (new TaskTreeFormatter())->format($supervisor->tree());
