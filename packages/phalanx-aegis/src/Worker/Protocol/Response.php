<?php

declare(strict_types=1);

namespace Phalanx\Worker\Protocol;

final readonly class Response
{
    public const string KIND_OK = 'ok';

    public const string KIND_ERR = 'err';

    public function __construct(
        public string $id,
        public string $kind,
        public string $serializedValue,
    ) {
    }

    public static function ok(string $id, mixed $value): self
    {
        return new self($id, self::KIND_OK, serialize($value));
    }

    public static function err(string $id, \Throwable $error): self
    {
        return new self($id, self::KIND_ERR, serialize([
            'class' => $error::class,
            'message' => $error->getMessage(),
        ]));
    }
}
