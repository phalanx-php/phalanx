<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Transport\Fake;

use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Transport as TransportContract;
use Phalanx\Panoply\Transport\Request;

/**
 * Scriptable {@see TransportContract} for wire-protocol testing.
 * The constructor accepts a script map keyed by request signature
 * ("METHOD URL") to a list of byte chunks. {@see self::stream()} yields
 * the scripted chunks in order, checking runtime cancellation between
 * each chunk. {@see self::requests()} accumulates every request made for
 * post-test inspection.
 *
 * Final — extension would alter the request-recording contract that
 * tests depend on.
 */
final class Transport implements TransportContract
{
    /** @var list<Request> */
    private array $requests = [];

    /**
     * @param array<string, list<string>> $script keyed by "METHOD URL"
     */
    public function __construct(
        private(set) array $script = [],
    ) {
    }

    /**
     * @return \Generator<int, string>
     */
    public function stream(Request $request, Runtime $runtime): \Generator
    {
        $this->requests[] = $request;

        $signature = $request->method . ' ' . $request->url;

        if (!isset($this->script[$signature])) {
            throw new UnscriptedRequest("No script entry for: {$signature}");
        }

        foreach ($this->script[$signature] as $chunk) {
            $runtime->throwIfCancelled();
            yield $chunk;
        }
    }

    /**
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->requests;
    }
}
