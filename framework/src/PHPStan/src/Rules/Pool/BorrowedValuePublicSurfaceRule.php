<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Pool;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\Pool\BorrowedValue;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node>
 */
final class BorrowedValuePublicSurfaceRule implements Rule
{
    public const string MESSAGE =
        'Public APIs must not expose borrowed values; expose owned ids, snapshots, or DTOs instead.';

    private const string IDENTIFIER = 'phalanx.pool.borrowedPublicSurface';

    public function __construct(
        private readonly PathPolicy $paths,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
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
        if (!$this->paths->shouldReport($scope->getFile()) || $this->paths->isInternal($scope->getFile())) {
            return [];
        }

        if ($node instanceof Property && ($node->isPublic() || $node->isProtected())) {
            return $this->borrowedTypeError($node->type, $node->getDocComment()?->getText(), $node->getLine(), $scope);
        }

        if (!$node instanceof ClassMethod || (!$node->isPublic() && !$node->isProtected())) {
            return [];
        }

        $doc = $node->getDocComment()?->getText();
        $errors = $this->borrowedTypeError($node->returnType, $this->tagDoc($doc, 'return'), $node->getLine(), $scope);
        foreach ($node->params as $param) {
            $paramDoc = $param->var instanceof Variable && is_string($param->var->name)
                ? $this->paramDoc($doc, $param->var->name)
                : null;
            array_push($errors, ...$this->borrowedTypeError($param->type, $paramDoc, $param->getLine(), $scope));
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function borrowedTypeError(Node|Identifier|null $type, ?string $doc, int $line, Scope $scope): array
    {
        foreach ($this->typeNames($type, $scope) as $name) {
            if ($this->isBorrowedType($name)) {
                return [
                    RuleErrorBuilder::message(self::MESSAGE)
                        ->identifier(self::IDENTIFIER)
                        ->line($line)
                        ->build(),
                ];
            }
        }

        if ($doc !== null && $this->docMentionsBorrowedType($doc, $scope)) {
            return [
                RuleErrorBuilder::message(self::MESSAGE)
                    ->identifier(self::IDENTIFIER)
                    ->line($line)
                    ->build(),
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function typeNames(Node|Identifier|null $type, Scope $scope): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof NullableType) {
            return $this->typeNames($type->type, $scope);
        }

        if ($type instanceof UnionType) {
            $names = [];
            foreach ($type->types as $inner) {
                array_push($names, ...$this->typeNames($inner, $scope));
            }
            return $names;
        }

        if (!$type instanceof Name) {
            return [];
        }

        $resolved = NodeNames::resolvedTypeName($type, $scope);

        return $resolved === null ? [] : [$resolved];
    }

    private function tagDoc(?string $doc, string $tag): ?string
    {
        if ($doc === null) {
            return null;
        }

        return preg_match('/@' . preg_quote($tag, '/') . '\s+([^\n\r*]+)/', $doc, $match) === 1
            ? $match[1]
            : null;
    }

    private function paramDoc(?string $doc, string $parameter): ?string
    {
        if ($doc === null) {
            return null;
        }

        return preg_match('/@param\s+([^\n\r*]+)\s+\$' . preg_quote($parameter, '/') . '\b/', $doc, $match) === 1
            ? $match[1]
            : null;
    }

    private function docMentionsBorrowedType(string $doc, Scope $scope): bool
    {
        if (!preg_match_all('/\\\\?[A-Z][A-Za-z0-9_\\\\]*/', $doc, $matches)) {
            return false;
        }

        foreach ($matches[0] as $name) {
            if (str_starts_with($name, '\\')) {
                $resolved = substr($name, 1);
            } else {
                $resolved = $scope->resolveName(new Name($name));
                if (!$this->reflectionProvider->hasClass($resolved)) {
                    $namespace = $scope->getNamespace();
                    if ($namespace !== null && $namespace !== '') {
                        $resolved = $namespace . '\\' . $name;
                    }
                }
            }

            if ($this->isBorrowedType($resolved)) {
                return true;
            }
        }

        return false;
    }

    private function isBorrowedType(string $type): bool
    {
        if ($type === BorrowedValue::class) {
            return true;
        }

        return $this->reflectionProvider->hasClass($type)
            && $this->reflectionProvider->getClass($type)->implementsInterface(BorrowedValue::class);
    }
}
