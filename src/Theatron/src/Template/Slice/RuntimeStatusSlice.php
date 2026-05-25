<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

use Phalanx\Boot\AppContext;

class RuntimeStatusSlice
{
    public function __construct(
        private(set) ?string $workingDirectory = null,
        private(set) ?string $homeDirectory = null,
    ) {
    }

    public static function fromContext(AppContext $context): self
    {
        $workingDirectory = self::stringValue($context->get('PWD'));
        if ($workingDirectory === null) {
            $resolved = getcwd();
            $workingDirectory = $resolved === false ? null : $resolved;
        }

        return new self(
            workingDirectory: $workingDirectory,
            homeDirectory: self::stringValue($context->get('HOME')),
        );
    }

    public function cwdLabel(): string
    {
        if ($this->workingDirectory === null || $this->workingDirectory === '') {
            return '.';
        }

        $workingDirectory = self::trimDirectory($this->workingDirectory);
        $homeDirectory = $this->homeDirectory === null ? null : self::trimDirectory($this->homeDirectory);

        if ($homeDirectory === null || $homeDirectory === '') {
            return $workingDirectory;
        }

        if ($workingDirectory === $homeDirectory) {
            return '~';
        }

        if (str_starts_with($workingDirectory, $homeDirectory . DIRECTORY_SEPARATOR)) {
            return '~' . mb_substr($workingDirectory, mb_strlen($homeDirectory));
        }

        return $workingDirectory;
    }

    private static function stringValue(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    private static function trimDirectory(string $directory): string
    {
        $trimmed = rtrim($directory, DIRECTORY_SEPARATOR);

        return $trimmed === '' ? DIRECTORY_SEPARATOR : $trimmed;
    }
}
