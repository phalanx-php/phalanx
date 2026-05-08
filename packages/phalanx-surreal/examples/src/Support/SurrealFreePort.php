<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Examples\Support;

/**
 * Allocates an ephemeral local port by binding to TCP port 0 and
 * reading the assigned port number back from the socket name.
 */
final class SurrealFreePort
{
    public function __invoke(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');

        if ($server === false) {
            return random_int(20_000, 40_000);
        }

        $name = stream_socket_get_name($server, false);
        fclose($server);

        if (!is_string($name)) {
            return random_int(20_000, 40_000);
        }

        $port = parse_url('tcp://' . $name, PHP_URL_PORT);

        return is_int($port) ? $port : random_int(20_000, 40_000);
    }
}
