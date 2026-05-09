<?php

declare(strict_types=1);

namespace Phalanx\Boot\Exception;

use Phalanx\Boot\BootHarnessReport;
use RuntimeException;

class CannotBootException extends RuntimeException
{
    public function __construct(private(set) BootHarnessReport $report)
    {
        parent::__construct(
            "Phalanx cannot boot: required configuration is missing or unreachable.\n\n" . $report->render(),
        );
    }
}
