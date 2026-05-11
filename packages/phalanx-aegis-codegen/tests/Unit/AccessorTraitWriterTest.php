<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Codegen\Tests\Unit;

use Phalanx\Aegis\Codegen\AccessorTraitWriter;
use Phalanx\Aegis\Codegen\LensMetadata;
use Phalanx\Testing\Lenses\LedgerLens;
use Phalanx\Testing\Lenses\LedgerLensFactory;
use Phalanx\Testing\Lenses\RuntimeLens;
use Phalanx\Testing\Lenses\RuntimeLensFactory;
use Phalanx\Testing\Lenses\ScopeLens;
use Phalanx\Testing\Lenses\ScopeLensFactory;
use PHPUnit\Framework\TestCase;

final class AccessorTraitWriterTest extends TestCase
{
    public function testRendersEmptyTraitForEmptyInput(): void
    {
        $output = new AccessorTraitWriter()->render([]);

        self::assertStringContainsString('namespace Phalanx\\Testing\\Generated;', $output);
        self::assertStringContainsString('trait TestAppAccessors', $output);
        self::assertStringNotContainsString('use Phalanx\\Testing\\Lenses\\', $output);
    }

    public function testRendersAccessorsAlphabeticallyByName(): void
    {
        $output = new AccessorTraitWriter()->render($this->aegisFixtures());

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
        $output = new AccessorTraitWriter()->render($this->aegisFixtures());

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
        $output = new AccessorTraitWriter()->render($this->aegisFixtures());

        self::assertStringContainsString(
            "    public LedgerLens \$ledger {\n        get => \$this->lens(LedgerLens::class);\n    }\n",
            $output,
        );
    }

    public function testOutputIsDeterministic(): void
    {
        $writer = new AccessorTraitWriter();
        $first = $writer->render($this->aegisFixtures());
        $second = $writer->render($this->aegisFixtures());

        self::assertSame($first, $second);
    }

    public function testWritePersistsContentsToTarget(): void
    {
        $target = sys_get_temp_dir()
            . '/phalanx-codegen-test-'
            . uniqid()
            . '/Generated/TestAppAccessors.php';

        try {
            new AccessorTraitWriter()->write($this->aegisFixtures(), $target);

            self::assertFileExists($target);
            $contents = file_get_contents($target);
            self::assertNotFalse($contents);
            self::assertStringContainsString('trait TestAppAccessors', $contents);
        } finally {
            if (is_file($target)) {
                @unlink($target);
            }
            $dir = dirname($target);
            if (is_dir($dir)) {
                @rmdir($dir);
                @rmdir(dirname($dir));
            }
        }
    }

    public function testWriteCreatesMissingParentDirectories(): void
    {
        $base = sys_get_temp_dir() . '/phalanx-codegen-test-' . uniqid();
        $target = $base . '/deeply/nested/Generated/TestAppAccessors.php';

        try {
            new AccessorTraitWriter()->write([], $target);

            self::assertFileExists($target);
        } finally {
            if (is_file($target)) {
                @unlink($target);
            }
            self::removeRecursive($base);
        }
    }

    private static function removeRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($full)) {
                self::removeRecursive($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }

    /** @return list<LensMetadata> */
    private function aegisFixtures(): array
    {
        return [
            new LensMetadata('ledger', LedgerLens::class, LedgerLensFactory::class),
            new LensMetadata('runtime', RuntimeLens::class, RuntimeLensFactory::class),
            new LensMetadata('scope', ScopeLens::class, ScopeLensFactory::class),
        ];
    }
}
