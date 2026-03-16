<?php

declare(strict_types=1);

namespace Convoy\Parallel\Protocol;

enum MessageType: string
{
    case TaskRequest = 'task';
    case ServiceCall = 'service_call';
    case TaskResponse = 'task_response';
    case ServiceResponse = 'service_response';
}
