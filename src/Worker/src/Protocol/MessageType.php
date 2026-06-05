<?php

declare(strict_types=1);

namespace Phalanx\Worker\Protocol;

enum MessageType: string
{
    case TaskRequest = 'task';
    case StreamEmit = 'stream_emit';
    case ServiceCall = 'service_call';
    case TaskResponse = 'task_response';
    case ServiceResponse = 'service_response';
}
