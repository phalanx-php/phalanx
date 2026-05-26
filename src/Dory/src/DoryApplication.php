<?php

declare(strict_types=1);

namespace Phalanx\Dory;

use Phalanx\AppHost;
use Phalanx\Cancellation\Cancelled;
use Throwable;

final class DoryApplication
{
    public function __construct(
        private AppHost $host,
        private(set) ?string $scriptPath,
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

                $dory = new ScriptContext(
                    inner: $scope,
                    scriptPath: realpath($this->scriptPath) ?: $this->scriptPath,
                    config: $config,
                );

                $result = $scope->timeout($config->scriptTimeout, static function () use ($dory): mixed {
                    return ScriptRunner::execute($dory);
                });

                return is_int($result) ? $result : 0;
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
