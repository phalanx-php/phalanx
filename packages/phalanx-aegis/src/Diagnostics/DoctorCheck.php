<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

final readonly class DoctorCheck
{
    public function __construct(
        public string $name,
        public bool $ok,
        public string $detail = '',
        public Severity $severity = Severity::Required,
    ) {
    }

    /** @return array{name: string, ok: bool, detail: string, severity: string} */
    public function toArray(): array
    {
        return [
            'name'     => $this->name,
            'ok'       => $this->ok,
            'detail'   => $this->detail,
            'severity' => $this->severity->value,
        ];
    }
}
