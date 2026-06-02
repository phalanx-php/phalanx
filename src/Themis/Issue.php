<?php

declare(strict_types=1);

namespace Phalanx\Themis;

final readonly class Issue
{
    public function __construct(
        public IssueLevel $level,
        public string $code,
        public string $message,
        public ?string $envKey = null,
        public ?string $path = null,
        public ?string $hint = null,
    ) {
    }

    public static function error(
        string $code,
        string $message,
        ?string $envKey = null,
        ?string $path = null,
        ?string $hint = null,
    ): self {
        return new self(IssueLevel::Error, $code, $message, $envKey, $path, $hint);
    }

    public static function warning(
        string $code,
        string $message,
        ?string $envKey = null,
        ?string $path = null,
        ?string $hint = null,
    ): self {
        return new self(IssueLevel::Warning, $code, $message, $envKey, $path, $hint);
    }

    public static function info(
        string $code,
        string $message,
        ?string $envKey = null,
        ?string $path = null,
        ?string $hint = null,
    ): self {
        return new self(IssueLevel::Info, $code, $message, $envKey, $path, $hint);
    }
}
