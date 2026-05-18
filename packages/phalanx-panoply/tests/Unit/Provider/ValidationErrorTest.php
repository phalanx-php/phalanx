<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider;

use Phalanx\Panoply\Provider\ValidationError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidationErrorTest extends TestCase
{
    #[Test]
    public function violationsListIsPreserved(): void
    {
        $violations = ['Missing required key: id', 'display_name must be non-empty'];
        $error = new ValidationError($violations, 'olympus.yaml');

        self::assertSame($violations, $error->violations);
    }

    #[Test]
    public function messageIncludesSourceLabel(): void
    {
        $error = new ValidationError(['Missing id'], 'sparta.yaml');

        self::assertStringContainsString('sparta.yaml', $error->getMessage());
    }

    #[Test]
    public function messageIncludesViolationCount(): void
    {
        $error = new ValidationError(['v1', 'v2', 'v3'], 'olympus.yaml');

        self::assertStringContainsString('3 violation', $error->getMessage());
    }

    #[Test]
    public function messageIncludesAllViolations(): void
    {
        $error = new ValidationError(['Missing id', 'Bad transport'], 'test.yaml');

        self::assertStringContainsString('Missing id', $error->getMessage());
        self::assertStringContainsString('Bad transport', $error->getMessage());
    }

    #[Test]
    public function defaultSourceLabelIsUsedWhenNotSupplied(): void
    {
        $error = new ValidationError(['Missing id']);

        self::assertStringContainsString('<unknown>', $error->getMessage());
    }

    #[Test]
    public function extendsInvalidArgumentException(): void
    {
        $error = new ValidationError(['violation']);

        self::assertInstanceOf(\InvalidArgumentException::class, $error);
    }
}
