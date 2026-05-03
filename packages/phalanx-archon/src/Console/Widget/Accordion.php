<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Widget;

use Closure;
use Phalanx\Archon\Console\Input\CancelledException;
use Phalanx\Archon\Console\Input\KeyReader;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Scope\Disposable;
use Phalanx\Scope\Suspendable;

/**
 * Collapsible sections, each containing a Form.
 *
 * Section headers are navigable with up/down. Enter expands the focused
 * section and runs its Form. When the form completes, the section collapses
 * and shows a one-line summary. When all sections have values, run() returns
 * array<string, mixed>.
 *
 * Visual per header row:
 *   ▸ Label           collapsed, not focused
 *   › Label           collapsed, focused
 *   ▾ Label           expanded (child form active)
 *   ✓ Label: summary  completed
 */
final class Accordion
{
    /** @var list<array{id: string, label: string, factory: Closure(): Form}> */
    private array $sections = [];

    public function section(string $id, string $label, Closure $factory): self
    {
        $clone             = clone $this;
        $clone->sections[] = ['id' => $id, 'label' => $label, 'factory' => $factory];

        return $clone;
    }

    /** @return array<string, mixed> */
    public function run(
        Suspendable&Disposable $scope,
        StreamOutput $output,
        KeyReader $reader,
    ): array {
        /** @var array<string, mixed> $values */
        $values   = [];
        $cursor   = 0;
        $expanded = null;

        $this->renderHeaders($output, $values, $cursor, $expanded);

        while (count($values) < count($this->sections)) {
            $key = $reader->nextKey($scope);

            if ($key === '' || $key === 'ctrl-c') {
                throw new CancelledException('Accordion cancelled');
            }

            $count = count($this->sections);

            match ($key) {
                'up'             => $cursor = max(0, $cursor - 1),
                'down'           => $cursor = min($count - 1, $cursor + 1),
                'enter', 'space' => null,
                default          => null,
            };

            if ($key === 'enter' || $key === 'space') {
                $section  = $this->sections[$cursor];
                $expanded = $cursor;
                $this->renderHeaders($output, $values, $cursor, $expanded);

                $form        = ($section['factory'])();
                $values[$section['id']] = $form->submit($scope, $output, $reader);
                $expanded    = null;
                $cursor      = min($count - 1, $cursor + 1);
            }

            $this->renderHeaders($output, $values, $cursor, $expanded);
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderHeaders(
        StreamOutput $output,
        array $values,
        int $cursor,
        ?int $expanded,
    ): void {
        $lines = [];
        foreach ($this->sections as $i => $section) {
            $id    = $section['id'];
            $label = $section['label'];

            if (array_key_exists($id, $values)) {
                $summary = is_array($values[$id])
                    ? implode(', ', array_map(strval(...), $values[$id]))
                    : (string) $values[$id];
                $lines[] = "  \033[32m✓\033[0m {$label}: \033[2m{$summary}\033[0m";
            } elseif ($i === $expanded) {
                $lines[] = "  \033[36m▾ {$label}\033[0m";
            } elseif ($i === $cursor) {
                $lines[] = "  \033[36m› {$label}\033[0m";
            } else {
                $lines[] = "  \033[2m▸\033[0m {$label}";
            }
        }
        $output->update(...$lines);
    }
}
