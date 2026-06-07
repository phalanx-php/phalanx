<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Testing;

use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\PHPStan\Support\TestingPathPolicy;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<PropertyFetch>
 */
final class DirectTestAppApplicationRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.testing.directTestAppApplication';

    public function __construct(private readonly TestingPathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return PropertyFetch::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (!$this->paths->shouldReport($scope->getFile(), self::IDENTIFIER)) {
            return [];
        }

        if (!$node->name instanceof Identifier || $node->name->toString() !== 'application') {
            return [];
        }

        if (!(new ObjectType('Phalanx\\Testing\\TestApp'))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        return RuleErrors::build(
            'High-level Phalanx tests must not reach through TestApp->application; use TestApp::scoped(), '
            . 'TestApp::start(), TestApp::runtime(), TestApp::supervisor(), or a package lens.',
            self::IDENTIFIER,
            $node->getStartLine(),
        );
    }
}
