<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Testing\Codegen;

use Phalanx\Testing\Codegen\AccessorTraitWriter;
use Phalanx\Testing\Codegen\LensMetadata;
use Phalanx\Testing\Lenses\LedgerLens;
use Phalanx\Testing\Lenses\LedgerLensFactory;
use Phalanx\Testing\Lenses\RuntimeLens;
use Phalanx\Testing\Lenses\RuntimeLensFactory;
use Phalanx\Testing\Lenses\ScopeLens;
use Phalanx\Testing\Lenses\ScopeLensFactory;
use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\TestCase;

final class AccessorTraitWriterTest extends TestCase
{
    use UsesTempWorkspace;

    public function testRendersEmptyTraitForEmptyInput(): void
    {
        $output = new AccessorTraitWriter()->render([]);

        self::assertStringContainsString('namespace Phalanx\\Testing\\Generated;', $output);
        self::assertStringContainsString('trait TestAppAccessors', $output);
        self::assertStringNotContainsString('use Phalanx\\Testing\\Lenses\\', $output);
    }

    public function testRendersAccessorsAlphabeticallyByName(): void
    {
        $output = new AccessorTraitWriter()->render($this->runtimeFixtures());

        $ledgerPos = strpos($output, '$ledger');
        $runtimePos = strpos($output, '$runtime');
        $scopePos = strpos($output, '$scope');

        self::assertNotFalse($ledgerPos);
        self::assertNotFalse($runtimePos);
        self::assertNotFalse($scopePos);

        self::assertLessThan($runtimePos, $ledgerPos, 'ledger appears before runtime');
        self::assertLessThan($scopePos, $runtimePos, 'runtime appears before scope');
    }

    public function testRendersUseStatementsAlphabetically(): void
    {
        $output = new AccessorTraitWriter()->render($this->runtimeFixtures());

        $ledgerUse = strpos($output, 'use Phalanx\\Testing\\Lenses\\LedgerLens;');
        $runtimeUse = strpos($output, 'use Phalanx\\Testing\\Lenses\\RuntimeLens;');
        $scopeUse = strpos($output, 'use Phalanx\\Testing\\Lenses\\ScopeLens;');

        self::assertNotFalse($ledgerUse);
        self::assertNotFalse($runtimeUse);
        self::assertNotFalse($scopeUse);

        self::assertLessThan($runtimeUse, $ledgerUse);
        self::assertLessThan($scopeUse, $runtimeUse);
    }

    public function testRendersTypedPropertyHookAccessors(): void
    {
        $output = new AccessorTraitWriter()->render($this->runtimeFixtures());

        self::assertStringContainsString(
            "    public LedgerLens \$ledger {\n        get => \$this->lens(LedgerLens::class);\n    }\n",
            $output,
        );
    }

    public function testOutputIsDeterministic(): void
    {
        $writer = new AccessorTraitWriter();
        $first = $writer->render($this->runtimeFixtures());
        $second = $writer->render($this->runtimeFixtures());

        self::assertSame($first, $second);
    }

    public function testWritePersistsContentsToTarget(): void
    {
        $target = $this->tempWorkspace('phalanx-codegen-test-')
            ->path('Generated/TestAppAccessors.php');

        new AccessorTraitWriter()->write($this->runtimeFixtures(), $target);

        self::assertFileExists($target);
        $contents = file_get_contents($target);
        self::assertNotFalse($contents);
        self::assertStringContainsString('trait TestAppAccessors', $contents);
    }

    public function testWriteCreatesMissingParentDirectories(): void
    {
        $target = $this->tempWorkspace('phalanx-codegen-test-')
            ->path('deeply/nested/Generated/TestAppAccessors.php');

        new AccessorTraitWriter()->write([], $target);

        self::assertFileExists($target);
    }

    /** @return list<LensMetadata> */
    private function runtimeFixtures(): array
    {
        return [
            new LensMetadata('ledger', LedgerLens::class, LedgerLensFactory::class),
            new LensMetadata('runtime', RuntimeLens::class, RuntimeLensFactory::class),
            new LensMetadata('scope', ScopeLens::class, ScopeLensFactory::class),
        ];
    }
}
