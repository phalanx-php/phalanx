<?php

declare(strict_types=1);

namespace Phalanx\Tui;

use Phalanx\Boot\AppContext;
use Phalanx\Tui\Apps\Builder as TuiBuilder;
use Phalanx\Tui\Runtime\Apps\Builder as RuntimeBuilder;

final class Tui
{
    private function __construct()
    {
    }

    /** @param array<string,mixed> $context */
    public static function app(array $context = []): TuiBuilder
    {
        return new TuiBuilder(AppContext::fromProject($context));
    }

    /** @param array<string,mixed> $context */
    public static function starting(array $context = []): RuntimeBuilder
    {
        return new RuntimeBuilder(AppContext::fromProject($context));
    }
}
