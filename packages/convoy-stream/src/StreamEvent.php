<?php

declare(strict_types=1);

namespace Convoy\Stream;

enum StreamEvent: string
{
    case Data = 'data';
    case End = 'end';
    case Error = 'error';
    case Close = 'close';
    case Connection = 'connection';
    case Exit = 'exit';
    case Drain = 'drain';
    case Message = 'message';
}
