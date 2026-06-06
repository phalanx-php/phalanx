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
 * Flags direct entry boot/build calls inside integration/feature test files
 * where TestApp boot is the canonical entry. Encourages users to consume
 * PhalanxTestCase::testApp() so cleanup, fakes, and lens activation are
 * handled uniformly.
 *
 * @implements Rule<StaticCall>
 */
final class UseTestAppRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.testing.useTestApp';

    private const array TARGET_METHODS_BY_CLASS = [
        'Phalanx\\Application' => ['starting'],
        'Phalanx\\Http\\Server' => ['starting'],
        'Phalanx\\Console\\Console' => ['starting', 'command'],
        'Phalanx\\Agents\\Agents' => ['starting'],
    ];

    private const array TEST_DIRECTORIES = [
        '/tests/Integration/',
        '/tests/Feature/',
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (!self::isInTestDirectory($scope->getFile())) {
            return [];
        }

        $class = NodeNames::calledClassName($node, $scope);
        $method = NodeNames::calledMethodName($node);

        if ($class === null || $method === null) {
            return [];
        }

        if (!in_array($method, self::TARGET_METHODS_BY_CLASS[$class] ?? [], true)) {
            return [];
        }

        return RuleErrors::build(
            sprintf(
                'Integration tests should boot through PhalanxTestCase::testApp() instead of %s::%s(). '
                . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                $class,
                $method,
            ),
            self::IDENTIFIER,
            $node->getStartLine(),
        );
    }

    private static function isInTestDirectory(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        foreach (self::TEST_DIRECTORIES as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
