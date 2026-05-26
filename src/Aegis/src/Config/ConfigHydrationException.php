<?php

declare(strict_types=1);

namespace Phalanx\Config;

use RuntimeException;

final class ConfigHydrationException extends RuntimeException
{
    /** @param list<Issue> $issues */
    public function __construct(public readonly array $issues)
    {
        parent::__construct($issues[0]->message ?? 'Config hydration failed.');
    }
}
