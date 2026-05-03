<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Input;

use Phalanx\Application;
use Phalanx\Archon\Input\RawInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RawInputTest extends TestCase
{
    #[Test]
    public function restoreIsIdempotentAndDisablesRawMode(): void
    {
        $commands = [];
        $input = new RawInput(
            isTty: true,
            stty: static function (string $command) use (&$commands): string {
                $commands[] = $command;

                return $command === 'stty -g 2>/dev/null' ? 'saved-state' : '';
            },
        );

        $input->enable();
        $input->restore();
        $input->restore();

        self::assertFalse($input->isActive());
        self::assertFalse($input->isAttached());
        self::assertSame([
            'stty -g 2>/dev/null',
            'stty -icanon -isig -echo -ixon min 1 time 0 2>/dev/null',
            'stty saved-state 2>/dev/null',
        ], $commands);
    }

    #[Test]
    public function restoreOnDisposeRestoresRawModeWhenScopeDisposes(): void
    {
        $commands = [];
        $app = Application::starting()->compile();
        $scope = $app->createScope();
        $input = new RawInput(
            isTty: true,
            stty: static function (string $command) use (&$commands): string {
                $commands[] = $command;

                return $command === 'stty -g 2>/dev/null' ? 'saved-state' : '';
            },
        );

        try {
            $input->enable();
            $input->restoreOnDispose($scope);

            self::assertTrue($input->isActive());
        } finally {
            $scope->dispose();
            $app->shutdown();
        }

        self::assertFalse($input->isActive());
        self::assertSame([
            'stty -g 2>/dev/null',
            'stty -icanon -isig -echo -ixon min 1 time 0 2>/dev/null',
            'stty saved-state 2>/dev/null',
        ], $commands);
    }
}
