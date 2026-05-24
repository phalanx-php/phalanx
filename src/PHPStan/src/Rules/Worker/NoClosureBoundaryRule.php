<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Worker;

use Closure;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\VariadicPlaceholder;
use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\Scope\TaskExecutor;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<MethodCall>
 */
final class NoClosureBoundaryRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.worker.noClosureBoundary';

    public function __construct(
        private readonly PathPolicy $paths,
    ) {
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
        $method = NodeNames::calledMethodName($node);
        if ($method === null || !in_array($method, ['inWorker', 'parallel', 'settleParallel'], true)) {
            return [];
        }

        if (!$this->paths->shouldReport($scope->getFile())) {
            return [];
        }

        if (!(new ObjectType(TaskExecutor::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        foreach ($node->args as $arg) {
            if ($arg instanceof VariadicPlaceholder) {
                continue;
            }

            $task = $arg->value;
            if (
                $task instanceof ArrowFunction
                || $task instanceof \PhpParser\Node\Expr\Closure
                || $this->isTaskFactoryClosure($task, $scope)
                || ($arg->unpack && $this->arrayContainsClosureBoundary($task, $scope))
            ) {
                return $this->error($node, $method);
            }

            $type = $scope->getType($task);
            if ((new ObjectType(Closure::class))->isSuperTypeOf($type)->yes()) {
                return $this->error($node, $method);
            }
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function error(MethodCall $node, string $method): array
    {
        return RuleErrors::build(
            sprintf(
                '%s() cannot receive a closure or Task::of() closure adapter; pass serializable WorkerTask objects.',
                $method,
            ),
            self::IDENTIFIER,
            $node->getLine(),
        );
    }

    private function isTaskFactoryClosure(\PhpParser\Node\Expr $expr, Scope $scope): bool
    {
        if (!$expr instanceof StaticCall) {
            return false;
        }

        $class = NodeNames::calledClassName($expr, $scope);
        $method = NodeNames::calledMethodName($expr);

        return $class === 'Phalanx\\Task\\Task'
            && in_array($method, ['named', 'of'], true);
    }

    private function arrayContainsClosureBoundary(\PhpParser\Node\Expr $expr, Scope $scope): bool
    {
        if (!$expr instanceof Array_) {
            return false;
        }

        foreach ($expr->items as $item) {
            if ($item === null) {
                continue;
            }

            $value = $item->value;
            if (
                $value instanceof ArrowFunction
                || $value instanceof \PhpParser\Node\Expr\Closure
                || $this->isTaskFactoryClosure($value, $scope)
            ) {
                return true;
            }
        }

        return false;
    }
}
