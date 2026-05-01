<?php

declare(strict_types=1);

namespace Phalanx\Worker;

class WorkerSupervisor
{
    /** @var list<Worker> */
    private array $workers = [];

    private int $rrCursor = 0;

    public function __construct(public readonly ParallelConfig $config)
    {
    }

    public function start(): void
    {
        if ($this->workers !== []) {
            return;
        }
        for ($i = 0; $i < $this->config->agents; $i++) {
            $worker = new Worker(
                process: new ProcessHandle($this->config->workerScript, $this->config->autoloadPath),
                mailbox: new Mailbox($this->config->mailboxLimit),
            );
            $worker->start();
            $this->workers[] = $worker;
        }
    }

    public function pick(): Worker
    {
        if ($this->workers === []) {
            $this->start();
        }
        return match ($this->config->strategy) {
            DispatchStrategy::RoundRobin => $this->roundRobin(),
            DispatchStrategy::LeastMailbox => $this->leastMailbox(),
        };
    }

    public function shutdown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop();
        }
        $this->workers = [];
    }

    /** @return list<Worker> */
    public function workers(): array
    {
        return $this->workers;
    }

    private function roundRobin(): Worker
    {
        $worker = $this->workers[$this->rrCursor];
        $this->rrCursor = ($this->rrCursor + 1) % count($this->workers);
        return $worker;
    }

    private function leastMailbox(): Worker
    {
        $best = $this->workers[0];
        $bestDepth = $best->depth();
        foreach ($this->workers as $w) {
            $d = $w->depth();
            if ($d < $bestDepth) {
                $best = $w;
                $bestDepth = $d;
            }
        }
        return $best;
    }
}
