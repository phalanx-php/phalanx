<?php

declare(strict_types=1);

namespace Phalanx\Themis;

use RuntimeException;

final class ConfigHydrationException extends RuntimeException
{
    /** @param list<Issue> $issues */
    public function __construct(private(set) array $issues)
    {
        parent::__construct($issues[0]->message);
    }
}
