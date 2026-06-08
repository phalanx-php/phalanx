<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Tools;

use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Phalanx\Tools\RenameMapping\main;
use function Phalanx\Tools\RenameMapping\renameBase;
use function Phalanx\Tools\RenameMapping\replaceContent;

final class RenameMappingScriptTest extends TestCase
{
    use UsesTempWorkspace;

    private string $tempDir;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 5) . '/tools/apply-rename-mapping.php';
    }

    protected function setUp(): void
    {
        $this->tempDir = $this->tempWorkspace('phalanx-rename-tool-')->dir('tree');
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
    public function renameRulesAreIdempotentWhenNewNameExtendsOldName(): void
    {
        $content = <<<'TEXT'
use Phalanx\SurrealDb\SurrealDbClient;
use Phalanx\\SurrealDb\\SurrealDbClient;
final class SurrealDbConfig {}
$package = 'phalanx-php/surrealdb';
$repo = 'phalanx-surrealdb';
TEXT;

        $updated = replaceContent($content, self::surrealMapping());

        self::assertSame($content, $updated);

        $pairs = [
            'Surreal' => 'SurrealDb',
            'surreal' => 'surrealdb',
            'surrealdb' => 'surrealdb',
        ];

        self::assertSame('SurrealDbConfig.php', renameBase('SurrealDbConfig.php', $pairs));
        self::assertSame('surrealdb.yaml', renameBase('surrealdb.yaml', $pairs));
    }

    #[Test]
    public function applyModeSkipsHiddenPrivateStateDirectories(): void
    {
        $this->writeFixtureTree();
        $this->tempWorkspace()->file('tree/.daemon8/snapshots/foo.md', 'use Phalanx\Hydra\WorkerPool;');

        ob_start();
        $exitCode = main($this->tempDir, ['apply-rename-mapping.php', '--apply']);
        ob_end_clean();

        self::assertSame(0, $exitCode);
        self::assertSame(
            'use Phalanx\Hydra\WorkerPool;',
            $this->fixture('tree/.daemon8/snapshots/foo.md'),
        );
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
            $this->fixture('tree/sample.php'),
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
            $this->fixture('tree/sample.php'),
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

    /** @return list<array<string, mixed>> */
    private static function surrealMapping(): array
    {
        return [
            [
                'action' => 'rename',
                'old' => 'Surreal',
                'new' => 'SurrealDb',
                'oldDir' => 'src/Surreal',
                'newDir' => 'src/SurrealDb',
                'oldNamespace' => 'Phalanx\\Surreal',
                'newNamespace' => 'Phalanx\\SurrealDb',
                'oldTestNamespace' => 'Phalanx\\Surreal\\Tests',
                'newTestNamespace' => 'Phalanx\\SurrealDb\\Tests',
                'oldPackage' => 'phalanx-php/surreal',
                'newPackage' => 'phalanx-php/surrealdb',
                'oldSplitRepo' => 'phalanx-surreal',
                'newSplitRepo' => 'phalanx-surrealdb',
            ],
        ];
    }

    private function writeFixtureTree(): void
    {
        $this->tempWorkspace()->file(
            'tree/tools/rename-mapping.json',
            json_encode(self::mapping(), JSON_THROW_ON_ERROR),
        );
        $this->tempWorkspace()->file('tree/sample.php', 'use Phalanx\Hydra\WorkerPool;');
        $this->tempWorkspace()->file('tree/HydraDemoServiceBundle.php', '<?php');
    }

    private function fixture(string $relative): string
    {
        return $this->tempWorkspace()->read($relative);
    }
}
