<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Fixtures;

use Phalanx\Dory\Rendering\OutputSink;

final class BufferSink implements OutputSink
{
    /** @var list<string> */
    public array $lines = [];

    public function line(string $text = ''): void
    {
        $this->lines[] = $text;
    }
}
