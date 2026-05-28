<?php

declare(strict_types=1);

namespace BgAgents\Repl\Handler;

use BgAgents\Daemon8\BgEvent;
use BgAgents\Repl\Cmd\AskCmd;
use BgAgents\Repl\ReplPrinter;
use BgAgents\Specialist\InvokeSpecialist;
use BgAgents\Specialist\SpecialistRegistry;
use Phalanx\ExecutionScope;

final readonly class AskHandler
{
    public function __construct(
        public SpecialistRegistry $registry,
        public ReplPrinter $printer,
    ) {}

    public function handle(AskCmd $cmd, ExecutionScope $scope): void
    {
        $spec = $this->registry->resolve($cmd->specialist);
        if ($spec === null) {
            $known = implode(', ', $this->registry->names());
            $this->printer->error("unknown specialist '{$cmd->specialist}'; known: {$known}");
            return;
        }

        try {
            $response = $scope->execute(new InvokeSpecialist(
                specialist: $spec,
                prompt: $cmd->query,
                eventKind: BgEvent::AGENT_FINAL_ANSWER,
                traceId: bin2hex(random_bytes(6)),
            ));
        } catch (\Throwable $e) {
            $this->printer->error("ask failed: {$e->getMessage()}");
            return;
        }

        $this->printer->answer($response->from, $response->text);
    }
}
