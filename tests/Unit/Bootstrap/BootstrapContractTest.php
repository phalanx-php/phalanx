<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Bootstrap;

use Phalanx\Bootstrap\BootstrapContract;
use Phalanx\Phalanx;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BootstrapContractTest extends TestCase
{
    #[Test]
    public function publicContractNamesTheComposerBootstrapSurface(): void
    {
        $contract = Phalanx::bootstrapContract();

        self::assertSame(BootstrapContract::CONTRACT, $contract->contract);
        self::assertSame(BootstrapContract::ENTRYPOINT, $contract->entrypoint);
        self::assertSame(BootstrapContract::PACKAGE, $contract->package);
        self::assertSame(BootstrapContract::VERSION, $contract->version);
        self::assertSame([
            'contract' => '2.0',
            'entrypoint' => Phalanx::class,
            'package' => 'phalanx-php/phalanx',
            'version' => '2.0-dev',
        ], $contract->toArray());
    }

    #[Test]
    public function composerMetadataMatchesThePublicContract(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($composer);
        self::assertSame(Phalanx::bootstrapContract()->toArray(), $composer['extra']['phalanx']['bootstrap'] ?? null);
    }
}
