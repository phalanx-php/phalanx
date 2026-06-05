<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit;

use Phalanx\Http\HttpRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class HttpRunnerBannerTest extends TestCase
{
    #[Test]
    public function resolvesListenPlaceholder(): void
    {
        self::assertSame(
            'Listening on 127.0.0.1:8080',
            self::resolve('Listening on {listen}', '127.0.0.1:8080'),
        );
    }

    #[Test]
    public function resolvesUrlPlaceholderWithHttpScheme(): void
    {
        self::assertSame(
            'Visit http://127.0.0.1:8080/docs',
            self::resolve('Visit {url}/docs', '127.0.0.1:8080'),
        );
    }

    #[Test]
    public function convertsWildcardHostToLocalhostInUrl(): void
    {
        $result = self::resolve('{listen} -> {url}', '0.0.0.0:9090');

        self::assertSame('0.0.0.0:9090 -> http://127.0.0.1:9090', $result);
    }

    #[Test]
    public function preservesNonWildcardHosts(): void
    {
        self::assertSame(
            'http://10.0.1.5:3000',
            self::resolve('{url}', '10.0.1.5:3000'),
        );
    }

    #[Test]
    public function resolvesBothPlaceholdersInComplexBanner(): void
    {
        $banner = <<<'BANNER'
            Server running
            Raw: {listen}
            URL: {url}/api
            BANNER;

        $result = self::resolve($banner, '0.0.0.0:8080');

        self::assertStringContainsString('Raw: 0.0.0.0:8080', $result);
        self::assertStringContainsString('URL: http://127.0.0.1:8080/api', $result);
    }

    #[Test]
    public function throwsOnInvalidListenAddress(): void
    {
        $this->expectException(RuntimeException::class);

        self::resolve('{url}', 'no-port');
    }

    private static function resolve(string $banner, string $listen): string
    {
        $method = new ReflectionMethod(HttpRunner::class, 'resolveBanner');

        return $method->invoke(null, $banner, $listen);
    }
}
