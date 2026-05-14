<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Pool;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\Pool\BorrowedValue;
use Phalanx\Styx\Channel;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<Node>
 */
final class BorrowedValueBoundaryRule implements Rule
{
    public const string CHANNEL_MESSAGE =
        'Borrowed values must not be emitted through Styx channels; copy to an owned value before emit().';

    public const string ARROW_RETURN_MESSAGE =
        'Borrowed values must not be returned from arrow functions; '
        . 'copy to an owned value before leaving the borrow scope.';

    public const string RETURN_MESSAGE =
        'Borrowed values must not be returned; copy to an owned value before leaving the borrow scope.';

    public const string PROPERTY_MESSAGE =
        'Borrowed values must not be stored on object or static properties; copy to an owned value first.';

    private const string IDENTIFIER = 'phalanx.pool.borrowedBoundary';

    /** @var array<string, array<string, true>> */
    private array $borrowedClosureVariables = [];

    public function __construct(private readonly PathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->paths->shouldReport($scope->getFile())) {
            return [];
        }

        if ($node instanceof MethodCall) {
            return $this->checkChannelEmit($node, $scope);
        }

        if ($this->paths->isInternal($scope->getFile())) {
            return [];
        }

        if (
            $node instanceof Return_
            && $node->expr instanceof Expr
            && $this->containsBorrowedValue($node->expr, $scope)
        ) {
            return [
                $this->error(
                    $node->expr instanceof ArrowFunction ? self::ARROW_RETURN_MESSAGE : self::RETURN_MESSAGE,
                    $node->getLine(),
                ),
            ];
        }

        if ($node instanceof Yield_ && $node->value instanceof Expr && $this->containsBorrowedValue($node->value, $scope)) {
            return [
                $this->error(self::RETURN_MESSAGE, $node->getLine()),
            ];
        }

        if ($node instanceof YieldFrom && $this->containsBorrowedValue($node->expr, $scope)) {
            return [
                $this->error(self::RETURN_MESSAGE, $node->getLine()),
            ];
        }

        if ($node instanceof Assign) {
            $this->recordBorrowedClosureVariable($node, $scope);

            if ($this->containsBorrowedValue($node->expr, $scope) && $this->isPropertyAssignmentTarget($node->var)) {
                return [
                    $this->error(self::PROPERTY_MESSAGE, $node->getLine()),
                ];
            }
        }

        if (
            $node instanceof AssignOp
            && $this->containsBorrowedValue($node->expr, $scope)
            && $this->isPropertyAssignmentTarget($node->var)
        ) {
            return [
                $this->error(self::PROPERTY_MESSAGE, $node->getLine()),
            ];
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkChannelEmit(MethodCall $call, Scope $scope): array
    {
        $method = NodeNames::calledMethodName($call);
        if ($method !== 'emit' && $method !== 'tryEmit') {
            return [];
        }

        if (!(new ObjectType(Channel::class))->isSuperTypeOf($scope->getType($call->var))->yes()) {
            return [];
        }

        foreach ($call->args as $arg) {
            if ($arg instanceof Node\Arg && $this->containsBorrowedValue($arg->value, $scope)) {
                return [
                    $this->error(self::CHANNEL_MESSAGE, $arg->getLine()),
                ];
            }
        }

        return [];
    }

    private function containsBorrowedValue(Expr $expr, Scope $scope): bool
    {
        if ($expr instanceof Variable && $this->isBorrowedClosureVariable($expr, $scope)) {
            return true;
        }

        if ($this->isBorrowedType($scope->getType($expr))) {
            return true;
        }

        if (!$expr instanceof Array_) {
            if (!$expr instanceof ClosureExpr && !$expr instanceof ArrowFunction) {
                return false;
            }

            return $this->closureCapturesBorrowed($expr, $scope);
        }

        foreach ($expr->items as $item) {
            if ($item !== null && $this->containsBorrowedValue($item->value, $scope)) {
                return true;
            }
        }

        return false;
    }

    private function recordBorrowedClosureVariable(Assign $assign, Scope $scope): void
    {
        if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
            return;
        }

        $file = $scope->getFile();
        $name = $assign->var->name;
        if (
            ($assign->expr instanceof ClosureExpr || $assign->expr instanceof ArrowFunction)
            && $this->closureCapturesBorrowed($assign->expr, $scope)
        ) {
            $this->borrowedClosureVariables[$file][$name] = true;
            return;
        }

        unset($this->borrowedClosureVariables[$file][$name]);
    }

    private function isBorrowedClosureVariable(Variable $variable, Scope $scope): bool
    {
        return is_string($variable->name)
            && isset($this->borrowedClosureVariables[$scope->getFile()][$variable->name]);
    }

    private function closureCapturesBorrowed(ClosureExpr|ArrowFunction $closure, Scope $scope): bool
    {
        if ($closure instanceof ClosureExpr) {
            foreach ($closure->uses as $use) {
                if ($this->containsBorrowedValue($use->var, $scope)) {
                    return true;
                }
            }

            foreach ($closure->stmts as $stmt) {
                if ($this->nodeReferencesBorrowed($stmt, $scope)) {
                    return true;
                }
            }

            return false;
        }

        return $this->nodeReferencesBorrowed($closure->expr, $scope);
    }

    private function nodeReferencesBorrowed(Node $node, Scope $scope, int $depth = 0): bool
    {
        if ($depth > 32) {
            return false;
        }

        if ($node instanceof Expr) {
            if ($node instanceof Variable && $this->isBorrowedClosureVariable($node, $scope)) {
                return true;
            }

            if ($this->isBorrowedType($scope->getType($node))) {
                return true;
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            if ($value instanceof Node && $this->nodeReferencesBorrowed($value, $scope, $depth + 1)) {
                return true;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->nodeReferencesBorrowed($item, $scope, $depth + 1)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isPropertyAssignmentTarget(Expr $expr): bool
    {
        if ($expr instanceof PropertyFetch || $expr instanceof StaticPropertyFetch) {
            return true;
        }

        return $expr instanceof ArrayDimFetch && $this->isPropertyAssignmentTarget($expr->var);
    }

    private function isBorrowedType(\PHPStan\Type\Type $type): bool
    {
        if ($type instanceof NeverType) {
            return false;
        }

        if ((new ObjectType(BorrowedValue::class))->isSuperTypeOf($type)->yes()) {
            return true;
        }

        if ($type->isIterable()->yes()) {
            return $this->isBorrowedType($type->getIterableValueType());
        }

        return false;
    }

    private function error(string $message, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier(self::IDENTIFIER)
            ->line($line)
            ->build();
    }
}
