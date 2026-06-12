<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Contracts;

use Phalanx\Err\Err;
use Phalanx\Err\Severity;
use Phalanx\Invocation\Caps;
use Phalanx\Invocation\Executable;
use Phalanx\Invocation\InvocationCtx;
use Phalanx\Supervision\Identity;
use Phalanx\Supervision\Operation;
use Phalanx\Tests\Fixture\OneFile\ChargeCard;
use Phalanx\Tests\Fixture\OneFile\ChargeCardCaps;
use Phalanx\Tests\Fixture\OneFile\ChargeDeclined;
use Phalanx\Tests\Fixture\OneFile\ChargeGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class OneFileWorkUnitTest extends TestCase
{
    #[Test]
    public function dumpedClassmapResolvesEveryCoLocatedDeclarationToTheOneFile(): void
    {
        $classmap = require dirname(__DIR__, 3) . '/vendor/composer/autoload_classmap.php';

        self::assertIsArray($classmap);

        $fixture = realpath(dirname(__DIR__, 3) . '/tests/Fixture/OneFile/ChargeCard.php');

        self::assertNotFalse($fixture);

        foreach ([ChargeCard::class, ChargeCardCaps::class, ChargeDeclined::class, ChargeGateway::class] as $class) {
            self::assertArrayHasKey($class, $classmap);
            self::assertIsString($classmap[$class]);
            self::assertSame($fixture, realpath($classmap[$class]));
        }
    }

    #[Test]
    public function secondaryDeclarationsAutoloadColdWithoutTheirOwnFiles(): void
    {
        $caps = ChargeCardCaps::class;
        $probe = <<<PHP
            require '{$this->packageRoot()}/vendor/autoload.php';

            echo class_exists('{$caps}') ? 'loaded' : 'missing';
            PHP;

        self::assertSame('loaded', $this->runPhp($probe));
    }

    #[Test]
    public function theCoLocatedWorkUnitRunsBothOutcomePaths(): void
    {
        $ctx = new class implements InvocationCtx {
        };

        $task = new ChargeCard(invoice: 'inv_8821');

        $receipt = $task($ctx, new ChargeCardCaps(gateway: new ChargeGateway(approving: true)));

        self::assertSame('receipt:inv_8821', $receipt);

        $declined = $task($ctx, new ChargeCardCaps(gateway: new ChargeGateway(approving: false)));

        self::assertInstanceOf(ChargeDeclined::class, $declined);
        self::assertSame(Severity::Expected, $declined->severity);
        self::assertSame('inv_8821', $declined->invoice);
    }

    #[Test]
    public function theCoLocatedDeclarationsCarryTheContractVocabulary(): void
    {
        self::assertTrue(new ReflectionClass(ChargeCard::class)->implementsInterface(Executable::class));
        self::assertTrue(new ReflectionClass(ChargeCardCaps::class)->implementsInterface(Caps::class));
        self::assertTrue(new ReflectionClass(ChargeDeclined::class)->implementsInterface(Err::class));

        $reflection = new ReflectionClass(ChargeCard::class);

        self::assertCount(1, $reflection->getAttributes(Operation::class));

        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        self::assertCount(1, $constructor->getParameters()[0]->getAttributes(Identity::class));
    }

    private function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function runPhp(string $code): string
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->packageRoot(),
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the cold-autoload probe.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout;
    }
}
