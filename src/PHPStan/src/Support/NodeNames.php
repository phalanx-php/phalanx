<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
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

    public static function classConstantClassName(ClassConstFetch $fetch, Scope $scope): ?string
    {
        if (!$fetch->class instanceof Name) {
            return null;
        }

        return $scope->resolveName($fetch->class);
    }

    public static function classConstantName(ClassConstFetch $fetch): ?string
    {
        if (!$fetch->name instanceof Node\Identifier) {
            return null;
        }

        return $fetch->name->toString();
    }

    public static function functionName(FuncCall $call, Scope $scope): ?string
    {
        if (!$call->name instanceof Name) {
            return null;
        }

        return $scope->resolveName($call->name);
    }

    public static function newClassName(New_ $new, Scope $scope): ?string
    {
        if (!$new->class instanceof Name) {
            return null;
        }

        return $scope->resolveName($new->class);
    }

    public static function resolvedTypeName(Node|null $type, Scope $scope): ?string
    {
        if (!$type instanceof Name) {
            return null;
        }

        return $scope->resolveName($type);
    }
}
