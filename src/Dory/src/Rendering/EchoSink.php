<?php

declare(strict_types=1);

namespace Phalanx\Dory\Rendering;

final class EchoSink implements OutputSink
{
    public function line(string $text = ''): void
    {
        echo $text . "\n";
    }
}
