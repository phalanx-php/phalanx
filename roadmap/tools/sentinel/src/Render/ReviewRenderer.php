<?php

declare(strict_types=1);

namespace Sentinel\Render;

use Sentinel\Watcher\FileChange;

interface ReviewRenderer
{
    public function banner(): void;

    public function agentRegistered(string $name, string $color): void;

    public function watchingDirectory(string $path): void;

    public function ready(): void;

    /** @param list<FileChange> $changes */
    public function fileChanges(array $changes): void;

    public function agentFeedback(string $agentName, string $color, string $text): void;

    public function humanMessage(string $message): void;

    public function externalMessage(string $from, string $message): void;

    public function reviewComplete(int $reviewNumber, ?float $elapsedSeconds = null, ?int $totalTokens = null): void;

    public function prompt(): void;

    public function info(string $message): void;

    public function error(string $message): void;

    public function shutdown(): void;
}
