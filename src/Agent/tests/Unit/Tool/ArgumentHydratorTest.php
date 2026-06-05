<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Unit\Tool;

use Phalanx\Agent\Effect\Context as EffectContext;
use Phalanx\Agent\Effect\Outcome as EffectOutcome;
use Phalanx\Agent\Effect\Resolution;
use Phalanx\Agent\Tool\ArgumentHydrator;
use Phalanx\Agent\Tool\Param;
use Phalanx\Agent\Tool\Tool;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArgumentHydratorTest extends TestCase
{
    #[Test]
    public function hydratesStringArgument(): void
    {
        $result = ArgumentHydrator::hydrate(['path' => '/tmp/test'], StringArgTool::class);

        self::assertSame('/tmp/test', $result['path']);
    }

    #[Test]
    public function coercesStringToInt(): void
    {
        $result = ArgumentHydrator::hydrate(['count' => '42'], IntArgTool::class);

        self::assertSame(42, $result['count']);
    }

    #[Test]
    public function coercesStringToFloat(): void
    {
        $result = ArgumentHydrator::hydrate(['ratio' => '3.14'], FloatArgTool::class);

        self::assertEqualsWithDelta(3.14, $result['ratio'], 0.001);
    }

    #[Test]
    public function coercesStringToBool(): void
    {
        $result = ArgumentHydrator::hydrate(['enabled' => 'true'], BoolArgTool::class);

        self::assertTrue($result['enabled']);
    }

    #[Test]
    public function usesDefaultValueWhenMissing(): void
    {
        $result = ArgumentHydrator::hydrate([], DefaultArgTool::class);

        self::assertSame('default_value', $result['path']);
    }

    #[Test]
    public function nullableParamReceivesNullWhenMissing(): void
    {
        $result = ArgumentHydrator::hydrate([], NullableArgTool::class);

        self::assertNull($result['label']);
    }

    #[Test]
    public function throwsOnMissingRequiredArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required argument: path');

        ArgumentHydrator::hydrate([], StringArgTool::class);
    }

    #[Test]
    public function emptyResultForToolWithNoConstructor(): void
    {
        $result = ArgumentHydrator::hydrate(['ignored' => 'data'], NoCtorTool::class);

        self::assertSame([], $result);
    }
}

// --- Fixture tools ---

final class StringArgTool implements Tool
{
    public function __construct(
        #[Param('File path')]
        private(set) string $path,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool);
    }
}

final class IntArgTool implements Tool
{
    public function __construct(
        #[Param('Count')]
        private(set) int $count,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool);
    }
}

final class FloatArgTool implements Tool
{
    public function __construct(
        #[Param('Ratio')]
        private(set) float $ratio,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool);
    }
}

final class BoolArgTool implements Tool
{
    public function __construct(
        #[Param('Enabled')]
        private(set) bool $enabled,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool);
    }
}

final class DefaultArgTool implements Tool
{
    public function __construct(
        #[Param('Path', required: false, default: 'default_value')]
        private(set) string $path = 'default_value',
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool);
    }
}

final class NullableArgTool implements Tool
{
    public function __construct(
        #[Param('Label')]
        private(set) ?string $label = null,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool);
    }
}

final class NoCtorTool implements Tool
{
    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool);
    }
}
