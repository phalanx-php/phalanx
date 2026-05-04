<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Http\Client;

use Phalanx\Stoa\Http\Client\RedirectPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedirectPolicyTest extends TestCase
{
    #[Test]
    public function defaultsFollowUpToFiveRedirectsAndKeepScheme(): void
    {
        $policy = RedirectPolicy::default();

        self::assertSame(5, $policy->maxRedirects);
        self::assertFalse($policy->allowCrossScheme);
        self::assertTrue($policy->rewriteToGetOn303);
    }

    #[Test]
    public function disabledNeverFollows(): void
    {
        $policy = RedirectPolicy::disabled();

        self::assertFalse($policy->follows(301));
        self::assertFalse($policy->follows(302));
        self::assertFalse($policy->follows(307));
    }

    #[Test]
    public function followsRecognizedRedirectStatusesOnly(): void
    {
        $policy = RedirectPolicy::default();

        foreach ([301, 302, 303, 307, 308] as $code) {
            self::assertTrue($policy->follows($code), "Should follow {$code}");
        }

        foreach ([200, 201, 304, 400, 500] as $code) {
            self::assertFalse($policy->follows($code), "Should not follow {$code}");
        }
    }

    #[Test]
    public function methodFor303RewritesToGetByDefault(): void
    {
        $policy = RedirectPolicy::default();

        self::assertSame('GET', $policy->methodFor('POST', 303));
    }

    #[Test]
    public function methodFor301PreservesOriginalMethod(): void
    {
        $policy = RedirectPolicy::default();

        self::assertSame('POST', $policy->methodFor('POST', 301));
        self::assertSame('PUT', $policy->methodFor('PUT', 308));
    }
}
