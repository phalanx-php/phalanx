<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Cancellation;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\TryCatch;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<TryCatch>
 */
final class NoCancelledShortcutRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.cancellation.noCancelledShortcut';

    public function __construct(private readonly PathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return TryCatch::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (
            $this->paths->isInternal($scope->getFile())
            || !$this->paths->shouldReport($scope->getFile())
        ) {
            return [];
        }

        $cancelledHandledEarlier = false;
        foreach ($node->catches as $catch) {
            if ($this->catchesCancelled($catch, $scope)) {
                if (!$this->catchesThrowable($catch, $scope) || $this->bodyPreservesCancellation($catch, $scope)) {
                    $cancelledHandledEarlier = true;
                }
            }

            if (!$this->catchesThrowable($catch, $scope)) {
                continue;
            }

            if ($cancelledHandledEarlier || $this->bodyPreservesCancellation($catch, $scope)) {
                continue;
            }

            return RuleErrors::build(
                'catch (Throwable|\Exception) must rethrow or explicitly preserve Phalanx\\Cancellation\\Cancelled; swallowed cancellation makes a cancelled task look successful.',
                self::IDENTIFIER,
                $catch->getLine(),
            );
        }

        return [];
    }

    private function catchesThrowable(Catch_ $catch, Scope $scope): bool
    {
        foreach ($catch->types as $type) {
            $resolved = $scope->resolveName($type);
            if ($resolved === 'Throwable' || $resolved === 'Exception') {
                return true;
            }
        }

        return false;
    }

    private function catchesCancelled(Catch_ $catch, Scope $scope): bool
    {
        foreach ($catch->types as $type) {
            if ($scope->resolveName($type) === 'Phalanx\\Cancellation\\Cancelled') {
                return true;
            }
        }

        return false;
    }

    private function bodyPreservesCancellation(Catch_ $catch, Scope $scope): bool
    {
        $catchVariable = $this->catchVariableName($catch);

        if (
            $this->catchesCancelled($catch, $scope)
            && $this->containsThrow($catch->stmts)
        ) {
            return true;
        }

        foreach ($catch->stmts as $stmt) {
            if ($this->rethrowsCaughtException($stmt, $catchVariable)) {
                return true;
            }

            if (
                $stmt instanceof If_
                && $this->preservesCancelledInConditional($stmt, $catchVariable, $scope)
            ) {
                return true;
            }
        }

        return false;
    }

    private function rethrowsCaughtException(Node\Stmt $stmt, ?string $catchVariable): bool
    {
        if ($catchVariable === null || !$stmt instanceof Expression || !$stmt->expr instanceof Throw_) {
            return false;
        }

        return $stmt->expr->expr instanceof Variable
            && $stmt->expr->expr->name === $catchVariable;
    }

    private function preservesCancelledInConditional(If_ $if, ?string $catchVariable, Scope $scope): bool
    {
        $guardPreservesCancellation = $catchVariable !== null
            && $this->conditionChecksCancelled($if->cond, $catchVariable, $scope)
            && $this->containsThrow($if->stmts);

        if ($guardPreservesCancellation) {
            return true;
        }

        foreach ($if->stmts as $stmt) {
            if (
                $stmt instanceof If_
                && $this->preservesCancelledInConditional($stmt, $catchVariable, $scope)
            ) {
                return true;
            }
        }

        foreach ($if->elseifs as $elseIf) {
            $elseifPreservesCancellation = $catchVariable !== null
                && $this->conditionChecksCancelled($elseIf->cond, $catchVariable, $scope)
                && $this->containsThrow($elseIf->stmts);

            if ($elseifPreservesCancellation) {
                return true;
            }
        }

        $elseStatements = $if->else === null ? [] : $if->else->stmts;
        foreach ($elseStatements as $stmt) {
            if (
                $stmt instanceof If_
                && $this->preservesCancelledInConditional($stmt, $catchVariable, $scope)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<Node\Stmt> $statements
     */
    private function containsThrow(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof Expression && $stmt->expr instanceof Throw_) {
                return true;
            }

            if ($stmt instanceof If_ && $this->containsThrow($stmt->stmts)) {
                return true;
            }
        }

        return false;
    }

    private function conditionChecksCancelled(Node\Expr $condition, string $catchVariable, Scope $scope): bool
    {
        if ($condition instanceof BooleanAnd || $condition instanceof BooleanOr) {
            return $this->conditionChecksCancelled($condition->left, $catchVariable, $scope)
                || $this->conditionChecksCancelled($condition->right, $catchVariable, $scope);
        }

        return $condition instanceof Instanceof_
            && $condition->expr instanceof Variable
            && $condition->expr->name === $catchVariable
            && $condition->class instanceof Name
            && $scope->resolveName($condition->class) === 'Phalanx\\Cancellation\\Cancelled';
    }

    private function catchVariableName(Catch_ $catch): ?string
    {
        if (!$catch->var instanceof Variable || !is_string($catch->var->name)) {
            return null;
        }

        return $catch->var->name;
    }
}
