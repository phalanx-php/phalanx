<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Widget;

final class AccordionSection
{
    public function __construct(
        private(set) string $title,
        private(set) Widget $content,
        public bool $expanded = false,
        private(set) int $contentHeight = 5,
    ) {}
}
