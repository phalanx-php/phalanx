<?php

declare(strict_types=1);

namespace BgAgents\Specialist;

use BgAgents\Daemon8\ObservationRecord;

/**
 * Slice of the daemon8 observation stream a specialist cares about.
 *
 * All filters are AND-combined. Empty filters mean "match anything in that
 * dimension". The filter is consulted in two places:
 *   - Live: HygieneLane and TeamRunner use ::matches() to gate event dispatch.
 *   - Pull: ContextPackBuilder converts to ObservationQuery for /api/observe.
 */
final readonly class SubscriptionFilter
{
    /**
     * @param list<string> $kinds
     * @param list<string> $tags
     * @param list<string> $origins
     */
    public function __construct(
        public array $kinds = [],
        public array $tags = [],
        public array $origins = [],
        public ?string $severityMin = null,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            kinds: self::stringList($raw['kinds'] ?? []),
            tags: self::stringList($raw['tags'] ?? []),
            origins: self::stringList($raw['origins'] ?? []),
            severityMin: isset($raw['severity_min']) && is_string($raw['severity_min'])
                ? $raw['severity_min']
                : null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->kinds === []
            && $this->tags === []
            && $this->origins === []
            && $this->severityMin === null;
    }

    public function matches(ObservationRecord $record): bool
    {
        if ($this->kinds !== [] && !in_array($record->kindTag, $this->kinds, true)) {
            return false;
        }

        if ($this->tags !== []) {
            foreach ($this->tags as $required) {
                if (!in_array($required, $record->tags, true)) {
                    return false;
                }
            }
        }

        if ($this->origins !== []) {
            $originStr = self::originString($record->origin);
            $matched = false;
            foreach ($this->origins as $candidate) {
                if (str_starts_with($originStr, $candidate)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        if ($this->severityMin !== null) {
            $rank = self::severityRank($record->severity);
            $threshold = self::severityRank($this->severityMin);
            if ($rank < $threshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (is_string($raw)) {
            return [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_filter($raw, is_string(...)));
    }

    /** @param array<string, mixed> $origin */
    private static function originString(array $origin): string
    {
        $type = is_string($origin['type'] ?? null) ? $origin['type'] : '';
        $name = is_string($origin['name'] ?? null) ? $origin['name'] : '';
        return $name === '' ? $type : "{$type}:{$name}";
    }

    private static function severityRank(string $severity): int
    {
        return match ($severity) {
            'trace' => 0,
            'debug' => 1,
            'info' => 2,
            'warn' => 3,
            'error' => 4,
            default => 2,
        };
    }
}
