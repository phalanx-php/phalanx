<?php

declare(strict_types=1);

namespace BgAgents\Repl\Handler;

use BgAgents\Repl\ReplPrinter;
use BgAgents\Specialist\SpecialistRegistry;

final readonly class ListSpecialistsHandler
{
    public function __construct(
        public SpecialistRegistry $registry,
        public ReplPrinter $printer,
    ) {}

    public function __invoke(): void
    {
        $specs = $this->registry->all();
        if ($specs === []) {
            $this->printer->warn('no specialists loaded');
            return;
        }

        $this->printer->note('');
        foreach ($specs as $spec) {
            $addr = $spec->addressing === [] ? '' : ' (' . implode(' ', $spec->addressing) . ')';
            $this->printer->kv($spec->name . $addr, "{$spec->provider}/{$spec->model} — {$spec->description}");
        }
        $this->printer->note('');
    }
}
