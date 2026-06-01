<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Styles;

use Phalanx\Theatron\Tui\Tdom\ElementType;
use Phalanx\Theatron\Tui\Tdom\Style as TdomStyle;

final class Stylesheet
{
    /** @param array<string, TdomStyle> $rules */
    private function __construct(
        private array $rules,
    ) {
    }

    /** @param array<string, TdomStyle> $rules */
    public static function of(array $rules): self
    {
        return new self($rules);
    }

    public function match(ElementType $type, ?string $role = null, ?string $variant = null): ?TdomStyle
    {
        $base = strtolower($type->name);

        if ($variant !== null && $role !== null) {
            $key = "{$base}:{$variant}.{$role}";
            if (isset($this->rules[$key])) {
                return $this->rules[$key];
            }
        }

        if ($role !== null) {
            $key = "{$base}.{$role}";
            if (isset($this->rules[$key])) {
                return $this->rules[$key];
            }
        }

        if ($variant !== null) {
            $key = "{$base}:{$variant}";
            if (isset($this->rules[$key])) {
                return $this->rules[$key];
            }
        }

        return $this->rules[$base] ?? $this->rules['root'] ?? null;
    }
}
