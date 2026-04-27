<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Widget;

final class AccordionSection
{
    public function __construct(
        public private(set) string $title,
        public private(set) Widget $content,
        public bool $expanded = false,
        public private(set) int $contentHeight = 5,
    ) {}
}
