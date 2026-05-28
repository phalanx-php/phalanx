<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[Group('architecture')]
final class DevCleanupTest extends TestCase
{
    private const int LINE_LIMIT = 3;

    #[Test]
    public function no_commented_code_in_source(): void
    {
        $root = dirname(__DIR__, 2);

        $process = new Process(
            command: [
                'php', 'vendor/bin/swiss-knife',
                'check-commented-code',
                'src',
                '--line-limit', (string) self::LINE_LIMIT,
            ],
            cwd: $root,
            timeout: 30,
        );

        $process->run();

        $errorOutput = trim($process->getErrorOutput());
        if ($errorOutput !== '') {
            $before = strstr($errorOutput, 'check-commented-code', before_needle: true);
            self::fail(rtrim($before !== false ? $before : $errorOutput));
        }

        $output = $process->getOutput();
        $files = self::extractFiles($output);

        self::assertSame(
            0,
            $process->getExitCode(),
            "[Commented Code Was Found]\n\nDouble-check the following files:\n" . $files,
        );
    }

    private static function extractFiles(string $output): string
    {
        $after = strstr($output, '*.php files');
        if ($after === false) {
            return trim($output);
        }

        $after = substr($after, strlen('*.php files'));
        $errorPos = strrpos($after, '[ERROR]');
        if ($errorPos !== false) {
            $after = substr($after, 0, $errorPos);
        }

        return trim($after);
    }
}
