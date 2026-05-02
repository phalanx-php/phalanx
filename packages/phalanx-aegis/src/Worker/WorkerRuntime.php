<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use OpenSwoole\Coroutine;
use Phalanx\Application;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Worker\Protocol\Response;
use Throwable;

/**
 * Child-process main loop. Reads TaskRequest frames from STDIN, deserializes
 * the task, runs __invoke() inside a fresh in-process Application/Scope, and
 * writes the Response back to STDOUT.
 *
 * Each request runs in its own coroutine so multiple in-flight tasks within a
 * single worker can interleave I/O.
 *
 * Service proxy back to the parent is NOT in this POC slice — `$scope->service()`
 * inside a worker task will throw "No service registered". This is documented
 * in IMPL-DIFFS.md.
 */
class WorkerRuntime
{
    public static function run(string $autoloadPath): void
    {
        if ($autoloadPath !== '' && file_exists($autoloadPath)) {
            require $autoloadPath;
        }

        RuntimeHooks::ensure(RuntimePolicy::phalanxManaged());

        Coroutine::run(static function (): void {
            $emptyBundle = new class implements ServiceBundle {
                public function services(Services $services, array $context): void
                {
                }
            };
            $app = Application::starting([])->providers($emptyBundle)->compile()->startup();

            // Switch STDIN to non-blocking and use stream_select to yield to the
            // scheduler. Mirrors the parent-side ProcessHandle::readLine pattern:
            // STDIO hooks are intentionally outside the default managed runtime
            // baseline until console/TTY semantics are proven.
            stream_set_blocking(STDIN, false);
            $buffer = '';
            while (true) {
                $r = [STDIN];
                $w = null;
                $e = null;
                $ready = @stream_select($r, $w, $e, 1, 0);
                if ($ready === false) {
                    break;
                }
                if ($ready === 0) {
                    if (feof(STDIN)) {
                        break;
                    }
                    continue;
                }
                $chunk = fread(STDIN, 8192);
                if ($chunk === false || ($chunk === '' && feof(STDIN))) {
                    break;
                }
                if ($chunk === '') {
                    continue;
                }
                $buffer .= $chunk;
                while (($newlinePos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newlinePos);
                    $buffer = substr($buffer, $newlinePos + 1);
                    if (trim($line) === '') {
                        continue;
                    }
                    Coroutine::create(static function () use ($line, $app): void {
                        self::handleLine($line, $app);
                    });
                }
            }

            $app->shutdown();
        });
    }

    private static function handleLine(string $line, Application $app): void
    {
        try {
            $req = Codec::decodeRequest($line);
        } catch (Throwable $e) {
            self::writeResponse(Response::err('', $e));
            return;
        }

        $scope = $app->createScope();
        try {
            $task = unserialize($req->serializedTask);
            if (!$task instanceof \Phalanx\Task\Scopeable && !$task instanceof \Phalanx\Task\Executable) {
                throw new \RuntimeException('worker: payload is not Scopeable|Executable');
            }
            $result = $scope->execute($task);
            self::writeResponse(Response::ok($req->id, $result));
        } catch (Throwable $e) {
            self::writeResponse(Response::err($req->id, $e));
        } finally {
            $scope->dispose();
        }
    }

    private static function writeResponse(Response $resp): void
    {
        fwrite(STDOUT, Codec::encodeResponse($resp));
        fflush(STDOUT);
    }
}
