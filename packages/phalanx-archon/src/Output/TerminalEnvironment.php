<?php

declare(strict_types=1);

namespace Phalanx\Archon\Output;

final readonly class TerminalEnvironment
{
    public function __construct(
        public ?int $columns = null,
        public ?int $lines = null,
        public string $termProgram = '',
    ) {
    }

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): self
    {
        return new self(
            columns: self::size($context['COLUMNS'] ?? null),
            lines: self::size($context['LINES'] ?? null),
            termProgram: (string) ($context['TERM_PROGRAM'] ?? ''),
        );
    }

    private static function size(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $size = (int) $value;

        return $size > 0 ? $size : null;
    }
}
