<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation\Record;

use Phalanx\Panoply\Conversation\Record;
use Phalanx\Panoply\Conversation\RecordType;

/**
 * A permission decision recorded during the agent's execution. The
 * `mode` describes whether the tool or action was allowed outright,
 * required user confirmation, or was denied. `scope` narrows which
 * tool or path pattern the decision applied to.
 */
final class PermissionMode extends Record
{
    final public RecordType $type { get => RecordType::PermissionMode; }

    public function __construct(
        string $id,
        ?int $sequence,
        \DateTimeImmutable $at,
        private(set) PermissionMode\Mode $mode,
        private(set) ?string $scope = null,
    ) {
        parent::__construct($id, $sequence, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'mode'  => $this->mode->value,
            'scope' => $this->scope,
        ];
    }
}
