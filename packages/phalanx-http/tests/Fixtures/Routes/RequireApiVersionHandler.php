<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\Http\Contract\Header;
use Phalanx\Http\Contract\RequiresHeaders;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

/**
 * Test fixture: declares a required X-Api-Version header that must match
 * the pattern v\d+. Used to verify RequiresHeaders enforcement at dispatch.
 */
final class RequireApiVersionHandler implements Scopeable, RequiresHeaders
{
    /** @var list<Header> */
    public array $requiredHeaders {
        get => [Header::required('X-Api-Version', pattern: 'v\d+')];
    }

    /** @return array{ok: bool} */
    public function __invoke(Scope $scope): array
    {
        return ['ok' => true];
    }
}
