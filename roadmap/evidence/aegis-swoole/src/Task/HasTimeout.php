<?php

declare(strict_types=1);

namespace AegisSwoole\Task;

/**
 * Behavioral interface declaring this task wants the timeout middleware to
 * wrap its execution with $scope->timeout($seconds, ...).
 */
interface HasTimeout
{
    public function timeoutSeconds(): float;
}
