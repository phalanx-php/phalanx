<?php

declare(strict_types=1);

namespace Phalanx\Skopos\LiveReload;

use OpenSwoole\Http\Response;
use Phalanx\Cancellation\Cancelled;
use Throwable;

/**
 * Subscribed-client registry for the LiveReload SSE channel.
 *
 * One instance per Skopos application. Subscribers are OpenSwoole HTTP
 * Response objects held open by the SSE handler in {@see Server}. reload()
 * writes the canonical "data: reload\n\n" frame to every active client and
 * prunes any that fail to receive it.
 */
final class BroadcasterChannel
{
    /** @var array<int, Response> spl_object_id => Response */
    private array $clients = [];

    /** Returns the subscription id used for unsubscribe(). */
    public function subscribe(Response $response): int
    {
        $id = spl_object_id($response);
        $this->clients[$id] = $response;
        return $id;
    }

    public function unsubscribe(int $id): void
    {
        unset($this->clients[$id]);
    }

    public function reload(): void
    {
        $this->writeAll("data: reload\n\n");
    }

    public function clientCount(): int
    {
        return count($this->clients);
    }

    public function closeAll(): void
    {
        foreach ($this->clients as $response) {
            try {
                if ($response->isWritable()) {
                    $response->end();
                }
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable) {
                // best-effort close; the runtime cleans up the fd regardless.
            }
        }

        $this->clients = [];
    }

    private function writeAll(string $payload): void
    {
        foreach ($this->clients as $id => $response) {
            $delivered = false;

            try {
                if ($response->isWritable()) {
                    $delivered = $response->write($payload) !== false;
                }
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable) {
                $delivered = false;
            }

            if (!$delivered) {
                unset($this->clients[$id]);
            }
        }
    }
}
