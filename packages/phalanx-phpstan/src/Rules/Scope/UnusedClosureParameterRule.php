<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Scope;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node\FunctionLike>
 */
final class UnusedClosureParameterRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.scope.unusedClosureParameter';

    public function getNodeType(): string
    {
        return Node\FunctionLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Closure && !$node instanceof ArrowFunction) {
            return [];
        }

        $errors = [];

        foreach ($node->getParams() as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $name = $param->var->name;

            if (str_starts_with($name, '_')) {
                continue;
            }

            if ($this->isUsedInBody($name, $node)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                sprintf(
                    'Closure parameter $%s is never used. Prefix with underscore ($_%s) if intentionally unused.',
                    $name,
                    $name,
                ),
            )
                ->identifier(self::IDENTIFIER)
                ->line($param->getLine())
                ->build();
        }

        return $errors;
    }

    private function isUsedInBody(string $name, Closure|ArrowFunction $node): bool
    {
        $body = $node instanceof ArrowFunction
            ? [$node->expr]
            : $node->stmts;

        if ($body === null || $body === []) {
            return false;
        }

        $finder = new NodeFinder();
        $variables = $finder->find($body, static function (Node $n) use ($name): bool {
            return $n instanceof Variable && $n->name === $name;
        });

        return $variables !== [];
    }
}
