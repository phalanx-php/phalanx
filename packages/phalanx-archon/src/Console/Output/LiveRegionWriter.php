<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Output;

/**
 * Minimal terminal surface needed by retained live-region renderers.
 *
 * Implementations decide how transient updates behave for their stream. TTY
 * writers generally repaint in place; non-TTY writers should drop transient
 * frames and keep only durable persist() output.
 */
interface LiveRegionWriter
{
    public function update(string ...$lines): void;

    public function persist(string ...$lines): void;

    public function clear(): void;

    public function isTty(): bool;
}
