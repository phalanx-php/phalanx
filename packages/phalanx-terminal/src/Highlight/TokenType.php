<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Highlight;

enum TokenType
{
    case Keyword;
    case String;
    case Number;
    case Comment;
    case Variable;
    case ClassName;
    case Operator;
    case Default;
}
