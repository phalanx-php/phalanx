<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Transaction;

use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<MethodCall>
 */
final class TransactionCallbackScopeRule implements Rule
{
    private const IDENTIFIER = 'phalanx.transaction.callbackScope';

    public function __construct(private readonly PathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (NodeNames::calledMethodName($node) !== 'transaction' || !$this->paths->shouldReport($scope->getFile())) {
            return [];
        }

        $callback = $node->args[1]->value ?? null;
        if (!$callback instanceof Closure) {
            return [];
        }

        $firstParam = $callback->params[0] ?? null;
        if ($firstParam === null) {
            return [];
        }

        $type = NodeNames::resolvedTypeName($firstParam->type, $scope);
        if ($type !== 'Phalanx\\Scope\\ExecutionScope') {
            return [];
        }

        return RuleErrors::build(
            'Transaction callbacks must accept Phalanx\\Scope\\TransactionScope, not full ExecutionScope; transactions must not expose fan-out or worker dispatch.',
            self::IDENTIFIER,
            $firstParam->getLine(),
        );
    }
}
