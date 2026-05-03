<?php

declare(strict_types=1);

namespace Phalanx\Archon\Composite;

use Closure;
use Phalanx\Archon\Input\BasePrompt;
use Phalanx\Archon\Input\KeyReader;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Scope\Disposable;
use Phalanx\Scope\Suspendable;

/**
 * Sequential multi-field form with backward navigation.
 *
 * Each field is registered with an $id and a factory closure that receives
 * the field's previous value (null on first visit, mixed on revisit) and
 * returns a configured BasePrompt instance.
 *
 * Ctrl+U on any field after the first triggers revert() on that prompt,
 * which throws FormRevertedException. Form catches it, erases the current
 * field, and re-runs the previous step pre-populated with its stored value.
 *
 * submit() returns array<string, mixed> keyed by field $id. Cancellation
 * propagates as CancelledException from the underlying prompt.
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

    /** @return array<string, mixed> */
    public function submit(
        Suspendable&Disposable $scope,
        StreamOutput $output,
        KeyReader $reader,
    ): array {
        /** @var array<string, mixed> $values */
        $values = [];
        $index  = 0;

        while ($index < count($this->steps)) {
            $step          = $this->steps[$index];
            $id            = $step['id'];
            $previousValue = $values[$id] ?? null;
            $prompt        = ($step['factory'])($previousValue);

            try {
                $values[$id] = $prompt->prompt($scope, $output, $reader);
                $index++;
            } catch (FormRevertedException) {
                if ($index === 0) {
                    throw new FormRevertedException();
                }
                unset($values[$id]);
                $index--;
            }
        }

        return $values;
    }

    private function addStep(string $id, Closure $factory): self
    {
        $clone          = clone $this;
        $clone->steps[] = ['id' => $id, 'factory' => $factory];

        return $clone;
    }
}
