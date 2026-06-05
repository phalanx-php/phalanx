<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Collab\Prompts;

use Phalanx\Tui\Collab\Prompts\FilePrompt;
use Phalanx\Tui\Collab\Prompts\PromptSource;
use Phalanx\Tui\Tests\Support\RecordingTaskScope;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('swoole')]
final class FilePromptTest extends TestCase
{
    /** @var list<string> */
    private array $promptFiles = [];

    #[Test]
    public function filePromptLoadsTextThroughTaskScope(): void
    {
        $path = $this->writePrompt('You are concise.');
        $scope = new RecordingTaskScope();

        $prompt = new FilePrompt($path);

        self::assertSame('file:' . $path, $prompt->id);
        self::assertSame(
            'You are concise.',
            self::insideCoroutine(static fn (): string => $prompt($scope)),
        );
        self::assertSame(1, $scope->callCount());
        self::assertSame('filesystem.native.read ' . $path, $scope->lastWaitReason()?->detail);
    }

    #[Test]
    public function promptSourceInterfaceCanBeConsumedByLoader(): void
    {
        $path = $this->writePrompt('Follow the roadmap.');

        $loader = static function (PromptSource $source): string {
            return self::insideCoroutine(static fn (): string => $source(new RecordingTaskScope()));
        };

        self::assertSame('Follow the roadmap.', $loader(new FilePrompt($path)));
    }

    #[Test]
    public function filePromptRejectsMissingFileWhenResolved(): void
    {
        $path = sys_get_temp_dir() . '/phalanx-missing-' . uniqid('', true) . '.prompt.md';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Prompt file does not exist');

        (new FilePrompt($path))(new RecordingTaskScope());
    }

    #[Test]
    public function filePromptRejectsDirectoryPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must reference a file');

        new FilePrompt(sys_get_temp_dir());
    }

    protected function setUp(): void
    {
        if (!extension_loaded('swoole') || version_compare((string) phpversion('swoole'), '6.0.0', '<')) {
            self::markTestSkipped('FilePrompt coroutine tests require the swoole 6.x extension.');
        }
    }

    #[After]
    protected function removePromptFiles(): void
    {
        foreach ($this->promptFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->promptFiles = [];
    }

    private static function insideCoroutine(\Closure $callback): mixed
    {
        $result = null;
        $error = null;

        \Swoole\Coroutine\run(static function () use ($callback, &$result, &$error): void {
            try {
                $result = $callback();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    private function writePrompt(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phalanx-prompt-');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->promptFiles[] = $path;

        return $path;
    }
}
