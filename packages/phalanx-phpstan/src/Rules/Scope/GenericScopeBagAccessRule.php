<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Scope;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\Scope\Scope as PhalanxScope;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<MethodCall>
 */
final class GenericScopeBagAccessRule implements Rule
{
    public const string MESSAGE =
        'Do not use generic scope bags across framework boundaries; expose typed scope properties or scoped services instead.';

    private const string IDENTIFIER = 'phalanx.scope.genericBagAccess';

    /** @var array<string, true> */
    private const array BAG_METHODS = [
        'attribute' => true,
        'resource' => true,
        'setResource' => true,
    ];

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
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof MethodCall) {
            return [];
        }

        if (!$this->paths->shouldReport($scope->getFile()) || $this->paths->isInternal($scope->getFile())) {
            return [];
        }

        $method = NodeNames::calledMethodName($node);
        if ($method === null || !isset(self::BAG_METHODS[$method])) {
            return [];
        }

        if ($node->var instanceof MethodCall && NodeNames::calledMethodName($node->var) === 'innerScope') {
            return [];
        }

        if (!(new ObjectType(PhalanxScope::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        return RuleErrors::build(self::MESSAGE, self::IDENTIFIER, $node->getLine());
    }
}
