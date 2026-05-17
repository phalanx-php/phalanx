<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Runtime;

use Phalanx\Panoply\Cue;

final class Error extends Cue
{
    final public string $type { get => 'cue.runtime.error'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $message,
        private(set) ?string $code = null,
        private(set) ?string $errorClass = null,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'message'     => $this->message,
            'code'        => $this->code,
            'error_class' => $this->errorClass,
        ];
    }
}
