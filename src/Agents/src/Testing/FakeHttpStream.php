<?php

declare(strict_types=1);

namespace Phalanx\Agents\Testing;

use Phalanx\Scope\Suspendable;

/**
 * Minimal Stream stand-in for tests and demos. Replays a fixed byte string
 * through read() in fixed-size chunks and tracks close() calls.
 *
 * Constructed without any Runtime runtime dependencies so it is safe to use
 * in unit tests, acceptance tests, and demo scripts alike.
 */
final class FakeHttpStream extends \Phalanx\HttpClient\Stream
{
    public int $status { get => $this->fakeStatus; }

    /** @var array<string, list<string>> */
    public array $headerLines { get => []; }

    /** @var array<string, string> */
    public array $headers { get => []; }

    public string $reasonPhrase { get => 'OK'; }

    public string $protocolVersion { get => '1.1'; }

    public bool $eof { get => $this->pos >= strlen($this->body); }

    public bool $closeCalled = false;

    private int $pos = 0;

    public function __construct(
        private readonly string $body,
        private readonly int $fakeStatus = 200,
        private readonly int $chunkSize = 128,
    ) {
    }

    #[\Override]
    public function read(Suspendable $scope, int $bytes = 8192): string
    {
        if ($this->eof) {
            return '';
        }

        $take = min($bytes, $this->chunkSize, strlen($this->body) - $this->pos);
        $chunk = substr($this->body, $this->pos, $take);
        $this->pos += $take;

        return $chunk;
    }

    #[\Override]
    public function close(): void
    {
        $this->closeCalled = true;
        $this->pos = strlen($this->body);
    }

    #[\Override]
    public function abort(string $reason): void
    {
        $this->pos = strlen($this->body);
    }

    #[\Override]
    public function fail(string $reason): void
    {
        $this->pos = strlen($this->body);
    }
}
