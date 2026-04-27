<?php

declare(strict_types=1);

namespace Phalanx\Testing\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class RequiresDaemon8 extends RequiresService
{
    public function __construct()
    {
        parent::__construct(name: 'daemon8', host: '127.0.0.1', port: 9077);
    }
}
