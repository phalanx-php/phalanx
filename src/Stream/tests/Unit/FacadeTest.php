<?php

declare(strict_types=1);

namespace Phalanx\Stream\Tests\Unit;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Facade;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class FacadeTest extends PhalanxTestCase
{
    #[Test]
    public function facadeCreatesChannelsAndScopedPipelines(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $channel = Facade::channel();
            self::assertInstanceOf(Channel::class, $channel);
            self::assertTrue($channel->isOpen);
            $channel->complete();

            $source = Facade::produce(static function (Channel $ch): void {
                $ch->emit('alpha');
                $ch->emit('beta');
            });

            self::assertSame(['alpha', 'beta'], Facade::from($scope, $source)->toArray());
        });
    }
}
