<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactor;

final class ReactorGroup
{
    /** @var array<string, BackgroundReactor> */
    private array $reactors = [];

    public function register(BackgroundReactor $reactor): void
    {
        $existing = $this->reactors[$reactor->id] ?? null;

        if ($existing !== null && $reactor->exclusivity === ReactorExclusivity::Exclusive) {
            $existing->cancel();
        }

        $this->reactors[$reactor->id] = $reactor;
    }

    public function cancel(string $id): void
    {
        $reactor = $this->reactors[$id] ?? null;
        $reactor?->cancel();
    }

    public function cancelGroup(string $group): void
    {
        foreach ($this->reactors as $reactor) {
            if ($reactor->group === $group) {
                $reactor->cancel();
            }
        }
    }

    public function cancelAll(): void
    {
        foreach ($this->reactors as $reactor) {
            $reactor->cancel();
        }
    }

    /** @return array<string, ReactorState> */
    public function states(): array
    {
        $states = [];

        foreach ($this->reactors as $id => $reactor) {
            $states[$id] = $reactor->state;
        }

        return $states;
    }

    /** @return array<string, ReactorState> */
    public function stateOf(string $group): array
    {
        $states = [];

        foreach ($this->reactors as $id => $reactor) {
            if ($reactor->group === $group) {
                $states[$id] = $reactor->state;
            }
        }

        return $states;
    }

    public function get(string $id): ?BackgroundReactor
    {
        return $this->reactors[$id] ?? null;
    }
}
