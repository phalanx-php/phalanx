<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Runtime;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\RuleErrors;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Node>
 */
final class NoGenericLifecycleRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.lifecycle.noGenericLifecycleBag';

    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = match (true) {
            $node instanceof New_ => NodeNames::newClassName($node, $scope),
            $node instanceof StaticCall => NodeNames::calledClassName($node, $scope),
            $node instanceof ClassConstFetch => NodeNames::classConstantClassName($node, $scope),
            default => null,
        };

        if ($class === null || !str_starts_with($class, 'Phalanx\\Lifecycle\\')) {
            return [];
        }

        return RuleErrors::build(
            'Use concrete Phalanx lifecycle surfaces: module ::starting(), ServiceConfig hooks, scope onDispose(), and cancellation onCancel(); do not use generic lifecycle callback bags.',
            self::IDENTIFIER,
            $node->getStartLine(),
        );
    }
}
