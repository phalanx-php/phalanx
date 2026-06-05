<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Exception;

class FilesystemException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $path = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
