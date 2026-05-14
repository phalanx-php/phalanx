<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use UnitEnum;

class RequestCtx
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * @template T
     * @param RequestCtxKey<T> $key
     * @param T $value
     */
    public function set(RequestCtxKey $key, mixed $value): void
    {
        $this->values[self::storageKey($key)] = $value;
    }

    /**
     * @template T
     * @param RequestCtxKey<T> $key
     * @return T|null
     */
    public function get(RequestCtxKey $key): mixed
    {
        /** @var T|null $value */
        $value = $this->values[self::storageKey($key)] ?? null;

        return $value;
    }

    /**
     * @template T
     * @param RequestCtxKey<T> $key
     * @return T
     */
    public function require(RequestCtxKey $key): mixed
    {
        $storageKey = self::storageKey($key);
        if (!array_key_exists($storageKey, $this->values)) {
            throw MissingRequestCtxValue::forKey($key);
        }

        /** @var T $value */
        $value = $this->values[$storageKey];

        return $value;
    }

    /** @param RequestCtxKey<mixed> $key */
    public function has(RequestCtxKey $key): bool
    {
        return array_key_exists(self::storageKey($key), $this->values);
    }

    /** @param RequestCtxKey<mixed> $key */
    public function remove(RequestCtxKey $key): void
    {
        unset($this->values[self::storageKey($key)]);
    }

    /** @internal */
    public function clear(): void
    {
        $this->values = [];
    }

    /** @param RequestCtxKey<mixed> $key */
    private static function storageKey(RequestCtxKey $key): string
    {
        if ($key instanceof UnitEnum) {
            return $key::class . '::' . $key->name;
        }

        return $key::class . '#' . $key->key();
    }
}
