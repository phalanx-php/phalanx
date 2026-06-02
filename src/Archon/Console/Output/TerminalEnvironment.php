<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Output;

use Phalanx\Boot\AppContext;

final readonly class TerminalEnvironment
{
    public function __construct(
        public ?int $columns = null,
        public ?int $lines = null,
        public ?bool $isTty = null,
        public string $termProgram = '',
    ) {
    }

    public static function fromContext(AppContext $context): self
    {
        return new self(
            columns: self::size($context->get('COLUMNS')),
            lines: self::size($context->get('LINES')),
            termProgram: $context->string('TERM_PROGRAM', ''),
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
