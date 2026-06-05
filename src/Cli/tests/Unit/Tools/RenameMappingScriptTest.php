<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Phalanx\Tools\RenameMapping\renameBase;
use function Phalanx\Tools\RenameMapping\replaceContent;

require_once dirname(__DIR__, 5) . '/tools/apply-rename-mapping.php';

final class RenameMappingScriptTest extends TestCase
{
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
}
