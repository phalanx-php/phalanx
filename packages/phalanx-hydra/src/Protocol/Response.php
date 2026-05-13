<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Protocol;

final class Response
{
    private function __construct(
        private(set) MessageType $type,
        private(set) string $id,
        private(set) bool $ok,
        private(set) mixed $result = null,
        private(set) ?string $errorClass = null,
        private(set) ?string $errorMessage = null,
        private(set) ?string $errorTrace = null,
    ) {
    }

    public static function taskOk(string $id, mixed $result): self
    {
        return new self(
            type: MessageType::TaskResponse,
            id: $id,
            ok: true,
            result: $result,
        );
    }

    public static function taskErr(string $id, \Throwable $error): self
    {
        return new self(
            type: MessageType::TaskResponse,
            id: $id,
            ok: false,
            errorClass: $error::class,
            errorMessage: $error->getMessage(),
            errorTrace: $error->getTraceAsString(),
        );
    }

    public static function serviceOk(string $id, mixed $result): self
    {
        return new self(
            type: MessageType::ServiceResponse,
            id: $id,
            ok: true,
            result: $result,
        );
    }

    public static function serviceErr(string $id, \Throwable $error): self
    {
        return new self(
            type: MessageType::ServiceResponse,
            id: $id,
            ok: false,
            errorClass: $error::class,
            errorMessage: $error->getMessage(),
            errorTrace: $error->getTraceAsString(),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!isset($data['id'], $data['type'], $data['ok'])) {
            throw new \InvalidArgumentException(
                'Response requires "id", "type", and "ok" keys, got: ' . implode(', ', array_keys($data))
            );
        }

        $type = MessageType::from($data['type']);

        if ($data['ok']) {
            return new self(
                type: $type,
                id: $data['id'],
                ok: true,
                result: $data['result'] ?? null,
            );
        }

        return new self(
            type: $type,
            id: $data['id'],
            ok: false,
            errorClass: $data['error'] ?? 'Exception',
            errorMessage: $data['message'] ?? '',
            errorTrace: $data['trace'] ?? '',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'ok' => $this->ok,
            'type' => $this->type->value,
        ];

        if ($this->ok) {
            $data['result'] = $this->result;
        } else {
            $data['error'] = $this->errorClass;
            $data['trace'] = $this->errorTrace;
            $data['message'] = $this->errorMessage;
        }

        return $data;
    }

    public function unwrap(): mixed
    {
        if ($this->ok) {
            return $this->result;
        }

        $class = $this->errorClass ?? \RuntimeException::class;

        if (is_a($class, \Throwable::class, true)) {
            throw new $class($this->errorMessage ?? 'Worker error');
        }

        throw new \RuntimeException($this->errorMessage ?? 'Worker error');
    }
}
