<?php

declare(strict_types=1);

namespace Phalanx\AiProviders;

use Phalanx\AiProviders\Hash\Canonicalizable;

/**
 * Final — canonical hash determinism: subclassing would alter toCanonical()
 * and break hash stability across consumers.
 *
 * Immutable set of {@see Capability} cases plus opaque "custom" strings
 * for vendor-specific surfaces. All mutating operations return a new
 * instance — agents and provider configs are expected to declare their
 * capabilities once and pass the instance by value.
 */
final class Capabilities implements Canonicalizable
{
    /** @var list<Capability> */
    private(set) array $cases;

    /** @var list<string> */
    private(set) array $custom;

    /**
     * @param list<Capability> $cases
     * @param list<string>     $custom
     */
    public function __construct(array $cases = [], array $custom = [])
    {
        $this->cases = self::dedupCases($cases);
        $this->custom = self::dedupStrings($custom);
    }

    public static function of(Capability ...$cases): self
    {
        return new self(array_values($cases));
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Stable serialization for hashing/diagnostics. Cases sorted by value;
     * custom tags sorted lexicographically.
     *
     * @return array{cases: list<string>, custom: list<string>}
     */
    public function toCanonical(): array
    {
        $cases = array_map(static fn (Capability $c): string => $c->value, $this->cases);
        sort($cases);

        $custom = $this->custom;
        sort($custom);

        return ['cases' => $cases, 'custom' => $custom];
    }

    public function with(Capability ...$cases): self
    {
        if (empty($cases)) {
            return $this;
        }

        return new self([...$this->cases, ...array_values($cases)], $this->custom);
    }

    public function withCustom(string ...$tags): self
    {
        if (empty($tags)) {
            return $this;
        }

        return new self($this->cases, [...$this->custom, ...array_values($tags)]);
    }

    public function without(Capability ...$cases): self
    {
        if (empty($cases)) {
            return $this;
        }

        $remove = array_fill_keys(array_map(static fn (Capability $c): string => $c->value, $cases), true);

        return new self(
            array_values(array_filter(
                $this->cases,
                static fn (Capability $c): bool => !isset($remove[$c->value]),
            )),
            $this->custom,
        );
    }

    public function has(Capability $capability): bool
    {
        return array_any($this->cases, static fn (Capability $existing): bool => $existing === $capability);
    }

    public function hasCustom(string $tag): bool
    {
        return in_array($tag, $this->custom, strict: true);
    }

    /**
     * True only when every requested capability is present.
     */
    public function satisfies(Capability ...$required): bool
    {
        $cases = $this->cases;

        return array_all(
            $required,
            static fn (Capability $capability): bool
                => array_any($cases, static fn (Capability $existing): bool => $existing === $capability),
        );
    }

    public function intersect(self $other): self
    {
        $caseLookup = array_fill_keys(
            array_map(static fn (Capability $c): string => $c->value, $other->cases),
            true,
        );

        $sharedCases = array_values(array_filter(
            $this->cases,
            static fn (Capability $c): bool => isset($caseLookup[$c->value]),
        ));

        $customLookup = array_fill_keys($other->custom, true);
        $sharedCustom = array_values(array_filter(
            $this->custom,
            static fn (string $tag): bool => isset($customLookup[$tag]),
        ));

        return new self($sharedCases, $sharedCustom);
    }

    public function union(self $other): self
    {
        return new self(
            [...$this->cases, ...$other->cases],
            [...$this->custom, ...$other->custom],
        );
    }

    public function isEmpty(): bool
    {
        return $this->cases === [] && $this->custom === [];
    }

    /**
     * @param list<Capability> $cases
     * @return list<Capability>
     */
    private static function dedupCases(array $cases): array
    {
        $seen = [];
        $out = [];
        foreach ($cases as $case) {
            if (isset($seen[$case->value])) {
                continue;
            }
            $seen[$case->value] = true;
            $out[] = $case;
        }

        return $out;
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private static function dedupStrings(array $tags): array
    {
        $seen = [];
        $out = [];
        foreach ($tags as $tag) {
            if (isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $out[] = $tag;
        }

        return $out;
    }
}
