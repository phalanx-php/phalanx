<?php

declare(strict_types=1);

namespace Phalanx\Archon\Input;

use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Scope\Disposable;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;

/**
 * Per-prompt key reader. Holds a KeyParser to accumulate partial multi-byte
 * sequences across reads, drains bytes from Aegis ConsoleInput, and yields
 * one canonical key token per nextKey() call.
 *
 * Lifecycle: enableRawMode() flips the terminal once before the prompt
 * loop starts, restore() runs on scope dispose. Both delegate to the
 * managed ConsoleInput service so stty cooperation lives in Aegis.
 *
 * Read buffer size: 32 bytes. Most keypresses are 1-3 bytes; bracketed
 * paste can deliver larger chunks but the parser handles fragmentation.
 */
final class RawInput implements KeyReader
{
    private const int READ_CHUNK = 32;

    public bool $isInteractive {
        get => $this->consoleInput->isInteractive;
    }

    /** @var list<string> */
    private array $queue = [];

    public function __construct(
        private readonly ConsoleInput $consoleInput,
        private readonly KeyParser $parser = new KeyParser(),
    ) {
    }

    public static function fromScope(Scope $scope): self
    {
        return new self($scope->service(ConsoleInput::class));
    }

    public function nextKey(Suspendable $scope): string
    {
        while ($this->queue === []) {
            $bytes = $this->consoleInput->read($scope, self::READ_CHUNK);
            if ($bytes === '') {
                return '';
            }
            $this->queue = $this->parser->parse($bytes);
        }

        return array_shift($this->queue);
    }

    public function enableRawMode(Suspendable $scope): void
    {
        $this->consoleInput->enableRawMode($scope);
    }

    public function restore(Suspendable $scope): void
    {
        $this->consoleInput->restore($scope);
    }

    public function restoreOnDispose(Disposable&Suspendable $scope): self
    {
        $reader = $this;
        $scope->onDispose(static function () use ($reader, $scope): void {
            $reader->restore($scope);
        });

        return $reader;
    }
}
