<?php

declare(strict_types=1);

namespace Phalanx\Stream;

enum StreamEvent: string
{
    case End = 'end';
    case Data = 'data';
    case Exit = 'exit';
    case Error = 'error';
    case Close = 'close';
    case Drain = 'drain';
    case Message = 'message';
    case Connection = 'connection';
}
