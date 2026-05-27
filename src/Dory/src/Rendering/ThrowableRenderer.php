<?php

declare(strict_types=1);

namespace Phalanx\Dory\Rendering;

use Throwable;

final class ThrowableRenderer implements ValueRenderer
{
    public function supports(mixed $value): bool
    {
        return $value instanceof Throwable;
    }

    public function render(mixed $value, OutputSink $output): void
    {
        if (!$value instanceof Throwable) {
            return;
        }

        $output->line($value::class . ': ' . $value->getMessage());
        $output->line('  at ' . $value->getFile() . ':' . $value->getLine());

        $previous = $value->getPrevious();

        if ($previous !== null) {
            $output->line('  caused by: ' . $previous::class . ': ' . $previous->getMessage());
        }
    }
}
