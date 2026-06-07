<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Testing;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\PHPStan\Support\TestingPathPolicy;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<StaticCall>
 */
final class UseTestAppRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.testing.useTestApp';

    private const array TARGET_METHODS_BY_CLASS = [
        'Phalanx\\Application' => ['starting'],
        'Phalanx\\Http\\Server' => ['starting'],
        'Phalanx\\Console\\Console' => ['starting', 'command'],
        'Phalanx\\DevServer\\DevServer' => ['starting'],
        'Phalanx\\Tui\\Tui' => ['app', 'starting'],
    ];

    public function __construct(private readonly TestingPathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (!$this->paths->shouldReport($scope->getFile(), self::IDENTIFIER)) {
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
                'High-level Phalanx tests should boot through PhalanxTestCase::testApp() instead of %s::%s(). '
                . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                $class,
                $method,
            ),
            self::IDENTIFIER,
            $node->getStartLine(),
        );
    }
}
