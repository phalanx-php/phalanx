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
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Stmt\Return_;
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
    private const string IDENTIFIER = 'phalanx.pool.borrowedBoundary';

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

        if ($node instanceof ArrowFunction && $this->containsBorrowedValue($node->expr, $scope)) {
            return [
                $this->error(
                    'Borrowed values must not be returned from arrow functions; copy to an owned value before leaving the borrow scope.',
                    $node->getLine(),
                ),
            ];
        }

        if ($node instanceof Return_ && $node->expr instanceof Expr && $this->containsBorrowedValue($node->expr, $scope)) {
            return [
                $this->error(
                    'Borrowed values must not be returned; copy to an owned value before leaving the borrow scope.',
                    $node->getLine(),
                ),
            ];
        }

        if ($node instanceof Assign && $this->containsBorrowedValue($node->expr, $scope)) {
            if ($node->var instanceof PropertyFetch || $node->var instanceof StaticPropertyFetch) {
                return [
                    $this->error(
                        'Borrowed values must not be stored on object or static properties; copy to an owned value first.',
                        $node->getLine(),
                    ),
                ];
            }
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
                    $this->error(
                        'Borrowed values must not be emitted through Styx channels; copy to an owned value before emit().',
                        $arg->getLine(),
                    ),
                ];
            }
        }

        return [];
    }

    private function containsBorrowedValue(Expr $expr, Scope $scope): bool
    {
        if ($this->isBorrowedType($scope->getType($expr))) {
            return true;
        }

        if (!$expr instanceof Array_) {
            return false;
        }

        foreach ($expr->items as $item) {
            if ($item !== null && $this->containsBorrowedValue($item->value, $scope)) {
                return true;
            }
        }

        return false;
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
