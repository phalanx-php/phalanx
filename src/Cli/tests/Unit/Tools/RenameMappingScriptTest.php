<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Tools;

use Phalanx\Cli\Tests\Support\RemovesDirectories;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Phalanx\Tools\RenameMapping\main;
use function Phalanx\Tools\RenameMapping\renameBase;
use function Phalanx\Tools\RenameMapping\replaceContent;

final class RenameMappingScriptTest extends TestCase
{
    use RemovesDirectories;

    private string $tempDir;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 5) . '/tools/apply-rename-mapping.php';
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/' . uniqid('phalanx-rename-tool-', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            self::removeDir($this->tempDir);
        }
    }

    #[Test]
    public function replacesHydraModuleTokensWithoutCorruptingHydrationVocabulary(): void
    {
        $content = <<<'TEXT'
use Phalanx\Hydra\WorkerPool;
use Phalanx\\Hydra\\WorkerPool;
final class HydraDemoServiceBundle {}
$demo = 'demos/hydra/01-basic-workers';
$package = 'phalanx-php/hydra';
$words = 'hydrate Hydrator HydratedConfig hydration';
TEXT;

        $updated = replaceContent($content, self::mapping());

        self::assertStringContainsString('use Phalanx\Worker\WorkerPool;', $updated);
        self::assertStringContainsString('use Phalanx\\\\Worker\\\\WorkerPool;', $updated);
        self::assertStringContainsString('final class WorkerDemoServiceBundle {}', $updated);
        self::assertStringContainsString("'demos/worker/01-basic-workers'", $updated);
        self::assertStringContainsString("'phalanx-php/worker'", $updated);
        self::assertStringContainsString("'hydrate Hydrator HydratedConfig hydration'", $updated);
    }

    #[Test]
    public function renamesClassAndSlugPathSegmentsWithoutRenamingHydratorFiles(): void
    {
        $pairs = [
            'Hydra' => 'Worker',
            'hydra' => 'worker',
        ];

        self::assertSame('WorkerDemoServiceBundle.php', renameBase('HydraDemoServiceBundle.php', $pairs));
        self::assertSame('worker.yaml', renameBase('hydra.yaml', $pairs));
        self::assertSame('ConfigHydrator.php', renameBase('ConfigHydrator.php', $pairs));
        self::assertSame('HydratedConfig.php', renameBase('HydratedConfig.php', $pairs));
    }

    #[Test]
    public function dryRunReportsChangesWithoutMutatingTree(): void
    {
        $this->writeFixtureTree();

        ob_start();
        $exitCode = main($this->tempDir, ['apply-rename-mapping.php', '--dry-run']);
        ob_end_clean();

        self::assertSame(0, $exitCode);

        self::assertStringContainsString(
            'use Phalanx\Hydra\WorkerPool;',
            (string) file_get_contents($this->tempDir . '/sample.php'),
        );
        self::assertFileExists($this->tempDir . '/HydraDemoServiceBundle.php');
        self::assertFileDoesNotExist($this->tempDir . '/WorkerDemoServiceBundle.php');
    }

    #[Test]
    public function applyModeRewritesTextAndRenamesPaths(): void
    {
        $this->writeFixtureTree();

        ob_start();
        $exitCode = main($this->tempDir, ['apply-rename-mapping.php', '--apply']);
        ob_end_clean();

        self::assertSame(0, $exitCode);

        self::assertStringContainsString(
            'use Phalanx\Worker\WorkerPool;',
            (string) file_get_contents($this->tempDir . '/sample.php'),
        );
        self::assertFileDoesNotExist($this->tempDir . '/HydraDemoServiceBundle.php');
        self::assertFileExists($this->tempDir . '/WorkerDemoServiceBundle.php');
    }

    /** @return list<array<string, mixed>> */
    private static function mapping(): array
    {
        return [
            [
                'action' => 'rename',
                'old' => 'Hydra',
                'new' => 'Worker',
                'oldDir' => 'src/Hydra',
                'newDir' => 'src/Worker',
                'oldNamespace' => 'Phalanx\\Hydra',
                'newNamespace' => 'Phalanx\\Worker',
                'oldTestNamespace' => 'Phalanx\\Hydra\\Tests',
                'newTestNamespace' => 'Phalanx\\Worker\\Tests',
                'oldPackage' => 'phalanx-php/hydra',
                'newPackage' => 'phalanx-php/worker',
                'oldSplitRepo' => 'phalanx-hydra',
                'newSplitRepo' => 'phalanx-worker',
            ],
        ];
    }

    private function writeFixtureTree(): void
    {
        mkdir($this->tempDir . '/tools', 0755, true);
        file_put_contents(
            $this->tempDir . '/tools/rename-mapping.json',
            json_encode(self::mapping(), JSON_THROW_ON_ERROR),
        );
        file_put_contents($this->tempDir . '/sample.php', 'use Phalanx\Hydra\WorkerPool;');
        file_put_contents($this->tempDir . '/HydraDemoServiceBundle.php', '<?php');
    }
}
