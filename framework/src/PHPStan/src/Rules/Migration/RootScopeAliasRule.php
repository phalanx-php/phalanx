<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Migration;

use PhpParser\Node;
use PhpParser\Node\Stmt\Use_;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Use_>
 */
final class RootScopeAliasRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.migration.rootScopeAlias';

    /** @var array<string, string> */
    private const array REPLACEMENTS = [
        'Phalanx\\Scope' => 'Phalanx\\Scope\\Scope',
        'Phalanx\\ExecutionScope' => 'Phalanx\\Scope\\ExecutionScope',
    ];

    public function __construct(private readonly PathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return Use_::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Use_ || !$this->paths->shouldReport($scope->getFile())) {
            return [];
        }

        foreach ($node->uses as $use) {
            $resolved = $scope->resolveName($use->name);
            if (!isset(self::REPLACEMENTS[$resolved])) {
                continue;
            }

            return RuleErrors::build(
                sprintf(
                    'Use %s instead of stale root-level %s.',
                    self::REPLACEMENTS[$resolved],
                    $resolved,
                ),
                self::IDENTIFIER,
                $use->getLine(),
            );
        }

        return [];
    }
}
