<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Support;

use Phalanx\Archon\Console\Output\LiveRegionWriter;

final class RecordingLiveRegionWriter implements LiveRegionWriter
{
    /** @var list<list<string>> */
    public array $updates = [];

    /** @var list<list<string>> */
    public array $persists = [];

    public int $clearCount = 0;

    public function __construct(
        private readonly bool $tty = true,
    ) {
    }

    public function update(string ...$lines): void
    {
        $this->updates[] = self::frame(...$lines);
    }

    public function persist(string ...$lines): void
    {
        $this->persists[] = self::frame(...$lines);
    }

    public function clear(): void
    {
        $this->clearCount++;
    }

    public function isTty(): bool
    {
        return $this->tty;
    }

    /** @return list<string> */
    private static function frame(string ...$lines): array
    {
        return array_values($lines);
    }
}
