<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Http\Client;

/**
 * @phpstan-type HeaderMap array<string, list<string>>
 */
final readonly class StoaHttpResponse
{
    /** @param HeaderMap $headers */
    public function __construct(
        public int $status,
        public string $reasonPhrase,
        public array $headers,
        public string $body,
        public string $protocolVersion = '1.1',
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
