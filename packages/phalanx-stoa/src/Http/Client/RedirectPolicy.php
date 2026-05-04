<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Http\Client;

/**
 * Outbound HTTP redirect handling rules.
 *
 * Redirects are inherently risky for stateful actions: 301 / 308 force
 * the original method, while 302 / 303 traditionally rewrite to GET in
 * common implementations. The policy exposes both choices explicitly
 * and refuses cross-scheme redirects (https → http) by default to
 * prevent silent downgrade attacks.
 */
final readonly class RedirectPolicy
{
    public function __construct(
        public int $maxRedirects = 5,
        public bool $allowCrossScheme = false,
        public bool $rewriteToGetOn303 = true,
    ) {
    }

    public static function disabled(): self
    {
        return new self(maxRedirects: 0);
    }

    public static function default(): self
    {
        return new self();
    }

    public function follows(int $status): bool
    {
        return $this->maxRedirects > 0 && in_array($status, [301, 302, 303, 307, 308], true);
    }

    public function methodFor(string $originalMethod, int $status): string
    {
        if ($status === 303 && $this->rewriteToGetOn303) {
            return 'GET';
        }

        return strtoupper($originalMethod);
    }
}
