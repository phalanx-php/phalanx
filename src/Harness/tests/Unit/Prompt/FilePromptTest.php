<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Prompt;

use Phalanx\Harness\Prompt\FilePrompt;
use Phalanx\Harness\Prompt\PromptSource;
use Phalanx\Harness\Tests\Support\RecordingTaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilePromptTest extends TestCase
{
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
        self::assertSame('grammata.native.read ' . $path, $scope->lastWaitReason()?->detail);
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

        return $path;
    }
}
