<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

final class PasteEvent implements InputEvent
{
    public function __construct(
        public private(set) string $content,
    ) {}
}
