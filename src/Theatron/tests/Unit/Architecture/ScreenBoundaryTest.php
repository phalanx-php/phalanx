<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenBoundaryTest extends TestCase
{
    #[Test]
    public function templateScreensDoNotDependOnAgoraOrSurrealPersistence(): void
    {
        foreach (self::screenFiles() as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            self::assertStringNotContainsString('Phalanx\\Agora\\', $source, $file);
            self::assertStringNotContainsString('Phalanx\\Surreal\\', $source, $file);
            self::assertStringNotContainsString('Harness\\Replay', $source, $file);
        }
    }

    /**
     * @return list<string>
     */
    private static function screenFiles(): array
    {
        $directory = dirname(__DIR__, 3) . '/src/Template/Screen';
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
