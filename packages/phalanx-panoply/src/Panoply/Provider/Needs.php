<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * Agent-side declaration of provider selection preferences and required
 * capabilities. Resolution lives in `Provider\Registry` (PA-04.02); this
 * type only carries what the agent asks for.
 *
 * Build fluently:
 *
 * ```php
 * Needs::new()
 *     ->prefer(Preference::LocalFirst)
 *     ->fallback(Preference::Hosted)
 *     ->require(Capability::Reasoning, Capability::ToolUse);
 * ```
 */
final class Needs implements Canonicalizable
{
    /** @var list<Preference> */
    private(set) array $preferences;

    private(set) Capabilities $required;

    /**
     * @param list<Preference> $preferences
     */
    private function __construct(array $preferences = [], ?Capabilities $required = null)
    {
        $this->preferences = self::dedup($preferences);
        $this->required = $required ?? Capabilities::empty();
    }

    public static function new(): self
    {
        return new self();
    }

    public function prefer(Preference $preference): self
    {
        return new self(
            [$preference, ...array_values(array_filter(
                $this->preferences,
                static fn (Preference $p): bool => $p !== $preference,
            ))],
            $this->required,
        );
    }

    public function fallback(Preference $preference): self
    {
        if (in_array($preference, $this->preferences, strict: true)) {
            return $this;
        }

        return new self(
            [...$this->preferences, $preference],
            $this->required,
        );
    }

    public function require(Capability ...$capabilities): self
    {
        if ($capabilities === []) {
            return $this;
        }

        return new self(
            $this->preferences,
            $this->required->with(...$capabilities),
        );
    }

    public function hasPreference(Preference $preference): bool
    {
        return in_array($preference, $this->preferences, strict: true);
    }

    public function isEmpty(): bool
    {
        return $this->preferences === [] && $this->required->isEmpty();
    }

    /**
     * @return array{preferences: list<string>, required: array{cases: list<string>, custom: list<string>}}
     */
    public function toCanonical(): array
    {
        return [
            'preferences' => array_map(static fn (Preference $p): string => $p->value, $this->preferences),
            'required'    => $this->required->toCanonical(),
        ];
    }

    /**
     * @param list<Preference> $preferences
     * @return list<Preference>
     */
    private static function dedup(array $preferences): array
    {
        $seen = [];
        $out = [];
        foreach ($preferences as $preference) {
            if (isset($seen[$preference->value])) {
                continue;
            }
            $seen[$preference->value] = true;
            $out[] = $preference;
        }

        return $out;
    }
}
