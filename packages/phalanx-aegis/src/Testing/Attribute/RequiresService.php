<?php

declare(strict_types=1);

namespace Phalanx\Testing\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class RequiresService
{
    public function __construct(
        public readonly string $name,
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 0,
        public readonly float $timeout = 0.5,
    ) {}

    public function isAvailable(): bool
    {
        $conn = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if ($conn === false) {
            return false;
        }

        /** @phpstan-ignore wyrihaximus.reactphp.blocking.function.fclose */
        fclose($conn);

        return true;
    }

    public function skipMessage(): string
    {
        return "Service '{$this->name}' not available at {$this->host}:{$this->port}";
    }
}
