<?php

declare(strict_types=1);

namespace Phalanx\Harness\Work;

use Phalanx\Harness\Message\Address;
use Phalanx\Panoply\Id;

final class WorkItem
{
    /** @var list<string> */
    private(set) array $dependsOn;

    /** @var list<string> */
    private(set) array $tags;

    /**
     * @param list<string> $dependsOn
     * @param list<string> $tags
     */
    public function __construct(
        private(set) Activity $activity,
        private(set) string $prompt,
        array $dependsOn = [],
        array $tags = [],
        private(set) ?Address $preferredParticipant = null,
        private(set) int $priority = 0,
        private(set) bool $critical = false,
        private(set) ?string $id = null,
    ) {
        if (trim($this->prompt) === '') {
            throw new \InvalidArgumentException('Work item prompt cannot be empty.');
        }

        $this->id ??= self::newId();
        $this->dependsOn = self::dedup($dependsOn);
        $this->tags = self::dedup($tags);

        if (in_array($this->id, $this->dependsOn, true)) {
            throw new \InvalidArgumentException('Work item cannot depend on itself.');
        }
    }

    /**
     * @param list<string> $completedIds
     */
    public function isBlockedBy(array $completedIds): bool
    {
        return array_diff($this->dependsOn, $completedIds) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'id' => $this->id,
            'activity' => $this->activity,
            'prompt' => $this->prompt,
            'depends_on' => $this->dependsOn,
            'tags' => $this->tags,
            'preferred_participant' => $this->preferredParticipant?->toCanonical(),
            'priority' => $this->priority,
            'critical' => $this->critical,
        ];
    }

    private static function newId(): string
    {
        return 'work_' . Id::generate();
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function dedup(array $values): array
    {
        $seen = [];
        $out = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $out[] = $value;
        }

        return $out;
    }
}
