<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Highlight;

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
