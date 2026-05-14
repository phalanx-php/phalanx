<?php

declare(strict_types=1);

namespace Phalanx\Athena\Http;

use RuntimeException;

/**
 * Coroutine-aware Guzzle handler stack factory.
 *
 * Many third-party SDKs (AWS, Stripe, official OpenAI/Anthropic SDKs) ship
 * with Guzzle as their HTTP client. Guzzle's default cURL handler blocks
 * the OpenSwoole reactor; the {@see \Hyperf\Guzzle\CoroutineHandler} adapter
 * swaps it for a coroutine-native client backed by `OpenSwoole\Coroutine\Http\Client`.
 *
 * This helper returns a configured `\GuzzleHttp\HandlerStack` so callers can
 * pass it to any Guzzle-using SDK:
 *
 * ```php
 * $client = new \GuzzleHttp\Client(['handler' => CoroutineGuzzleStack::create()]);
 * ```
 *
 * Both `guzzlehttp/guzzle` and `hyperf/guzzle` are opt-in via composer suggest.
 * Calling this without either installed raises a clear {@see RuntimeException}
 * naming the missing dependency.
 */
final class CoroutineGuzzleStack
{
    /**
     * Build a coroutine-aware Guzzle handler stack.
     *
     * @return object \GuzzleHttp\HandlerStack - typed as object so this file
     *               parses cleanly even when Guzzle is not installed.
     */
    public static function create(): object
    {
        self::ensureDependencies(class_exists(...));

        /** @var class-string $stack */
        $stack = \GuzzleHttp\HandlerStack::class;
        /** @var class-string $handler */
        $handler = \Hyperf\Guzzle\CoroutineHandler::class;

        return $stack::create(new $handler());
    }

    /**
     * @internal
     * @param callable(class-string): bool $classExists
     */
    public static function ensureDependencies(callable $classExists): void
    {
        if (!$classExists(\GuzzleHttp\HandlerStack::class)) {
            throw new RuntimeException(
                'guzzlehttp/guzzle is not installed. Add it to your composer.json '
                . 'to use CoroutineGuzzleStack with Guzzle-based SDKs.',
            );
        }

        if (!$classExists(\Hyperf\Guzzle\CoroutineHandler::class)) {
            throw new RuntimeException(
                'hyperf/guzzle is not installed. Add `hyperf/guzzle` to your '
                . 'composer.json so Guzzle uses an OpenSwoole-native handler '
                . 'instead of blocking cURL.',
            );
        }
    }
}
