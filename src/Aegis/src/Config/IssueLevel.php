<?php

declare(strict_types=1);

namespace Phalanx\Config;

enum IssueLevel
{
    case Error;
    case Warning;
    case Info;
}
