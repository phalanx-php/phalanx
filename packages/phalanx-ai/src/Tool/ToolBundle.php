<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tool;

interface ToolBundle
{
    /** @return list<class-string<Tool>> */
    public function tools(): array;
}
