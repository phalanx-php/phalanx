<?php

declare(strict_types=1);

namespace AegisSwoole\Service;

interface ServiceBundle
{
    /** @param array<string, mixed> $context */
    public function services(Services $services, array $context): void;
}
