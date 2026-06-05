<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenBoundaryTest extends TestCase
{
    #[Test]
    public function tuiScreensDoNotExecuteAgentRuntimeDirectly(): void
    {
        foreach (self::sourceFiles(dirname(__DIR__, 4) . '/src/Tui') as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            self::assertStringNotContainsString('Phalanx\\' . 'Harness\\', $source, $file);
            self::assertStringNotContainsString('Harness' . '\\Replay', $source, $file);
            self::assertStringNotContainsString('Agent::run(', $source, $file);
        }
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }
}
