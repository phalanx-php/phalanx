<?php

declare(strict_types=1);

namespace BgAgents\Repl\Handler;

use BgAgents\Repl\ReplPrinter;

final readonly class HelpHandler
{
    public function __construct(public ReplPrinter $printer) {}

    public function __invoke(): void
    {
        $this->printer->note('');
        $this->printer->banner('Available commands');
        $this->printer->kv('ask N "Q"', 'ask specialist N (or @addressing) the question Q');
        $this->printer->kv('list', 'list loaded specialists');
        $this->printer->kv('status', 'show team status (heartbeat, last activity)');
        $this->printer->kv('bookkeeper', 'list pending bookkeeper issues');
        $this->printer->kv('  bk accept N', 'accept bookkeeper proposal N');
        $this->printer->kv('  bk dismiss N', 'dismiss bookkeeper proposal N');
        $this->printer->kv('memory <topic>', 'search RAG memory by topic');
        $this->printer->kv('help', 'this help');
        $this->printer->kv('exit', 'leave the REPL');
        $this->printer->note('');
    }
}
