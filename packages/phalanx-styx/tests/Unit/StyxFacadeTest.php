<?php

declare(strict_types=1);

namespace Phalanx\Styx\Tests\Unit;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Styx;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class StyxFacadeTest extends PhalanxTestCase
{
    #[Test]
    public function facadeCreatesChannelsAndScopedStreams(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $channel = Styx::channel();
            self::assertInstanceOf(Channel::class, $channel);
            self::assertTrue($channel->isOpen);
            $channel->complete();

            $source = Styx::produce(static function (Channel $ch): void {
                $ch->emit('alpha');
                $ch->emit('beta');
            });

            self::assertSame(['alpha', 'beta'], Styx::from($scope, $source)->toArray());
        });
    }
}
