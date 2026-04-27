<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit;

use Phalanx\HasMiddleware;
use Phalanx\Stoa\Contract\HasValidators;
use Phalanx\Stoa\Contract\Header;
use Phalanx\Stoa\Contract\RequiresHeaders;
use Phalanx\Stoa\Contract\Responds;
use Phalanx\Stoa\Contract\RouteValidator;
use Phalanx\Stoa\Response\Accepted;
use Phalanx\Stoa\Response\Created;
use Phalanx\Stoa\Response\NoContent;
use Phalanx\Stoa\ToResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CapabilityInterfacesTest extends TestCase
{
    #[Test]
    public function responds_property_returns_status_to_class_map(): void
    {
        $handler = new class implements Responds {
            /** @var array<int, class-string> */
            public array $responseTypes {
                get => [
                    201 => \stdClass::class,
                    409 => \RuntimeException::class,
                ];
            }
        };

        $this->assertSame(
            [201 => \stdClass::class, 409 => \RuntimeException::class],
            $handler->responseTypes,
        );
    }

    #[Test]
    public function responds_satisfies_interface_with_backed_property(): void
    {
        // Preferred form: backed property with public private(set), no hook ceremony.
        $handler = new class implements Responds {
            /** @var array<int, class-string> */
            public private(set) array $responseTypes = [
                201 => \stdClass::class,
                409 => \RuntimeException::class,
            ];
        };

        $this->assertSame(
            [201 => \stdClass::class, 409 => \RuntimeException::class],
            $handler->responseTypes,
        );
    }

    #[Test]
    public function has_validators_property_hook_returns_class_string_list(): void
    {
        $handler = new class implements HasValidators {
            /** @var list<class-string<RouteValidator>> */
            public array $validators {
                get => [];
            }
        };

        $this->assertSame([], $handler->validators);
    }

    #[Test]
    public function has_middleware_property_hook_returns_class_string_list(): void
    {
        $handler = new class implements HasMiddleware {
            /** @var list<class-string> */
            public array $middleware {
                get => ['SomeMiddleware', 'AnotherMiddleware'];
            }
        };

        $this->assertSame(['SomeMiddleware', 'AnotherMiddleware'], $handler->middleware);
    }

    #[Test]
    public function requires_headers_property_hook_returns_header_list(): void
    {
        $handler = new class implements RequiresHeaders {
            /** @var list<Header> */
            public array $requiredHeaders {
                get => [
                    Header::required('X-Api-Version', pattern: 'v\d+'),
                    Header::optional('X-Trace-Id'),
                ];
            }
        };

        $this->assertCount(2, $handler->requiredHeaders);
        $this->assertSame('X-Api-Version', $handler->requiredHeaders[0]->name);
        $this->assertSame('v\d+', $handler->requiredHeaders[0]->pattern);
        $this->assertTrue($handler->requiredHeaders[0]->required);
        $this->assertFalse($handler->requiredHeaders[1]->required);
    }

    #[Test]
    public function created_response_status_hook_returns_201(): void
    {
        $created = new Created(['id' => 1]);

        $this->assertSame(201, $created->status);
        $this->assertSame(201, Created::STATUS);
        $this->assertSame(201, $created->toResponse()->getStatusCode());
    }

    #[Test]
    public function accepted_response_status_hook_returns_202(): void
    {
        $accepted = new Accepted(['queued' => true]);

        $this->assertSame(202, $accepted->status);
        $this->assertSame(202, Accepted::STATUS);
        $this->assertSame(202, $accepted->toResponse()->getStatusCode());
    }

    #[Test]
    public function no_content_response_status_hook_returns_204(): void
    {
        $noContent = new NoContent();

        $this->assertSame(204, $noContent->status);
        $this->assertSame(204, NoContent::STATUS);
        $this->assertSame(204, $noContent->toResponse()->getStatusCode());
    }

    #[Test]
    public function subclass_can_override_status_via_const(): void
    {
        $custom = new class (['ok' => true]) extends Created {
            public const int STATUS = 207;
        };

        $this->assertSame(207, $custom->status);
        $this->assertSame(207, $custom->toResponse()->getStatusCode());
    }

    #[Test]
    public function to_response_interface_requires_status_property(): void
    {
        $impl = new class implements ToResponse {
            public int $status { get => 418; }

            public function toResponse(): \Psr\Http\Message\ResponseInterface
            {
                return new \React\Http\Message\Response($this->status);
            }
        };

        $this->assertSame(418, $impl->status);
        $this->assertSame(418, $impl->toResponse()->getStatusCode());
    }

    #[Test]
    public function header_required_factory_sets_required_true(): void
    {
        $header = Header::required('X-Foo', pattern: '\w+');

        $this->assertSame('X-Foo', $header->name);
        $this->assertSame('\w+', $header->pattern);
        $this->assertTrue($header->required);
    }

    #[Test]
    public function header_optional_factory_sets_required_false(): void
    {
        $header = Header::optional('X-Bar');

        $this->assertSame('X-Bar', $header->name);
        $this->assertNull($header->pattern);
        $this->assertFalse($header->required);
    }
}
