<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Testing;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\PHPStan\Support\TestingPathPolicy;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<\PhpParser\Node>
 */
final class NoRawTestSleepRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.testing.noRawSleep';

    public function __construct(private readonly TestingPathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return \PhpParser\Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (!$this->paths->shouldReport($scope->getFile(), self::IDENTIFIER)) {
            return [];
        }

        if ($node instanceof StaticCall) {
            return $this->processStaticCall($node, $scope);
        }

        if ($node instanceof FuncCall) {
            return $this->processFunctionCall($node, $scope);
        }

        return [];
    }

    /** @return list<IdentifierRuleError> */
    private function processStaticCall(StaticCall $node, Scope $scope): array
    {
        $class = NodeNames::calledClassName($node, $scope);
        $method = NodeNames::calledMethodName($node);

        if ($class === null || $method === null) {
            return [];
        }

        if (
            ($class === 'Swoole\\Coroutine' && in_array($method, ['sleep', 'usleep'], true))
            || ($class === 'Phalanx\\Concurrency\\Co' && $method === 'sleep')
        ) {
            return RuleErrors::build(
                sprintf(
                    'High-level Phalanx tests should not use %s::%s(); use deterministic TestApp/lens probes, $scope->delay(...), or an await helper instead.',
                    $class,
                    $method,
                ),
                self::IDENTIFIER,
                $node->getStartLine(),
            );
        }

        return [];
    }

    /** @return list<IdentifierRuleError> */
    private function processFunctionCall(FuncCall $node, Scope $scope): array
    {
        $function = NodeNames::functionName($node, $scope);

        if (!in_array($function, ['sleep', 'usleep'], true)) {
            return [];
        }

        return RuleErrors::build(
            sprintf(
                'High-level Phalanx tests should not use %s(); use deterministic TestApp/lens probes, $scope->delay(...), or an await helper instead.',
                $function,
            ),
            self::IDENTIFIER,
            $node->getStartLine(),
        );
    }
}
