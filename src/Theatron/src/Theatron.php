<?php

declare(strict_types=1);

namespace Phalanx\Theatron;

use Phalanx\Boot\AppContext;
use Phalanx\Theatron\Tui\Apps\TheatronBuilder;

final class Theatron
{
    /** @param array<string,mixed> $context */
    public static function app(array $context = []): TheatronBuilder
    {
        return new TheatronBuilder(new AppContext($context));
    }
}
