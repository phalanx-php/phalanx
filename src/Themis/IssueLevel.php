<?php

declare(strict_types=1);

namespace Phalanx\Themis;

enum IssueLevel
{
    case Error;
    case Warning;
    case Info;
}
