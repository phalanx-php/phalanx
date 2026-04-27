<?php

declare(strict_types=1);

namespace Phalanx\Auth;

final class AuthContext
{
    public bool $isAuthenticated {
        get => $this->identity !== null;
    }

    public function __construct(
        public private(set) ?Identity $identity = null,
        private readonly ?string $accessToken = null,
        /** @var list<string> */
        private readonly array $abilities = [],
    ) {
    }

    public static function guest(): self
    {
        return new self();
    }

    /** @param list<string> $abilities */
    public static function authenticated(Identity $identity, ?string $token = null, array $abilities = []): self
    {
        return new self($identity, $token, $abilities);
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    public function token(): ?string
    {
        return $this->accessToken;
    }
}
