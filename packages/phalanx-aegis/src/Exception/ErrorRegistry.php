<?php

declare(strict_types=1);

namespace Phalanx\Exception;

use Phalanx\Cancellation\AggregateException;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\Scope;
use Throwable;

/**
 * A central registry for exception reporters.
 *
 * This service allows Phalanx to unify error reporting across all substrates
 * (HTTP, CLI, background tasks) while isolating individual reporter failures.
 */
final class ErrorRegistry
{
    /** @var list<ErrorHandler> */
    private array $handlers = [];

    /** @param list<ErrorHandler> $handlers */
    public function __construct(array $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->register($handler);
        }
    }

    public function register(ErrorHandler $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * Reports an exception to all registered handlers.
     *
     * If the exception is an AggregateException (from concurrent tasks), it
     * will be unwrapped and each constituent error will be reported individually.
     */
    public function report(Scope $scope, Throwable $e): void
    {
        if ($e instanceof AggregateException) {
            foreach ($e->errors as $subError) {
                $this->reportSingle($scope, $subError);
            }

            return;
        }

        $this->reportSingle($scope, $e);
    }

    private function reportSingle(Scope $scope, Throwable $e): void
    {
        foreach ($this->handlers as $handler) {
            try {
                $handler->report($scope, $e);
            } catch (Cancelled $c) {
                throw $c;
            } catch (Throwable) {
            }
        }
    }
}
