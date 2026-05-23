<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Worker;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\VariadicPlaceholder;
use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Worker\WorkerTask;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<MethodCall>
 */
final class WorkerTaskOnlyRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.worker.workerTaskOnly';

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
    public function processNode(Node $node, Scope $scope): array
    {
        $method = NodeNames::calledMethodName($node);
        if ($method === null || !in_array($method, ['inWorker', 'parallel', 'settleParallel', 'mapParallel'], true)) {
            return [];
        }

        if (!$this->paths->shouldReport($scope->getFile())) {
            return [];
        }

        if (!(new ObjectType(TaskExecutor::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        if ($method === 'mapParallel') {
            return $this->checkMapParallel($node, $scope);
        }

        foreach ($node->args as $arg) {
            if ($arg instanceof VariadicPlaceholder) {
                continue;
            }

            if ($this->isClosureBoundary($arg->value, $scope)) {
                continue;
            }

            if ($arg->unpack) {
                if ($this->unpackedArrayContainsNonWorkerTask($arg->value, $scope)) {
                    return $this->error($method, $arg->getLine());
                }

                continue;
            }

            $type = $scope->getType($arg->value);
            if ((new ObjectType(WorkerTask::class))->isSuperTypeOf($type)->no()) {
                return $this->error($method, $arg->getLine());
            }
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkMapParallel(MethodCall $node, Scope $scope): array
    {
        $factory = $node->args[1]->value ?? null;
        if ($factory instanceof ArrowFunction) {
            $returnType = $scope->getType($factory->expr);
            if (!(new ObjectType(WorkerTask::class))->isSuperTypeOf($returnType)->no()) {
                return [];
            }

            return $this->error('mapParallel', $factory->getLine());
        }

        if (!$factory instanceof Closure) {
            return [];
        }

        $return = $this->singleReturnExpression($factory);
        if ($return === null) {
            return [];
        }

        $returnType = $scope->getType($return);
        if (!(new ObjectType(WorkerTask::class))->isSuperTypeOf($returnType)->no()) {
            return [];
        }

        return $this->error('mapParallel', $factory->getLine());
    }

    private function unpackedArrayContainsNonWorkerTask(Node\Expr $expr, Scope $scope): bool
    {
        if (!$expr instanceof Array_) {
            return false;
        }

        foreach ($expr->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($this->isClosureBoundary($item->value, $scope)) {
                continue;
            }

            $type = $scope->getType($item->value);
            if ((new ObjectType(WorkerTask::class))->isSuperTypeOf($type)->no()) {
                return true;
            }
        }

        return false;
    }

    private function isClosureBoundary(Node\Expr $expr, Scope $scope): bool
    {
        if ($expr instanceof ArrowFunction || $expr instanceof Closure) {
            return true;
        }

        if (!$expr instanceof StaticCall) {
            return false;
        }

        $class = NodeNames::calledClassName($expr, $scope);
        $method = NodeNames::calledMethodName($expr);

        return $class === 'Phalanx\\Task\\Task'
            && in_array($method, ['named', 'of'], true);
    }

    private function singleReturnExpression(Closure $closure): ?Node\Expr
    {
        if (count($closure->stmts) !== 1) {
            return null;
        }

        $statement = $closure->stmts[0];
        if (!$statement instanceof Return_) {
            return null;
        }

        return $statement->expr;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function error(string $method, int $line): array
    {
        return RuleErrors::build(
            sprintf('%s() can only dispatch named objects implementing Phalanx\\Worker\\WorkerTask.', $method),
            self::IDENTIFIER,
            $line,
        );
    }
}
