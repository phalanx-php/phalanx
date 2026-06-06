<?php

declare(strict_types=1);

namespace Phalanx\Stream\Tests\Unit;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Stream;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class StreamTest extends PhalanxTestCase
{
    #[Test]
    public function facadeCreatesChannelsAndScopedPipelines(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $channel = Stream::channel();
            self::assertInstanceOf(Channel::class, $channel);
            self::assertTrue($channel->isOpen);
            $channel->complete();

            $source = Stream::produce(static function (Channel $ch): void {
                $ch->emit('alpha');
                $ch->emit('beta');
            });

            self::assertSame(['alpha', 'beta'], Stream::from($scope, $source)->toArray());
        });
    }
}
