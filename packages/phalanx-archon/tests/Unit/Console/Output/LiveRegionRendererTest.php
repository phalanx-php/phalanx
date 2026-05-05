<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Console\Output;

use Phalanx\Archon\Console\Output\LiveRegionRenderer;
use Phalanx\Archon\Console\Output\LiveRegionWriter;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LiveRegionRendererTest extends TestCase
{
    #[Test]
    public function updateSuppressesDuplicateFrames(): void
    {
        $writer = new SpyLiveRegionWriter();
        $renderer = new LiveRegionRenderer($writer);

        $renderer->update('one');
        $renderer->update('one');
        $renderer->update('two');

        self::assertSame([['one'], ['two']], $writer->updates);
    }

    #[Test]
    public function settlePersistsAndResetsRetainedFrame(): void
    {
        $writer = new SpyLiveRegionWriter();
        $renderer = new LiveRegionRenderer($writer);

        $renderer->update('working');
        $renderer->settle('done');
        $renderer->update('working');

        self::assertSame([['working'], ['working']], $writer->updates);
        self::assertSame([['done']], $writer->persists);
    }

    #[Test]
    public function persistWritesDurableOutputAndResetsRetainedFrame(): void
    {
        $writer = new SpyLiveRegionWriter();
        $renderer = new LiveRegionRenderer($writer);

        $renderer->update('working');
        $renderer->persist('checkpoint');
        $renderer->update('working');

        self::assertSame([['working'], ['working']], $writer->updates);
        self::assertSame([['checkpoint']], $writer->persists);
    }

    #[Test]
    public function clearResetsRetainedFrame(): void
    {
        $writer = new SpyLiveRegionWriter();
        $renderer = new LiveRegionRenderer($writer);

        $renderer->update('same');
        $renderer->clear();
        $renderer->update('same');

        self::assertSame([['same'], ['same']], $writer->updates);
        self::assertSame(1, $writer->clearCount);
    }

    #[Test]
    public function ttyStateComesFromWriter(): void
    {
        self::assertTrue(new LiveRegionRenderer(new SpyLiveRegionWriter(true))->isTty());
        self::assertFalse(new LiveRegionRenderer(new SpyLiveRegionWriter(false))->isTty());
    }

    #[Test]
    public function nonTtyStreamOutputKeepsOnlySettledOutput(): void
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Unable to open memory stream.');
        }

        $renderer = new LiveRegionRenderer(new StreamOutput(
            $stream,
            new TerminalEnvironment(columns: 80, lines: 24),
        ));

        try {
            $renderer->update('frame 1');
            $renderer->update('frame 2');
            $renderer->settle('done');
            rewind($stream);

            self::assertSame("done\n", stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }
}

final class SpyLiveRegionWriter implements LiveRegionWriter
{
    /** @var list<list<string>> */
    public array $updates = [];

    /** @var list<list<string>> */
    public array $persists = [];

    public int $clearCount = 0;

    public function __construct(private readonly bool $tty = true)
    {
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
