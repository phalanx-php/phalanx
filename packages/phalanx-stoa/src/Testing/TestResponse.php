<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Testing;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Userland-friendly assertion surface over a PSR-7 response.
 *
 * Mirrors the test-response API conventions of Laravel and Symfony so test
 * authors arriving from those frameworks can read assertions without
 * relearning the surface. The underlying PSR-7 response remains accessible
 * via the public `psr` property for cases the assertion vocabulary doesn't
 * cover.
 */
final class TestResponse
{
    /** @var array<int|string, mixed>|null */
    private ?array $decodedBody = null;

    public function __construct(public readonly ResponseInterface $psr)
    {
    }

    public function status(): int
    {
        return $this->psr->getStatusCode();
    }

    public function body(): string
    {
        return (string) $this->psr->getBody();
    }

    /** @return array<int|string, mixed> */
    public function json(): array
    {
        if ($this->decodedBody !== null) {
            return $this->decodedBody;
        }

        $body = $this->body();
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Response body is not valid JSON: '
                . (strlen($body) > 200 ? substr($body, 0, 200) . '...' : $body),
            );
        }

        return $this->decodedBody = $decoded;
    }

    public function assertStatus(int $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->status(),
            "Expected status {$expected}; got {$this->status()}.\nBody: " . $this->body(),
        );

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertUnprocessable(): self
    {
        return $this->assertStatus(422);
    }

    public function assertHeader(string $name, string $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->psr->getHeaderLine($name),
            "Header '{$name}' did not match.",
        );

        return $this;
    }

    public function assertHeaderMissing(string $name): self
    {
        Assert::assertFalse(
            $this->psr->hasHeader($name),
            "Header '{$name}' was unexpectedly present.",
        );

        return $this;
    }

    public function assertBodyContains(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->body());

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        Assert::assertSame(
            $expected,
            self::extract($this->json(), $path),
            "JSON path '{$path}' did not match.",
        );

        return $this;
    }

    public function assertJsonStructure(array $structure): self
    {
        self::assertStructure($structure, $this->json(), '');

        return $this;
    }

    /** @param array<int|string, mixed> $payload */
    private static function extract(array $payload, string $path): mixed
    {
        $cursor = $payload;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                Assert::fail("JSON path '{$path}' is missing at segment '{$segment}'.");
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param array<int|string, mixed> $structure
     * @param array<int|string, mixed> $actual
     */
    private static function assertStructure(array $structure, array $actual, string $path): void
    {
        foreach ($structure as $key => $value) {
            if (is_int($key)) {
                $childPath = $path === '' ? (string) $value : $path . '.' . $value;
                Assert::assertArrayHasKey(
                    $value,
                    $actual,
                    "JSON structure missing key at '{$childPath}'.",
                );
                continue;
            }

            $childPath = $path === '' ? $key : $path . '.' . $key;
            Assert::assertArrayHasKey(
                $key,
                $actual,
                "JSON structure missing key at '{$childPath}'.",
            );

            $next = $actual[$key];

            if (is_array($value)) {
                Assert::assertIsArray(
                    $next,
                    "JSON structure expected array at '{$childPath}'.",
                );

                /** @var array<int|string, mixed> $nextArray */
                $nextArray = $next;
                self::assertStructure($value, $nextArray, $childPath);
            }
        }
    }
}
