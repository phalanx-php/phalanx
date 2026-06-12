<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixture\OneFile;

use Phalanx\Err\Err;
use Phalanx\Err\Severity;
use Phalanx\Invocation\Caps;
use Phalanx\Invocation\Executable;
use Phalanx\Invocation\InvocationCtx;
use Phalanx\Supervision\Identity;
use Phalanx\Supervision\Operation;

#[Operation('billing.charge')]
final class ChargeCard implements Executable
{
    public function __construct(
        #[Identity]
        private(set) string $invoice,
    ) {
    }

    public function __invoke(InvocationCtx $ctx, ChargeCardCaps $caps): string|ChargeDeclined
    {
        return $caps->gateway->charge($this->invoice)
            ? "receipt:{$this->invoice}"
            : new ChargeDeclined($this->invoice);
    }
}

final class ChargeCardCaps implements Caps
{
    public function __construct(
        private(set) ChargeGateway $gateway,
    ) {
    }
}

final class ChargeGateway
{
    public function __construct(
        private readonly bool $approving,
    ) {
    }

    public function charge(string $invoice): bool
    {
        return $this->approving && $invoice !== '';
    }
}

final class ChargeDeclined implements Err
{
    public Severity $severity {
        get => Severity::Expected;
    }

    public function __construct(
        private(set) string $invoice,
    ) {
    }
}
