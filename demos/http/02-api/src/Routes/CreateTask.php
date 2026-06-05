<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Api\Routes;

use Acme\HttpDemo\Api\Input\CreateTaskInput;
use Phalanx\Http\AuthRequestContext;
use Phalanx\Http\Contract\Header;
use Phalanx\Http\Contract\HasValidators;
use Phalanx\Http\Contract\RequiresHeaders;
use Phalanx\Http\Response\Created;
use Phalanx\Task\Scopeable;

final class CreateTask implements HasValidators, RequiresHeaders, Scopeable
{
    /** @var list<class-string<\Phalanx\Http\Contract\RouteValidator>> */
    public array $validators {
        get => [];
    }

    /** @var list<Header> */
    public array $requiredHeaders {
        get => [Header::required('Idempotency-Key', '[A-Za-z0-9._-]{6,64}')];
    }

    public function __invoke(AuthRequestContext $ctx, CreateTaskInput $input): Created
    {
        return new Created([
            'task' => [
                'id' => 101,
                'title' => $input->title,
                'priority' => $input->priority,
            ],
            'idempotency_key' => $ctx->header('Idempotency-Key'),
            'created_by' => $ctx->auth->identity?->id,
        ]);
    }
}
