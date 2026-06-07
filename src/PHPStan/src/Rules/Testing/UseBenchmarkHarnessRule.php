<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Testing;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\RuleErrors;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<StaticCall>
 */
final class UseBenchmarkHarnessRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.testing.useBenchmarkHarness';

    private const array TARGET_CLASSES = [
        'Phalanx\\Application',
        'Phalanx\\Http\\Http',
        'Phalanx\\Console\\Console',
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (!self::isInBenchmarkDirectory($scope->getFile())) {
            return [];
        }

        if (self::isBenchmarkInfrastructure($scope->getFile())) {
            return [];
        }

        $class = NodeNames::calledClassName($node, $scope);
        $method = NodeNames::calledMethodName($node);

        if ($method !== 'starting') {
            return [];
        }

        if (!in_array($class, self::TARGET_CLASSES, true)) {
            return [];
        }

        return RuleErrors::build(
            sprintf(
                'Benchmarks should boot through BenchmarkHarness instead of %s::starting(). '
                . 'Bypassing the harness skips pool-stats collection, ZMM tracking, and scope-clean assertions.',
                $class,
            ),
            self::IDENTIFIER,
            $node->getStartLine(),
        );
    }

    private static function isInBenchmarkDirectory(string $file): bool
    {
        return str_contains(str_replace('\\', '/', $file), '/benchmarks/');
    }

    private static function isBenchmarkInfrastructure(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        return str_contains($normalized, '/benchmarks/_kit/')
            || str_ends_with($normalized, '/BenchmarkCase.php');
    }
}
