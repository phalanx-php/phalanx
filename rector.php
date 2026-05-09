<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/packages'])
    ->withBootstrapFiles([__DIR__ . '/packages/phalanx-aegis/phpstan-bootstrap.php'])
    ->withPhpSets(php84: true)
    ->withSkip([
        __DIR__ . '/vendor',
        '*/vendor/*',
        '*/node_modules/*',
        \Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector::class,
        \Rector\CodingStyle\Rector\FuncCall\ClosureFromCallableToFirstClassCallableRector::class,
        \Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector::class,
        \Rector\Php55\Rector\String_\StringClassNameToClassConstantRector::class,
        \Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector::class,
        \Rector\Php71\Rector\TryCatch\MultiExceptionCatchRector::class,
        \Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector::class,
        \Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector::class,
        \Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector::class,
        \Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class,
        \Rector\Php82\Rector\Class_\ReadOnlyClassRector::class,
        \Rector\Php84\Rector\Foreach_\ForeachToArrayAllRector::class,
        \Rector\Php84\Rector\Foreach_\ForeachToArrayAnyRector::class,
        \Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector::class,
    ]);
