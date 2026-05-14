<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Pool;

use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Node\ClassPropertiesNode;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<ClassPropertiesNode>
 */
final class BorrowedValuePromotedPropertyRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.pool.borrowedPromotedProperty';

    public function __construct(private readonly PathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return ClassPropertiesNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->paths->shouldReport($scope->getFile()) || !$node instanceof ClassPropertiesNode) {
            return [];
        }

        $errors = [];
        $borrowed = new ObjectType(\Phalanx\Pool\BorrowedValue::class);

        foreach ($node->getProperties() as $property) {
            $type = $property->getNativeType();
            if ($type === null || !$property->isPromoted() || !$borrowed->isSuperTypeOf($type)->yes()) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message('Borrowed values must not be stored in promoted properties; copy to an owned value first.')
                ->identifier(self::IDENTIFIER)
                ->line($property->getLine())
                ->build();
        }

        return $errors;
    }
}
