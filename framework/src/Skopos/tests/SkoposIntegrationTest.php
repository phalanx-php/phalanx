<?php

declare(strict_types=1);

namespace Phalanx\Skopos\Tests;

use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Skopos\FileWatcher;
use Phalanx\Skopos\ManagedProcess;
use Phalanx\Skopos\Output\Multiplexer;
use Phalanx\Skopos\Process;
use Phalanx\Skopos\ProcessState;
use Phalanx\Testing\PhalanxTestCase;

final class SkoposIntegrationTest extends PhalanxTestCase
{
    public function testManagedProcessReachesRunningOnReadinessMatch(): void
    {
        $script = 'echo "ready\n"; for ($i = 0; $i < 50; $i++) { usleep(20000); }';
        $config = Process::named('readiness-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg($script))
            ->ready('/ready/');

        $finalState = $this->scope->run(static function (ExecutionScope $scope) use ($config): ProcessState {
            $output = self::nullMultiplexer();
            $mp = new ManagedProcess($config);
            $mp->start($scope, $output);
            $mp->waitUntilReady($scope, timeout: 5.0);
            $state = $mp->state;
            $mp->stop(0.5, 1.0);
            $mp->waitUntilStopped($scope);
            return $state;
        });

        self::assertSame(ProcessState::Running, $finalState);
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
    }

    public function testManagedProcessReachesRunningOnStderrReadinessMatch(): void
    {
        $script = 'fwrite(STDERR, "ready\n"); for ($i = 0; $i < 50; $i++) { usleep(20000); }';
        $config = Process::named('stderr-readiness-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg($script))
            ->ready('/ready/');

        $finalState = $this->scope->run(static function (ExecutionScope $scope) use ($config): ProcessState {
            $output = self::nullMultiplexer();
            $mp = new ManagedProcess($config);
            $mp->start($scope, $output);
            $mp->waitUntilReady($scope, timeout: 5.0);
            $state = $mp->state;
            $mp->stop(0.5, 1.0);
            $mp->waitUntilStopped($scope);
            return $state;
        });

        self::assertSame(ProcessState::Running, $finalState);
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
    }

    public function testManagedProcessImmediateReadinessRunsWithoutPattern(): void
    {
        $script = 'for ($i = 0; $i < 50; $i++) { usleep(20000); }';
        $config = Process::named('immediate-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg($script));

        $state = $this->scope->run(static function (ExecutionScope $scope) use ($config): ProcessState {
            $output = self::nullMultiplexer();
            $mp = new ManagedProcess($config);
            $mp->start($scope, $output);
            $scope->delay(0.05);
            $observed = $mp->state;
            $mp->stop(0.5, 1.0);
            $mp->waitUntilStopped($scope);
            return $observed;
        });

        self::assertSame(ProcessState::Running, $state);
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
    }

    public function testManagedProcessCrashFiresCallback(): void
    {
        $config = Process::named('crash-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg('exit(2);'));

        $crashed = $this->scope->run(static function (ExecutionScope $scope) use ($config): bool {
            $output = self::nullMultiplexer();
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
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
    }

    public function testFileWatcherDetectsMtimeChange(): void
    {
        $tmpDir = self::tempDir();
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
            $scope->delay(0.2);

            touch($file, time() + 5);
            $scope->delay(0.3);
            $watcher->stop();

            return $captured;
        });

        self::assertContains($file, $changes);

        @unlink($file);
        @rmdir($tmpDir);
    }

    public function testManagedProcessRestartsCleanly(): void
    {
        $script = 'echo "ready\n"; for ($i = 0; $i < 50; $i++) { usleep(20000); }';
        $config = Process::named('restart-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg($script))
            ->ready('/ready/');

        $pids = $this->scope->run(static function (ExecutionScope $scope) use ($config): array {
            $output = self::nullMultiplexer();
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
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
    }

    public function testManagedProcessStopIsIdempotent(): void
    {
        $script = 'echo "ready\n"; for ($i = 0; $i < 50; $i++) { usleep(20000); }';
        $config = Process::named('idempotent-stop-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg($script))
            ->ready('/ready/');

        $stoppedState = $this->scope->run(static function (ExecutionScope $scope) use ($config): ProcessState {
            $output = self::nullMultiplexer();
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
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
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
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
    }

    public function testManagedProcessNaturalExitReleasesResourceBeforeScopeDisposal(): void
    {
        $config = Process::named('natural-exit-fixture')
            ->command(PHP_BINARY . ' -r ' . escapeshellarg('echo "done\n";'));

        [$state, $live] = $this->scope->run(static function (ExecutionScope $scope) use ($config): array {
            $output = self::nullMultiplexer();
            $mp = new ManagedProcess($config);
            $mp->start($scope, $output);
            $mp->waitUntilStopped($scope, timeout: 2.0);

            return [
                $mp->state,
                $scope->runtime->memory->resources->liveCount(AegisResourceSid::StreamingProcess),
            ];
        });

        self::assertSame(ProcessState::Crashed, $state);
        self::assertSame(0, $live);
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
    }

    public function testFileWatcherStopWithoutStartIsSafe(): void
    {
        $tmpDir = self::tempDir();

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

        @rmdir($tmpDir);

        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
    }

    public function testFileWatcherStopSuppressesLaterChanges(): void
    {
        $tmpDir = self::tempDir();
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
            $scope->delay(0.15);

            return $captured;
        });

        @unlink($file);
        @rmdir($tmpDir);

        self::assertSame([], $changes);
    }

    private static function nullMultiplexer(): Multiplexer
    {
        $stream = fopen('php://temp', 'wb+');
        if ($stream === false) {
            throw new \RuntimeException('php://temp unavailable');
        }
        return new Multiplexer($stream);
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'skopos-test-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        return $dir;
    }
}
