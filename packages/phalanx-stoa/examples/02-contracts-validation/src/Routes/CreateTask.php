<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Contracts\Routes;

use Acme\StoaDemo\Contracts\Input\CreateTaskInput;
use Phalanx\Stoa\Contract\HasValidators;
use Phalanx\Stoa\Contract\Header;
use Phalanx\Stoa\Contract\RequiresHeaders;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Response\Created;
use Phalanx\Task\Scopeable;

final class CreateTask implements HasValidators, RequiresHeaders, Scopeable
{
    /** @var list<class-string> */
    public array $validators {
        get => [];
    }

    /** @var list<Header> */
    public array $requiredHeaders {
        get => [Header::required('Idempotency-Key', '[A-Za-z0-9._-]{6,64}')];
    }

    /** @return Created */
    public function __invoke(RequestScope $scope, CreateTaskInput $input): Created
    {
        return new Created([
            'task' => [
                'id' => 101,
                'title' => $input->title,
                'priority' => $input->priority,
            ],
            'idempotency_key' => $scope->header('Idempotency-Key'),
        ]);
    }
}
