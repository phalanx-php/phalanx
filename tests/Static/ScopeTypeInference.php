<?php

declare(strict_types=1);

namespace Phalanx\Tests\Static;

use Phalanx\Scope\Scope;
use Phalanx\Tests\Fixture\OneFile\ChargeCard;
use Phalanx\Tests\Fixture\OneFile\EmailReceipt;

use function PHPStan\Testing\assertType;

/**
 * The generics keystone (EM1.5), statically checked: scopes preserve the
 * declared outcome union, so a bare-T parent cannot drop a child's Err.
 * This file is analysed by PHPStan and never executed by PHPUnit.
 */
final class ScopeTypeInference
{
    public function runPreservesTheDeclaredOutcomeUnion(Scope $scope): void
    {
        assertType(
            'Phalanx\Tests\Fixture\OneFile\ChargeDeclined|string',
            $scope->run(new ChargeCard(invoice: 'inv_1')),
        );
    }

    public function parallelAndMapPreserveTheUnionPositionally(Scope $scope): void
    {
        assertType(
            'list<Phalanx\Tests\Fixture\OneFile\ChargeDeclined|string>',
            $scope->parallel([new ChargeCard(invoice: 'inv_1'), new ChargeCard(invoice: 'inv_2')]),
        );

        assertType(
            'list<Phalanx\Tests\Fixture\OneFile\ChargeDeclined|string>',
            $scope->map(['inv_1', 'inv_2'], static fn (string $invoice): ChargeCard => new ChargeCard(invoice: $invoice)),
        );
    }

    public function racePreservesTheUnion(Scope $scope): void
    {
        assertType(
            'Phalanx\Tests\Fixture\OneFile\ChargeDeclined|string',
            $scope->race([new ChargeCard(invoice: 'inv_1')]),
        );
    }

    public function seriesAccumulatesTheOutcomeUnionAcrossSteps(Scope $scope): void
    {
        assertType(
            'bool|Phalanx\Tests\Fixture\OneFile\ChargeDeclined|Phalanx\Tests\Fixture\OneFile\ReceiptBounced|string',
            $scope->series(
                new ChargeCard(invoice: 'inv_1'),
                static fn (string $receipt): EmailReceipt => new EmailReceipt(receipt: $receipt),
            ),
        );
    }
}
