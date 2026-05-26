<?php

declare(strict_types=1);

namespace Phalanx\Dory;

final class ScriptRunner
{
    public static function execute(ScriptContext $dory): mixed
    {
        return (static function (ScriptContext $dory): mixed {
            return require $dory->scriptPath;
        })($dory);
    }
}
