<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Runtime;

use Phalanx\PHPStan\Support\RuleErrors;
use PhpParser\Node;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Name>
 */
final class NoGenericLifecycleRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.lifecycle.noGenericLifecycleBag';

    public function getNodeType(): string
    {
        return Name::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $resolved = $node->getAttribute('resolvedName');
        $class = $resolved instanceof Name ? $resolved->toString() : $node->toString();

        if (!str_starts_with($class, 'Phalanx\\Lifecycle\\')) {
            return [];
        }

        return RuleErrors::build(
            'Use concrete Phalanx lifecycle surfaces: module ::starting(), ServiceConfig hooks, scope onDispose(), and cancellation onCancel(); do not use generic lifecycle callback bags.',
            self::IDENTIFIER,
            $node->getStartLine(),
        );
    }
}
