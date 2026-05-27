<?php

declare(strict_types=1);

namespace Phalanx\Dory;

final class ScriptRunner
{
    public static function execute(ScriptContext $context): mixed
    {
        ScriptContextHolder::set($context);
        try {
            return (static function (string $scriptPath): mixed {
                return require $scriptPath;
            })($context->scriptPath);
        } finally {
            ScriptContextHolder::clear();
        }
    }
}
