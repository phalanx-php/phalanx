<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Messages;

final class Address
{
    private(set) string $identity;

    private(set) ?string $role;

    private function __construct(
        string $identity,
        ?string $role = null,
    ) {
        $identity = trim($identity);
        $role = $role === null ? null : trim($role);

        if ($identity === '') {
            throw new \InvalidArgumentException('Address identity cannot be empty.');
        }

        if ($role === '') {
            throw new \InvalidArgumentException('Address role cannot be empty.');
        }

        $this->identity = $identity;
        $this->role = $role;
    }

    public static function user(?string $id = null): self
    {
        return new self($id ?? 'user', 'user');
    }

    public static function agent(string $id): self
    {
        return new self('agent:' . self::requirePart($id, 'agent id'), 'agent');
    }

    public static function service(string $name): self
    {
        return new self('service:' . self::requirePart($name, 'service name'), 'service');
    }

    public static function system(): self
    {
        return new self('system', 'system');
    }

    public static function named(string $identity, ?string $role = null): self
    {
        return new self($identity, $role);
    }

    public function equals(self $other): bool
    {
        return $this->identity === $other->identity
            && $this->role === $other->role;
    }

    public function toString(): string
    {
        return $this->identity;
    }

    /**
     * @return array{identity: string, role: string|null}
     */
    public function toCanonical(): array
    {
        return [
            'identity' => $this->identity,
            'role' => $this->role,
        ];
    }

    private static function requirePart(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("Address {$label} cannot be empty.");
        }

        return $value;
    }
}
