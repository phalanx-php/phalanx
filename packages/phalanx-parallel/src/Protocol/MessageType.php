<?php

declare(strict_types=1);

namespace Phalanx\Parallel\Protocol;

enum MessageType: string
{
    case TaskRequest = 'task';
    case ServiceCall = 'service_call';
    case TaskResponse = 'task_response';
    case ServiceResponse = 'service_response';
}
