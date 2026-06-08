<?php

declare(strict_types=1);

namespace Phalanx\DevServer\Tests;

use Phalanx\Mark\Mark;
use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\DevServer\FileWatcher;
use Phalanx\DevServer\ManagedProcess;
use Phalanx\DevServer\Output\Multiplexer;
use Phalanx\DevServer\Process;
use Phalanx\DevServer\ProcessState;
use Phalanx\Stream\ResourceHandle;
use Phalanx\Stream\Stream;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\After;

final class DevServerIntegrationTest extends PhalanxTestCase
{
    /** @var list<ResourceHandle> */
    private array $streams = [];

    public function testManagedProcessReachesRunningOnReadinessMatch(): void
    {
        $script = 'echo "ready\n"; for ($i = 0; $i < 50; $i++) { usleep(20000); }';
        $config = Process::named('readiness-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg($script))
            ->ready('/ready/');
        $output = $this->nullMultiplexer();

        $finalState = $this->scope->run(static function (ExecutionScope $scope) use ($config, $output): ProcessState {
            $mp = new ManagedProcess($config);
            $mp->start($scope, $output);
            $mp->waitUntilReady($scope, timeout: 5.0);
            $state = $mp->state;
            $mp->stop(0.5, 1.0);
            $mp->waitUntilStopped($scope);
            return $state;
        });

        self::assertSame(ProcessState::Running, $finalState);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    public function testManagedProcessReachesRunningOnStderrReadinessMatch(): void
    {
        $script = 'fwrite(STDERR, "ready\n"); for ($i = 0; $i < 50; $i++) { usleep(20000); }';
        $config = Process::named('stderr-readiness-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg($script))
            ->ready('/ready/');
        $output = $this->nullMultiplexer();

        $finalState = $this->scope->run(static function (ExecutionScope $scope) use ($config, $output): ProcessState {
            $mp = new ManagedProcess($config);
            $mp->start($scope, $output);
            $mp->waitUntilReady($scope, timeout: 5.0);
            $state = $mp->state;
            $mp->stop(0.5, 1.0);
            $mp->waitUntilStopped($scope);
            return $state;
        });

        self::assertSame(ProcessState::Running, $finalState);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    public function testManagedProcessImmediateReadinessRunsWithoutPattern(): void
    {
        $script = 'for ($i = 0; $i < 50; $i++) { usleep(20000); }';
        $config = Process::named('immediate-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg($script));
        $output = $this->nullMultiplexer();

        $state = $this->scope->run(static function (ExecutionScope $scope) use ($config, $output): ProcessState {
            $mp = new ManagedProcess($config);
            $mp->start($scope, $output);
            $scope->delay(Mark::ms(50));
            $observed = $mp->state;
            $mp->stop(0.5, 1.0);
            $mp->waitUntilStopped($scope);
            return $observed;
        });

        self::assertSame(ProcessState::Running, $state);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    public function testManagedProcessCrashFiresCallback(): void
    {
        $config = Process::named('crash-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg('exit(2);'));
        $output = $this->nullMultiplexer();

        $crashed = $this->scope->run(static function (ExecutionScope $scope) use ($config, $output): bool {
            $mp = new ManagedProcess($config);
            $captured = false;
            $mp->onCrash(static function () use (&$captured): void {
                $captured = true;
            });
            $mp->start($scope, $output);
            $mp->waitUntilStopped($scope, timeout: 2.0);

            return $captured;
        });

        self::assertTrue($crashed);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    public function testFileWatcherDetectsMtimeChange(): void
    {
        $tmpDir = $this->tempDir();
        $file = $tmpDir . '/probe.php';
        file_put_contents($file, "<?php\n\$probe = 1;\n");

        $changes = $this->scope->run(static function (ExecutionScope $scope) use ($tmpDir, $file): array {
            $captured = [];
            $watcher = new FileWatcher(
                paths: [$tmpDir],
                extensions: ['php'],
                onChange: static function (array $changed) use (&$captured): void {
                    foreach ($changed as $c) {
                        $captured[] = $c;
                    }
                },
                interval: 0.1,
            );
            $watcher->start($scope);
            $scope->delay(Mark::ms(200));

            touch($file, time() + 5);
            $scope->delay(Mark::ms(300));
            $watcher->stop();

            return $captured;
        });

        self::assertContains($file, $changes);
    }

    public function testManagedProcessRestartsCleanly(): void
    {
        $script = 'echo "ready\n"; for ($i = 0; $i < 50; $i++) { usleep(20000); }';
        $config = Process::named('restart-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg($script))
            ->ready('/ready/');
        $output = $this->nullMultiplexer();

        $pids = $this->scope->run(static function (ExecutionScope $scope) use ($config, $output): array {
            $mp = new ManagedProcess($config);
            $mp->start($scope, $output);
            $mp->waitUntilReady($scope, timeout: 5.0);
            $first = $mp->pid;

            $mp->restart($scope, $output);
            $mp->waitUntilReady($scope, timeout: 5.0);
            $second = $mp->pid;

            $mp->stop(0.5, 1.0);
            $mp->waitUntilStopped($scope);

            return [$first, $second];
        });

        self::assertNotNull($pids[0]);
        self::assertNotNull($pids[1]);
        self::assertNotSame($pids[0], $pids[1]);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    public function testManagedProcessStopIsIdempotent(): void
    {
        $script = 'echo "ready\n"; for ($i = 0; $i < 50; $i++) { usleep(20000); }';
        $config = Process::named('idempotent-stop-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg($script))
            ->ready('/ready/');
        $output = $this->nullMultiplexer();

        $stoppedState = $this->scope->run(static function (ExecutionScope $scope) use ($config, $output): ProcessState {
            $mp = new ManagedProcess($config);
            $mp->start($scope, $output);
            $mp->waitUntilReady($scope, timeout: 5.0);

            $mp->stop(0.5, 1.0);
            $mp->stop(0.5, 1.0);
            $mp->stop(0.5, 1.0);

            $mp->waitUntilStopped($scope);

            return $mp->state;
        });

        self::assertSame(ProcessState::Stopped, $stoppedState);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    public function testManagedProcessStopBeforeStartIsSafe(): void
    {
        $config = Process::named('stop-before-start-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg('echo "ready\n";'));

        $state = $this->scope->run(static function () use ($config): ProcessState {
            $mp = new ManagedProcess($config);
            $mp->stop(0.5, 1.0);
            return $mp->state;
        });

        self::assertSame(ProcessState::Stopped, $state);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    public function testManagedProcessNaturalExitReleasesResourceBeforeScopeDisposal(): void
    {
        $config = Process::named('natural-exit-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg('echo "done\n";'));
        $output = $this->nullMultiplexer();

        [$state, $live] = $this->scope->run(static function (ExecutionScope $scope) use ($config, $output): array {
            $mp = new ManagedProcess($config);
            $mp->start($scope, $output);
            $mp->waitUntilStopped($scope, timeout: 2.0);

            return [
                $mp->state,
                $scope->runtime->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess),
            ];
        });

        self::assertSame(ProcessState::Crashed, $state);
        self::assertSame(0, $live);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    public function testFileWatcherStopWithoutStartIsSafe(): void
    {
        $tmpDir = $this->tempDir();

        $this->scope->run(static function () use ($tmpDir): void {
            $watcher = new FileWatcher(
                paths: [$tmpDir],
                extensions: ['php'],
                onChange: static function (): void {
                },
                interval: 1.0,
            );
            $watcher->stop();
            $watcher->stop();
        });

        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    public function testFileWatcherStopSuppressesLaterChanges(): void
    {
        $tmpDir = $this->tempDir();
        $file = $tmpDir . '/probe.php';
        file_put_contents($file, "<?php\n\$probe = 1;\n");

        $changes = $this->scope->run(static function (ExecutionScope $scope) use ($tmpDir, $file): array {
            $captured = [];
            $watcher = new FileWatcher(
                paths: [$tmpDir],
                extensions: ['php'],
                onChange: static function (array $changed) use (&$captured): void {
                    foreach ($changed as $c) {
                        $captured[] = $c;
                    }
                },
                interval: 0.05,
            );
            $watcher->start($scope);
            $watcher->stop();

            touch($file, time() + 5);
            $scope->delay(Mark::ms(150));

            return $captured;
        });

        self::assertSame([], $changes);
    }

    #[After]
    protected function closeStreams(): void
    {
        foreach ($this->streams as $stream) {
            $stream->close();
        }

        $this->streams = [];
    }

    private function nullMultiplexer(): Multiplexer
    {
        $stream = $this->streams[] = Stream::captureBuffer();

        return new Multiplexer($stream->resource());
    }

    private function tempDir(): string
    {
        return $this->tempWorkspace('dev-server-test-')->dir(bin2hex(random_bytes(4)));
    }
}
