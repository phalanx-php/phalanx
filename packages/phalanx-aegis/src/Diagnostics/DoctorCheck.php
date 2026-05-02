<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

final readonly class DoctorCheck
{
    public function __construct(
        public string $name,
        public bool $ok,
        public string $detail = '',
    ) {
    }

    /** @return array{name: string, ok: bool, detail: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'ok' => $this->ok,
            'detail' => $this->detail,
        ];
    }
}
