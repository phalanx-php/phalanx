<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Keymap;

final readonly class KeymapEntry
{
    public function __construct(
        private(set) string $section,
        private(set) string $combo,
        private(set) string $label,
    ) {
    }
}
