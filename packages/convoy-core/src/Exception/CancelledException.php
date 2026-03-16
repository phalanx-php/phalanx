<?php

declare(strict_types=1);

namespace Convoy\Exception;

use Throwable;

class CancelledException extends \RuntimeException
{
    public function __construct(string $message = 'Operation cancelled', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
