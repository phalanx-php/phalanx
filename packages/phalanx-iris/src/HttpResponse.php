<?php

declare(strict_types=1);

namespace Phalanx\Iris;

/**
 * @phpstan-type HeaderMap array<string, list<string>>
 */
final class HttpResponse
{
    public bool $successful {
        get => $this->status >= 200 && $this->status < 300;
    }

    /** @param HeaderMap $headers */
    public function __construct(
        public private(set) int $status,
        public private(set) string $reasonPhrase,
        public private(set) array $headers,
        public private(set) string $body,
        public private(set) string $protocolVersion = '1.1',
    ) {
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);
        foreach ($this->headers as $h => $values) {
            if (strtolower($h) === $key && $values !== []) {
                return $values[0];
            }
        }

        return null;
    }
}
