<?php

declare(strict_types=1);

namespace Phalanx\Dory\Rendering;

final class ValueRendererPipeline
{
    /** @param list<ValueRenderer> $renderers */
    public function __construct(private(set) array $renderers)
    {
    }

    public function render(mixed $value, OutputSink $output): void
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($value)) {
                $renderer->render($value, $output);
                return;
            }
        }

        $output->line(var_export($value, true));
    }
}
