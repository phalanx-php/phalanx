<?php

declare(strict_types=1);

namespace Phalanx\Console\Composite;

use Closure;
use Phalanx\Console\Input\BasePrompt;
use Phalanx\Console\Input\RawInput;
use Phalanx\Console\Output\StreamOutput;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Sequential multi-field form with backward navigation.
 *
 * Each field is registered with an $id and a factory closure that receives
 * the field's previous value (null on first visit, mixed on revisit) and
 * returns a configured BasePrompt instance.
 *
 * Ctrl+U on any field after the first triggers revert() on that prompt,
 * rejecting its deferred with FormRevertedException. Form catches this,
 * erases the current field, and re-runs the previous step pre-populated
 * with its stored value.
 *
 * submit() returns a PromiseInterface that resolves to array<string, mixed>
 * keyed by field $id, or rejects with CancelledException if any field is
 * cancelled.
 */
final class Form
{
    /** @var list<array{id: string, factory: Closure(mixed): BasePrompt}> */
    private array $steps = [];

    public function text(string $id, Closure $factory): self
    {
        return $this->addStep($id, $factory);
    }

    public function password(string $id, Closure $factory): self
    {
        return $this->addStep($id, $factory);
    }

    public function number(string $id, Closure $factory): self
    {
        return $this->addStep($id, $factory);
    }

    public function confirm(string $id, Closure $factory): self
    {
        return $this->addStep($id, $factory);
    }

    public function select(string $id, Closure $factory): self
    {
        return $this->addStep($id, $factory);
    }

    public function multiSelect(string $id, Closure $factory): self
    {
        return $this->addStep($id, $factory);
    }

    public function search(string $id, Closure $factory): self
    {
        return $this->addStep($id, $factory);
    }

    public function suggest(string $id, Closure $factory): self
    {
        return $this->addStep($id, $factory);
    }

    /** @return PromiseInterface<array<string, mixed>> */
    public function submit(StreamOutput $output, RawInput $input): PromiseInterface
    {
        $deferred = new Deferred();
        $steps    = $this->steps;
        $values   = [];
        $index    = 0;

        $runStep = null;
        $runStep = static function () use (&$runStep, &$index, &$values, $steps, $deferred, $output, $input): void {
            if ($index >= count($steps)) {
                $deferred->resolve($values);
                return;
            }

            $step          = $steps[$index];
            $id            = $step['id'];
            $previousValue = $values[$id] ?? null;
            $prompt        = ($step['factory'])($previousValue);

            $prompt->prompt($output, $input)->then(
                static function (mixed $value) use (&$index, &$values, $id, &$runStep): void {
                    $values[$id] = $value;
                    $index++;
                    $runStep();
                },
                static function (\Throwable $e) use (&$index, &$values, $id, &$runStep, $deferred): void {
                    if ($e instanceof FormRevertedException) {
                        unset($values[$id]);
                        $index = max(0, $index - 1);
                        $runStep();
                    } else {
                        $deferred->reject($e);
                    }
                },
            );
        };

        $runStep();

        return $deferred->promise();
    }

    private function addStep(string $id, Closure $factory): self
    {
        $clone          = clone $this;
        $clone->steps[] = ['id' => $id, 'factory' => $factory];

        return $clone;
    }
}
