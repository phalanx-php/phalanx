<?php

declare(strict_types=1);

namespace BgAgents\Repl\Handler;

use BgAgents\Memory\MemoryQuery;
use BgAgents\Memory\MemoryStore;
use BgAgents\Repl\ReplPrinter;
use Phalanx\ExecutionScope;

final readonly class MemoryHandler
{
    public function __construct(
        public MemoryStore $store,
        public ReplPrinter $printer,
    ) {}

    public function query(ExecutionScope $scope, string $topic): void
    {
        $records = $this->store->query($scope, new MemoryQuery(
            topics: $topic === '' ? [] : [$topic],
            limit: 12,
        ));

        if ($records === []) {
            $this->printer->info("no memory records matching '{$topic}'");
            return;
        }

        foreach ($records as $r) {
            $tags = $r->tags === [] ? '' : ' [' . implode(' ', $r->tags) . ']';
            $this->printer->kv($r->topic, $r->summary . $tags);
        }
    }
}
