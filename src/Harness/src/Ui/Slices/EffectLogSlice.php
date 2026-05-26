<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

class EffectLogSlice
{
    /** @param list<EffectLogEntry> $entries */
    public function __construct(
        private(set) array $entries = [],
    ) {
    }

    public function appendRequested(PendingEffect $effect): self
    {
        return new self([...$this->without($effect->effectId), EffectLogEntry::requested($effect)]);
    }

    /** @param list<string> $reasonCodes */
    public function mark(
        string $effectId,
        EffectStatus $status,
        array $reasonCodes = [],
        ?string $grantId = null,
        ?int $durationMs = null,
        ?string $errorClass = null,
    ): self {
        $entries = $this->entries;

        foreach ($entries as $i => $entry) {
            if ($entry->effectId !== $effectId) {
                continue;
            }

            $entries[$i] = $entry->withStatus(
                status: $status,
                reasonCodes: $reasonCodes,
                grantId: $grantId,
                durationMs: $durationMs,
                errorClass: $errorClass,
            );

            return new self($entries);
        }

        return $this;
    }

    /** @return list<EffectLogEntry> */
    private function without(string $effectId): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(EffectLogEntry $entry): bool => $entry->effectId !== $effectId,
        ));
    }
}
