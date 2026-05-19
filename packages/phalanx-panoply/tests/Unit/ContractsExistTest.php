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
        $params = $perform->getParameters();
        self::assertCount(2, $params);
        self::assertSame('invocation', $params[0]->getName());
        self::assertSame(Invocation::class, self::namedTypeName($params[0]->getType()));
        self::assertSame('runtime', $params[1]->getName());
        self::assertSame(Runtime::class, self::namedTypeName($params[1]->getType()));
        self::assertSame(Stream::class, self::namedTypeName($perform->getReturnType()));

        $capabilities = $r->getMethod('capabilities');
        self::assertCount(0, $capabilities->getParameters());
        self::assertSame(Capabilities::class, self::namedTypeName($capabilities->getReturnType()));
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
        self::assertSame(TransportRequest::class, self::namedTypeName($params[0]->getType()));
        self::assertSame('runtime', $params[1]->getName());
        self::assertSame(Runtime::class, self::namedTypeName($params[1]->getType()));
        self::assertSame(\Generator::class, self::namedTypeName($stream->getReturnType()));
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

        $call = $r->getMethod('call');
        $params = $call->getParameters();
        self::assertCount(2, $params, 'Runtime::call accepts (closure, ?waitReason)');
        self::assertSame('work', $params[0]->getName());
        self::assertSame(\Closure::class, self::namedTypeName($params[0]->getType()));
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

        self::assertSame(Projects::class, self::namedTypeName($r->getMethod('projects')->getReturnType()));
        self::assertSame(Locators::class, self::namedTypeName($r->getMethod('locators')->getReturnType()));
        self::assertSame(Parser::class, self::namedTypeName($r->getMethod('parser')->getReturnType()));

        $settings = $r->getMethod('settings');
        self::assertCount(0, $settings->getParameters());
        self::assertSame(Settings::class, self::namedTypeName($settings->getReturnType()));
    }

    #[Test]
    public function parserInterfaceShape(): void
    {
        $r = new \ReflectionClass(Parser::class);
        self::assertTrue($r->isInterface());

        $parse = $r->getMethod('parse');
        $params = $parse->getParameters();
        self::assertCount(2, $params);
        self::assertSame('source', $params[0]->getName());
        self::assertSame(Source::class, self::namedTypeName($params[0]->getType()));
        self::assertSame('options', $params[1]->getName());
        self::assertTrue($params[1]->allowsNull());
        self::assertSame(Log::class, self::namedTypeName($parse->getReturnType()));
    }

    #[Test]
    public function authorizerInterfaceShape(): void
    {
        $r = new \ReflectionClass(Authorizer::class);
        self::assertTrue($r->isInterface());

        $evaluate = $r->getMethod('evaluate');
        $params = $evaluate->getParameters();
        self::assertCount(2, $params);
        self::assertSame('effect', $params[0]->getName());
        self::assertSame(Effect::class, self::namedTypeName($params[0]->getType()));
        self::assertSame('grant', $params[1]->getName());
        self::assertTrue($params[1]->allowsNull());
        self::assertSame(Decision::class, self::namedTypeName($evaluate->getReturnType()));
    }

    #[Test]
    public function scorerInterfaceShape(): void
    {
        $r = new \ReflectionClass(Scorer::class);
        self::assertTrue($r->isInterface());

        $score = $r->getMethod('score');
        $params = $score->getParameters();
        self::assertCount(1, $params);
        self::assertSame('effect', $params[0]->getName());
        self::assertSame(Effect::class, self::namedTypeName($params[0]->getType()));
        self::assertSame(Hazard::class, self::namedTypeName($score->getReturnType()));
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

        $save = $r->getMethod('save');
        $saveParams = $save->getParameters();
        self::assertCount(1, $saveParams);
        self::assertSame(Artifact::class, self::namedTypeName($saveParams[0]->getType()));

        $byId = $r->getMethod('byId');
        self::assertTrue($byId->getReturnType()?->allowsNull());

        $all = $r->getMethod('all');
        self::assertSame(Collection::class, self::namedTypeName($all->getReturnType()));
    }

    /**
     * Extract the type name from a ReflectionType, asserting it is a
     * ReflectionNamedType first. Intersection and union types are not expected
     * in the panoply contract layer.
     */
    private static function namedTypeName(\ReflectionType|null $type): string
    {
        self::assertInstanceOf(\ReflectionNamedType::class, $type, 'Expected a named (non-union/intersection) type');

        return $type->getName();
    }
}
