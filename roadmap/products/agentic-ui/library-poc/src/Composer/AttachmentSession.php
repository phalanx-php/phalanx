<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Composer;

final class AttachmentSession
{
    /** @var array<string, array> */
    private array $uploads = [];

    public function start(string $sessionId): string
    {
        $id = bin2hex(random_bytes(8));
        $this->uploads[$id] = ['session' => $sessionId, 'files' => []];
        return $id;
    }

    public function addFile(string $uploadId, array $fileMeta): void
    {
        $this->uploads[$uploadId]['files'][] = $fileMeta;
    }

    public function complete(string $uploadId): array
    {
        return $this->uploads[$uploadId] ?? [];
    }
}
