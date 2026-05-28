<?php

declare(strict_types=1);

namespace BgAgents\Command;

use BgAgents\Config\BgAgentsConfig;
use BgAgents\Daemon8\BgEvent;
use BgAgents\Specialist\InvokeSpecialist;
use BgAgents\Specialist\SpecialistRegistry;
use Phalanx\Archon\CommandContext;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final class AskCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        assert($scope instanceof CommandContext);

        $name = (string) $scope->args->get('specialist');
        $query = (string) $scope->args->get('query');

        if ($name === '' || $query === '') {
            fwrite(STDERR, "usage: bg-agents ask <specialist> \"<query>\"\n");
            return 2;
        }

        $registry = $scope->service(SpecialistRegistry::class);
        $specialist = $registry->resolve($name);
        if ($specialist === null) {
            $known = implode(', ', $registry->names());
            fwrite(STDERR, "unknown specialist: {$name}\nknown: {$known}\n");
            return 1;
        }

        $config = $scope->service(BgAgentsConfig::class);

        try {
            $response = $scope->execute(new InvokeSpecialist(
                specialist: $specialist,
                prompt: $query,
                eventKind: BgEvent::AGENT_FINAL_ANSWER,
                traceId: bin2hex(random_bytes(6)),
            ));
        } catch (\Throwable $e) {
            fwrite(STDERR, "ask failed: {$e->getMessage()}\n");
            if ($config->verbose) {
                fwrite(STDERR, $e->getTraceAsString() . "\n");
            }
            return 1;
        }

        fwrite(STDOUT, $response->text . "\n");

        if ($config->verbose) {
            fwrite(STDERR, sprintf(
                "\n[%s via %s/%s — in:%d out:%d steps:%d %.0fms]\n",
                $response->from,
                $response->provider,
                $response->model,
                $response->tokensIn,
                $response->tokensOut,
                $response->steps,
                $response->latencyMs,
            ));
        }

        return 0;
    }
}
