<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Output;

/**
 * Retained renderer for one bounded terminal live region.
 *
 * Widgets hand it complete frames; it owns duplicate suppression, clearing,
 * and the transition from transient live output to durable settled output.
 */
class LiveRegionRenderer
{
    /** @var list<string>|null */
    private ?array $frame = null;

    public function __construct(private readonly LiveRegionWriter $writer)
    {
    }

    public function update(string ...$lines): void
    {
        $frame = self::frame(...$lines);

        if ($this->frame === $frame) {
            return;
        }

        $this->frame = $frame;
        $this->writer->update(...$frame);
    }

    public function settle(string ...$lines): void
    {
        $this->persist(...$lines);
    }

    public function persist(string ...$lines): void
    {
        $this->frame = null;
        $this->writer->persist(...$lines);
    }

    public function clear(): void
    {
        $this->frame = null;
        $this->writer->clear();
    }

    public function isTty(): bool
    {
        return $this->writer->isTty();
    }

    /** @return list<string> */
    private static function frame(string ...$lines): array
    {
        return array_values($lines);
    }
}
