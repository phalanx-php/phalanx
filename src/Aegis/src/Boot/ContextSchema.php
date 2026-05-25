<?php

declare(strict_types=1);

namespace Phalanx\Boot;

class ContextSchema
{
    /** @param list<ContextKey> $keys */
    private function __construct(private(set) array $keys)
    {
    }

    public static function of(ContextKey ...$keys): self
    {
        return new self(array_values($keys));
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** @return list<ContextKey> */
    public function all(): array
    {
        return $this->keys;
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    public function merge(self $other): self
    {
        if ($other->isEmpty()) {
            return $this;
        }
        if ($this->isEmpty()) {
            return $other;
        }

        return new self([...$this->keys, ...$other->keys]);
    }

    public function ownedBy(string $owner): self
    {
        return new self(array_map(
            static fn(ContextKey $key): ContextKey => $key->ownedBy($owner),
            $this->keys,
        ));
    }

    public function harness(): BootHarness
    {
        return BootHarness::of(...array_map(
            static fn(ContextKey $key): BootRequirement => $key->toRequirement(),
            $this->keys,
        ));
    }

    public function render(): string
    {
        if ($this->keys === []) {
            return 'No context keys registered.';
        }

        $lines = ['Context keys:'];

        foreach ($this->keys as $key) {
            $required = $key->required ? 'required' : 'optional';
            $owner = $key->owner === null ? '' : sprintf(' [%s]', $key->owner);
            $type = $key->type === null ? '' : sprintf(' <%s>', $key->type);
            $fallback = $key->fallback === null ? '' : sprintf(' default=%s', $key->fallback);

            $lines[] = sprintf(
                '- %s%s %s%s%s - %s',
                $key->name,
                $type,
                $required,
                $fallback,
                $owner,
                $key->description,
            );
        }

        return implode(PHP_EOL, $lines);
    }
}
