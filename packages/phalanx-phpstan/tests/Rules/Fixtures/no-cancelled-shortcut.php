<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Cancellation\Cancelled;
use Throwable;

final class NoCancelledShortcutFixture
{
    public function bad(): void
    {
        try {
            $this->work();
        } catch (Throwable $e) {
            return;
        }
    }

    public function goodRethrow(): void
    {
        try {
            $this->work();
        } catch (Throwable $e) {
            throw $e;
        }
    }

    public function goodEarlierCatch(): void
    {
        try {
            $this->work();
        } catch (Cancelled) {
            throw new Cancelled();
        } catch (Throwable) {
            return;
        }
    }

    public function goodConditionalRethrow(): void
    {
        try {
            $this->work();
        } catch (Throwable $e) {
            if ($e instanceof Cancelled) {
                throw $e;
            }

            return;
        }
    }

    public function badThrowsDifferentException(): void
    {
        try {
            $this->work();
        } catch (Throwable) {
            throw new \RuntimeException('masked');
        }
    }

    private function work(): void
    {
    }
}
