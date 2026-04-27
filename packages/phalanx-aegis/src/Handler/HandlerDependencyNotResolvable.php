<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use RuntimeException;

/**
 * Thrown when HandlerResolver cannot resolve a constructor parameter for a
 * handler class. The user must register every dependency in the service
 * container -- there are no fallbacks. Scalar parameters and untyped
 * parameters are programming errors that surface here at first dispatch.
 */
final class HandlerDependencyNotResolvable extends RuntimeException
{
    public function __construct(
        public readonly string $handlerClass,
        public readonly string $parameterName,
        public readonly ?string $parameterType,
        ?string $reason = null,
    ) {
        $type = $parameterType ?? 'mixed/untyped';
        $msg = "Cannot resolve dependency \${$parameterName} ({$type}) for handler {$handlerClass}";
        if ($reason !== null) {
            $msg .= ": {$reason}";
        }
        parent::__construct($msg);
    }
}
