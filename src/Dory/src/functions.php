<?php

declare(strict_types=1);

use Phalanx\Dory\ScriptContext;
use Phalanx\Dory\ScriptContextHolder;

function dory(): ScriptContext
{
    return ScriptContextHolder::current();
}
