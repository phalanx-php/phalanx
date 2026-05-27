<?php

declare(strict_types=1);

namespace Phalanx\Dory;

use Phalanx\AppHost;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Dory\Rendering\OutputSink;
use Phalanx\Dory\Rendering\ValueRendererPipeline;
use Throwable;

final class DoryApplication
{
    public function __construct(
        private AppHost $host,
        private ?string $scriptPath,
    ) {
    }

    public function run(): int
    {
        if ($this->scriptPath === null) {
            fwrite(STDERR, "No script path provided.\n");
            return 1;
        }

        if (!file_exists($this->scriptPath)) {
            fwrite(STDERR, "Script not found: {$this->scriptPath}\n");
            return 1;
        }

        $this->host->startup();

        try {
            $scope = $this->host->createScope();

            try {
                $config = $scope->service(DoryConfig::class);

                $context = new DoryExecutionContext(
                    inner: $scope,
                    scriptPath: realpath($this->scriptPath) ?: $this->scriptPath,
                    config: $config,
                );

                $result = $scope->timeout($config->scriptTimeout, static function () use ($context): mixed {
                    return ScriptRunner::execute($context);
                });

                if (is_int($result)) {
                    return $result;
                }

                if ($result !== null) {
                    $pipeline = $scope->service(ValueRendererPipeline::class);
                    $sink = $scope->service(OutputSink::class);
                    $pipeline->render($result, $sink);
                }

                return 0;
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                fwrite(STDERR, $e->getMessage() . "\n");
                return 1;
            } finally {
                $scope->dispose();
            }
        } finally {
            $this->host->shutdown();
        }
    }
}
