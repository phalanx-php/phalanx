<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * Agent-side declaration of how context is positioned in the assembled
 * prompt envelope. Three slots — `front`, `middle`, `tail` — each carry
 * an ordered list of class-string references to context sources.
 *
 * Assembly (resolving class-strings into actual content) lives in the
 * agent runtime (PA-04+). This type only carries the declaration.
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
    /** @var list<class-string> */
    private(set) array $frontSources;

    /** @var list<class-string> */
    private(set) array $middleSources;

    /** @var list<class-string> */
    private(set) array $tailSources;

    /**
     * @param list<class-string> $front
     * @param list<class-string> $middle
     * @param list<class-string> $tail
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
     * @param class-string ...$sources
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
     * @param class-string ...$sources
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
     * @param class-string ...$sources
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
     * @return list<class-string>
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
     * @return array{front: list<string>, middle: list<string>, tail: list<string>}
     */
    public function toCanonical(): array
    {
        return [
            'front'  => $this->frontSources,
            'middle' => $this->middleSources,
            'tail'   => $this->tailSources,
        ];
    }

    /**
     * @param list<class-string> $sources
     * @return list<class-string>
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
