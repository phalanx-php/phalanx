<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;

final class NodeNames
{
    public static function calledMethodName(Node\Expr\MethodCall|StaticCall $call): ?string
    {
        if (!$call->name instanceof Node\Identifier) {
            return null;
        }

        return $call->name->toString();
    }

    public static function calledClassName(StaticCall $call, Scope $scope): ?string
    {
        if (!$call->class instanceof Name) {
            return null;
        }

        return $scope->resolveName($call->class);
    }

    public static function resolvedTypeName(Node|null $type, Scope $scope): ?string
    {
        if (!$type instanceof Name) {
            return null;
        }

        return $scope->resolveName($type);
    }
}
