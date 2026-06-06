<?php

declare(strict_types=1);

namespace Phalanx\Tui;

use Phalanx\Boot\AppContext;
use Phalanx\Tui\Apps\Builder as TuiBuilder;
use Phalanx\Tui\Collab\Apps\Builder as CollabBuilder;

final class Tui
{
    /** @param array<string,mixed> $context */
    public static function app(array $context = []): TuiBuilder
    {
        return new TuiBuilder(AppContext::fromProject($context));
    }

    /** @param array<string,mixed> $context */
    public static function collab(array $context = []): CollabBuilder
    {
        return new CollabBuilder(AppContext::fromProject($context));
    }
}
