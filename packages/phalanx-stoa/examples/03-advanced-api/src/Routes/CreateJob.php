<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Advanced\Routes;

use Acme\StoaDemo\Advanced\Input\CreateJobInput;
use Acme\StoaDemo\Advanced\Services\AuditLog;
use Phalanx\Stoa\AuthRequestScope;
use Phalanx\Stoa\Response\Accepted;
use Phalanx\Task\Scopeable;

final class CreateJob implements Scopeable
{
    public function __construct(private readonly AuditLog $audit)
    {
    }

    public function __invoke(AuthRequestScope $scope, CreateJobInput $input): Accepted
    {
        return new Accepted([
            'job' => [
                'id' => 'job_001',
                'name' => $input->name,
                'priority' => $input->priority,
            ],
            'requested_by' => $scope->auth->identity?->id,
            'audit_events' => $this->audit->count,
        ]);
    }
}
