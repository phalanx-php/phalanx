<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixture\OneFile;

use Phalanx\Err\Err;
use Phalanx\Err\Severity;
use Phalanx\Invocation\Ctx;
use Phalanx\Invocation\Executable;
use Phalanx\Supervision\Operation;

/** @implements Executable<bool|ReceiptBounced> */
#[Operation('billing.receipt')]
final class EmailReceipt implements Executable
{
    public function __construct(
        private(set) string $receipt,
    ) {
    }

    public function __invoke(Ctx $ctx): bool|ReceiptBounced
    {
        return $this->receipt !== '' ?: new ReceiptBounced($this->receipt);
    }
}

final class ReceiptBounced implements Err
{
    public Severity $severity {
        get => Severity::Expected;
    }

    public function __construct(
        private(set) string $receipt,
    ) {
    }
}
