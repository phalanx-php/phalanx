<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Testing;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\PHPStan\Support\TestingPathPolicy;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<MethodCall>
 */
final class UseTestScopeRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.testing.useTestScope';

    private const array APP_HOST_TYPES = [
        'Phalanx\\Application',
        'Phalanx\\AppHost',
    ];

    public function __construct(private readonly TestingPathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (!$this->paths->shouldReport($scope->getFile(), self::IDENTIFIER)) {
            return [];
        }

        if (NodeNames::calledMethodName($node) !== 'createScope') {
            return [];
        }

        $receiver = $scope->getType($node->var);
        foreach (self::APP_HOST_TYPES as $type) {
            if ((new ObjectType($type))->isSuperTypeOf($receiver)->yes()) {
                return RuleErrors::build(
                    'High-level Phalanx tests should use $this->scope->run(...), $this->testApp(...), '
                    . 'or a package lens instead of direct createScope(); direct scopes bypass managed cleanup expectations.',
                    self::IDENTIFIER,
                    $node->getStartLine(),
                );
            }
        }

        return [];
    }
}
