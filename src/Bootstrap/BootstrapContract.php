<?php

declare(strict_types=1);

namespace Phalanx\Bootstrap;

use Phalanx\Phalanx;

final class BootstrapContract
{
    public const string CONTRACT = '2.0';

    public const string ENTRYPOINT = Phalanx::class;

    public const string PACKAGE = 'phalanx-php/phalanx';

    public const string VERSION = Phalanx::VERSION;

    public string $contract;

    /** @var class-string */
    public string $entrypoint;

    public string $package;

    public string $version;

    private function __construct()
    {
        $this->contract = self::CONTRACT;
        $this->entrypoint = self::ENTRYPOINT;
        $this->package = self::PACKAGE;
        $this->version = self::VERSION;
    }

    public static function current(): self
    {
        return new self();
    }

    /** @return array{contract: string, entrypoint: class-string, package: string, version: string} */
    public function toArray(): array
    {
        return [
            'contract' => $this->contract,
            'entrypoint' => $this->entrypoint,
            'package' => $this->package,
            'version' => $this->version,
        ];
    }
}
