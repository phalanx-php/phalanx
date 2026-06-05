<?php

declare(strict_types=1);

namespace Phalanx\AiProviders;

use Phalanx\AiProviders\Hash\Canonicalizable;

/**
 * Final — canonical hash determinism: subclassing would alter toCanonical()
 * and break hash stability across consumers.
 *
 * Agent-side declaration of how context is positioned in the assembled
 * prompt envelope. Three slots — `front`, `middle`, `tail` — each carry
 * an ordered list of string identifiers for context sources (typically
 * class names, but any stable identifier is accepted).
 *
 * Assembly (resolving source identifiers into actual content) lives in the
 * agent runtime. This type only carries the declaration.
 *
 * Build fluently:
 *
 * ```php
 * Context::new()
 *     ->front(Mission::class, Constraints::class)
 *     ->middle(SourceExcerpts::class, PriorArtifacts::class)
 *     ->tail(OutputShape::class, Question::class);
 * ```
 *
 * The front-middle-tail split is policy: LLMs weight beginning and end of
 * prompts heavily, so mission/constraints belong at the front, output
 * schema and the immediate question belong at the tail, source material
 * sits in the middle.
 */
final class Context implements Canonicalizable
{
    /** @var list<string> */
    private(set) array $frontSources;

    /** @var list<string> */
    private(set) array $middleSources;

    /** @var list<string> */
    private(set) array $tailSources;

    /**
     * @param list<string> $front
     * @param list<string> $middle
     * @param list<string> $tail
     */
    public function __construct(array $front = [], array $middle = [], array $tail = [])
    {
        $this->frontSources = self::dedup($front);
        $this->middleSources = self::dedup($middle);
        $this->tailSources = self::dedup($tail);
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * @return array{front: list<string>, middle: list<string>, tail: list<string>}
     */
    final public function toCanonical(): array
    {
        return [
            'front' => $this->frontSources,
            'middle' => $this->middleSources,
            'tail' => $this->tailSources,
        ];
    }

    /**
     * @param string ...$sources
     */
    public function front(string ...$sources): self
    {
        if ($sources === []) {
            return $this;
        }

        return new self(
            [...$this->frontSources, ...array_values($sources)],
            $this->middleSources,
            $this->tailSources,
        );
    }

    /**
     * @param string ...$sources
     */
    public function middle(string ...$sources): self
    {
        if ($sources === []) {
            return $this;
        }

        return new self(
            $this->frontSources,
            [...$this->middleSources, ...array_values($sources)],
            $this->tailSources,
        );
    }

    /**
     * @param string ...$sources
     */
    public function tail(string ...$sources): self
    {
        if ($sources === []) {
            return $this;
        }

        return new self(
            $this->frontSources,
            $this->middleSources,
            [...$this->tailSources, ...array_values($sources)],
        );
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return [...$this->frontSources, ...$this->middleSources, ...$this->tailSources];
    }

    public function isEmpty(): bool
    {
        return $this->frontSources === []
            && $this->middleSources === []
            && $this->tailSources === [];
    }

    /**
     * @param list<string> $sources
     * @return list<string>
     */
    private static function dedup(array $sources): array
    {
        $seen = [];
        $out = [];
        foreach ($sources as $source) {
            if (isset($seen[$source])) {
                continue;
            }
            $seen[$source] = true;
            $out[] = $source;
        }

        return $out;
    }
}
