<?php

declare(strict_types=1);

namespace Phalanx\Console\Composite;

use Closure;
use Phalanx\Console\Input\CancelledException;
use Phalanx\Console\Input\RawInput;
use Phalanx\Console\Output\StreamOutput;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Collapsible sections, each containing a Form.
 *
 * Section headers are navigable with up/down. Enter expands the focused
 * section and runs its Form. When the form completes, the section collapses
 * and shows a one-line summary. When all sections have values, the deferred
 * resolves with array<string, mixed>.
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

    /** @return PromiseInterface<array<string, mixed>> */
    public function run(StreamOutput $output, RawInput $input): PromiseInterface
    {
        $deferred = new Deferred();
        $sections = $this->sections;
        $values   = [];
        $cursor   = 0;
        $expanded = null;

        $render = static function () use (&$sections, &$values, &$cursor, &$expanded, $output): void {
            $lines = [];
            foreach ($sections as $i => $section) {
                $id    = $section['id'];
                $label = $section['label'];

                if (isset($values[$id])) {
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
        };

        $listenForNav = null;
        $runSection   = null;

        $runSection = static function (int $index) use (
            &$runSection, &$listenForNav,
            &$cursor, &$expanded, &$values, &$sections,
            $output, $input, $deferred, $render,
        ): void {
            $section  = $sections[$index];
            $id       = $section['id'];
            $expanded = $index;
            $render();

            $form = ($section['factory'])();
            $form->submit($output, $input)->then(
                static function (mixed $value) use (
                    &$listenForNav, &$cursor, &$expanded, &$values, &$sections,
                    $id, $index, $deferred, $render,
                ): void {
                    $values[$id] = $value;
                    $expanded    = null;

                    if (count($values) === count($sections)) {
                        $render();
                        $deferred->resolve($values);
                        return;
                    }

                    $cursor = $index + 1;
                    $render();
                    $listenForNav();
                },
                static function (\Throwable $e) use ($deferred): void {
                    $deferred->reject($e);
                },
            );
        };

        $listenForNav = static function () use (
            &$listenForNav, &$runSection,
            &$cursor, &$sections,
            $render, $input, $deferred,
        ): void {
            $input->nextKey()->then(
                static function (string $key) use (
                    &$listenForNav, &$runSection,
                    &$cursor, &$sections,
                    $render, $input, $deferred,
                ): void {
                    if ($key === 'ctrl-c') {
                        $deferred->reject(new CancelledException('Accordion cancelled'));
                        return;
                    }

                    $count = count($sections);

                    match ($key) {
                        'up'             => $cursor = max(0, $cursor - 1),
                        'down'           => $cursor = min($count - 1, $cursor + 1),
                        'enter', 'space' => $runSection($cursor),
                        default          => null,
                    };

                    if ($key !== 'enter' && $key !== 'space') {
                        $render();
                        $listenForNav();
                    }
                },
            );
        };

        $render();
        $listenForNav();

        return $deferred->promise();
    }
}
