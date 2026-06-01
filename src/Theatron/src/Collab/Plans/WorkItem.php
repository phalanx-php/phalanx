<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Plans;

use Phalanx\Theatron\Collab\Internal\Id;
use Phalanx\Theatron\Collab\Internal\StringList;
use Phalanx\Theatron\Collab\Messages\Address;

final class WorkItem
{
    private(set) string $id;

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
        ?string $id = null,
    ) {
        if (trim($this->prompt) === '') {
            throw new \InvalidArgumentException('Work item prompt cannot be empty.');
        }

        $this->id = $id === null ? self::newId() : self::requireId($id);
        $this->dependsOn = StringList::unique($dependsOn);
        $this->tags = StringList::unique($tags);

        if (in_array($this->id, $this->dependsOn, true)) {
            throw new \InvalidArgumentException('Work item cannot depend on itself.');
        }
    }

    /**
     * @param list<string> $completedIds
     */
    public function isBlockedBy(array $completedIds): bool
    {
        return $this->missingDependencies($completedIds) !== [];
    }

    /**
     * @param list<string> $completedIds
     * @return list<string>
     */
    public function missingDependencies(array $completedIds): array
    {
        return array_values(array_diff($this->dependsOn, $completedIds));
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
        return Id::new('work');
    }

    private static function requireId(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('Work item id cannot be empty.');
        }

        return $id;
    }
}
