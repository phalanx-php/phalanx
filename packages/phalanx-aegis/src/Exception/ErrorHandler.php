<?php

declare(strict_types=1);

namespace Phalanx\Exception;

use Phalanx\Scope\Scope;
use Throwable;

/**
 * Contract for reporting an exception to an external system (e.g., Sentry, CloudWatch).
 */
interface ErrorHandler
{
    /**
     * Reports an exception to an external system.
     *
     * @param Scope $scope The scope in which the error occurred.
     * @param Throwable $e The exception to report.
     */
    public function report(Scope $scope, Throwable $e): void;
}
