<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Ollama;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Ndjson\Reader;
use Phalanx\Panoply\Provider as ProviderContract;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Stream;
use Phalanx\Panoply\Transport as TransportContract;

/**
 * Ollama Chat API provider. Translates Ollama's NDJSON wire format into
 * the canonical panoply {@see Cue} stream.
 *
 * Ollama is an auth-free local provider — no API key is required. The
 * NDJSON wire format is distinct from SSE: each response line is a
 * self-contained JSON object without an outer event structure. The
 * {@see Reader} handles chunk accumulation and line splitting.
 *
 * Composes a {@see TransportContract} and a resolved {@see Model} config.
 * The provider itself is stateless — all state lives in the {@see CueMapper}
 * allocated per call.
 *
 *
 * Final — sealed provider contract; extension would alter the NDJSON mapping
 * invariants tests depend on.
 */
final class ChatProvider implements ProviderContract
{
    public function __construct(
        private(set) TransportContract $transport,
        private(set) Model $model,
        private(set) string $baseUrl = 'http://localhost:11434',
        private(set) ChatOptions $chatOptions = new ChatOptions(),
    ) {
    }

    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        $request   = RequestBuilder::build($invocation, $this->model, $this->baseUrl, $this->chatOptions);
        $transport = $this->transport;
        $mapper    = new CueMapper($invocation);

        return new Stream(static function () use ($transport, $request, $runtime, $mapper): \Generator {
            $reader = new Reader();

            foreach ($transport->stream($request, $runtime) as $chunk) {
                foreach ($reader->feed($chunk) as $line) {
                    yield from $mapper->translate($line);
                }
            }

            foreach ($reader->flush() as $line) {
                yield from $mapper->translate($line);
            }

            yield from $mapper->complete();
        });
    }

    public function capabilities(): Capabilities
    {
        return $this->model->capabilities;
    }
}
