<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Unit;

use Phalanx\Stoa\Contract\Header;
use Phalanx\Stoa\Response\Accepted;
use Phalanx\Stoa\Response\Created;
use Phalanx\Stoa\Response\NoContent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CapabilityInterfacesTest extends TestCase
{
    #[Test]
    public function createdResponseStatusHookReturns201(): void
    {
        $created = new Created(['id' => 1]);

        $this->assertSame(201, $created->status);
        $this->assertSame(201, Created::STATUS);
        $this->assertSame(201, $created->toResponse()->getStatusCode());
    }

    #[Test]
    public function acceptedResponseStatusHookReturns202(): void
    {
        $accepted = new Accepted(['queued' => true]);

        $this->assertSame(202, $accepted->status);
        $this->assertSame(202, Accepted::STATUS);
        $this->assertSame(202, $accepted->toResponse()->getStatusCode());
    }

    #[Test]
    public function noContentResponseStatusHookReturns204(): void
    {
        $noContent = new NoContent();

        $this->assertSame(204, $noContent->status);
        $this->assertSame(204, NoContent::STATUS);
        $this->assertSame(204, $noContent->toResponse()->getStatusCode());
    }

    #[Test]
    public function headerRequiredFactorySetsRequiredTrue(): void
    {
        $header = Header::required('X-Foo', pattern: '\w+');

        $this->assertSame('X-Foo', $header->name);
        $this->assertSame('\w+', $header->pattern);
        $this->assertTrue($header->required);
    }

    #[Test]
    public function headerOptionalFactorySetsRequiredFalse(): void
    {
        $header = Header::optional('X-Bar');

        $this->assertSame('X-Bar', $header->name);
        $this->assertNull($header->pattern);
        $this->assertFalse($header->required);
    }
}
