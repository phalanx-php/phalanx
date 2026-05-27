<?php

declare(strict_types=1);

namespace Phalanx\Dory\Rendering;

interface ValueRenderer
{
    public function supports(mixed $value): bool;

    public function render(mixed $value, OutputSink $output): void;
}
