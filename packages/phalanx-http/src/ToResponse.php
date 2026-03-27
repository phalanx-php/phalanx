<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Psr\Http\Message\ResponseInterface;

interface ToResponse
{
    public function toResponse(): ResponseInterface;
}
