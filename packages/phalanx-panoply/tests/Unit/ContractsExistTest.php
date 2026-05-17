<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Artifact;
use Phalanx\Panoply\Artifact\Collection;
use Phalanx\Panoply\Artifact\Store;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Parser;
use Phalanx\Panoply\Conversation\Source;
use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Authorizer;
use Phalanx\Panoply\Effect\Decision;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Hazard\Scorer;
use Phalanx\Panoply\HomeDir;
use Phalanx\Panoply\HomeDir\Locators;
use Phalanx\Panoply\HomeDir\Projects;
use Phalanx\Panoply\HomeDir\Settings;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Stream;
use Phalanx\Panoply\Transport;
use Phalanx\Panoply\Transport\Request as TransportRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pin every Phase 3 interface. Reflection-based shape verification ensures
 * the method signatures declared in the contract layer stay honest across
 * refactors.
 */
final class ContractsExistTest extends TestCase
{
    #[Test]
    public function providerInterfaceShape(): void
    {
        $r = new \ReflectionClass(Provider::class);
        self::assertTrue($r->isInterface());

        $perform = $r->getMethod('perform');
        $params  = $perform->getParameters();
        self::assertCount(2, $params);
        self::assertSame('invocation', $params[0]->getName());
        self::assertSame(Invocation::class, $params[0]->getType()?->getName());
        self::assertSame('runtime', $params[1]->getName());
        self::assertSame(Runtime::class, $params[1]->getType()?->getName());
        self::assertSame(Stream::class, $perform->getReturnType()?->getName());

        $capabilities = $r->getMethod('capabilities');
        self::assertCount(0, $capabilities->getParameters());
        self::assertSame(Capabilities::class, $capabilities->getReturnType()?->getName());
    }

    #[Test]
    public function transportInterfaceShape(): void
    {
        $r = new \ReflectionClass(Transport::class);
        self::assertTrue($r->isInterface());

        $stream = $r->getMethod('stream');
        $params = $stream->getParameters();
        self::assertCount(2, $params);
        self::assertSame('request', $params[0]->getName());
        self::assertSame(TransportRequest::class, $params[0]->getType()?->getName());
        self::assertSame('runtime', $params[1]->getName());
        self::assertSame(Runtime::class, $params[1]->getType()?->getName());
        self::assertSame(\Generator::class, $stream->getReturnType()?->getName());
    }

    #[Test]
    public function runtimeInterfaceShape(): void
    {
        $r = new \ReflectionClass(Runtime::class);
        self::assertTrue($r->isInterface());

        self::assertTrue($r->hasMethod('call'));
        self::assertTrue($r->hasMethod('isCancelled'));
        self::assertTrue($r->hasMethod('throwIfCancelled'));
        self::assertTrue($r->hasMethod('onCancel'));

        $call   = $r->getMethod('call');
        $params = $call->getParameters();
        self::assertCount(2, $params, 'Runtime::call accepts (closure, ?waitReason)');
        self::assertSame('work', $params[0]->getName());
        self::assertSame(\Closure::class, $params[0]->getType()?->getName());
        self::assertSame('waitReason', $params[1]->getName());
        self::assertTrue($params[1]->allowsNull());

        $return = $call->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $return);
        self::assertSame('mixed', $return->getName());
    }

    #[Test]
    public function homeDirInterfaceShape(): void
    {
        $r = new \ReflectionClass(HomeDir::class);
        self::assertTrue($r->isInterface());

        self::assertTrue($r->hasMethod('projects'));
        self::assertTrue($r->hasMethod('locators'));
        self::assertTrue($r->hasMethod('parser'));
        self::assertTrue($r->hasMethod('settings'));

        self::assertSame(Projects::class, $r->getMethod('projects')->getReturnType()?->getName());
        self::assertSame(Locators::class, $r->getMethod('locators')->getReturnType()?->getName());
        self::assertSame(Parser::class, $r->getMethod('parser')->getReturnType()?->getName());

        $settings = $r->getMethod('settings');
        self::assertCount(0, $settings->getParameters());
        self::assertSame(Settings::class, $settings->getReturnType()?->getName());
    }

    #[Test]
    public function parserInterfaceShape(): void
    {
        $r = new \ReflectionClass(Parser::class);
        self::assertTrue($r->isInterface());

        $parse  = $r->getMethod('parse');
        $params = $parse->getParameters();
        self::assertCount(2, $params);
        self::assertSame('source', $params[0]->getName());
        self::assertSame(Source::class, $params[0]->getType()?->getName());
        self::assertSame('options', $params[1]->getName());
        self::assertTrue($params[1]->allowsNull());
        self::assertSame(Log::class, $parse->getReturnType()?->getName());
    }

    #[Test]
    public function authorizerInterfaceShape(): void
    {
        $r = new \ReflectionClass(Authorizer::class);
        self::assertTrue($r->isInterface());

        $evaluate = $r->getMethod('evaluate');
        $params   = $evaluate->getParameters();
        self::assertCount(2, $params);
        self::assertSame('effect', $params[0]->getName());
        self::assertSame(Effect::class, $params[0]->getType()?->getName());
        self::assertSame('grant', $params[1]->getName());
        self::assertTrue($params[1]->allowsNull());
        self::assertSame(Decision::class, $evaluate->getReturnType()?->getName());
    }

    #[Test]
    public function scorerInterfaceShape(): void
    {
        $r = new \ReflectionClass(Scorer::class);
        self::assertTrue($r->isInterface());

        $score  = $r->getMethod('score');
        $params = $score->getParameters();
        self::assertCount(1, $params);
        self::assertSame('effect', $params[0]->getName());
        self::assertSame(Effect::class, $params[0]->getType()?->getName());
        self::assertSame(Hazard::class, $score->getReturnType()?->getName());
    }

    #[Test]
    public function storeInterfaceShape(): void
    {
        $r = new \ReflectionClass(Store::class);
        self::assertTrue($r->isInterface());

        self::assertTrue($r->hasMethod('save'));
        self::assertTrue($r->hasMethod('byId'));
        self::assertTrue($r->hasMethod('byActivity'));
        self::assertTrue($r->hasMethod('all'));

        $save       = $r->getMethod('save');
        $saveParams = $save->getParameters();
        self::assertCount(1, $saveParams);
        self::assertSame(Artifact::class, $saveParams[0]->getType()?->getName());

        $byId = $r->getMethod('byId');
        self::assertTrue($byId->getReturnType()?->allowsNull());

        $all = $r->getMethod('all');
        self::assertSame(Collection::class, $all->getReturnType()?->getName());
    }
}
