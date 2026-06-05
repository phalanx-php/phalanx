<?php

declare(strict_types=1);

namespace Phalanx\Config\Tests\Unit;

use Phalanx\Config\Config;
use Phalanx\Config\ConfigFactory;
use Phalanx\Config\ConfigValidator;
use Phalanx\Config\Env;
use Phalanx\Config\Issue;
use Phalanx\Config\ValidationContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigValidatorTest extends TestCase
{
    #[Test]
    public function validConfigTreeProducesCleanResult(): void
    {
        $factory = ConfigFactory::fromContext(['ZEUS_DSN' => 'postgres://localhost/zeus']);
        $validator = new ConfigValidator($factory);

        $result = $validator->validate([ValidatorZeusConfig::class]);

        self::assertTrue($result->valid);
        self::assertFalse($result->hasErrors);
        self::assertFalse($result->hasWarnings);
        self::assertFalse($result->blocksBoot);
    }

    #[Test]
    public function missingRequiredEnvProducesErrorIssues(): void
    {
        $factory = ConfigFactory::fromContext([]);
        $validator = new ConfigValidator($factory);

        $result = $validator->validate([ValidatorZeusConfig::class]);

        self::assertFalse($result->valid);
        self::assertTrue($result->hasErrors);
        self::assertTrue($result->blocksBoot);
    }

    #[Test]
    public function strictModeWarningsBlockBoot(): void
    {
        $factory = ConfigFactory::fromContext([]);
        $validator = new ConfigValidator($factory);
        $ctx = new ValidationContext(strict: true);

        $result = $validator->validate([ValidatorWarningConfig::class], $ctx);

        self::assertTrue($result->hasWarnings);
        self::assertTrue($result->blocksBoot);
    }

    #[Test]
    public function nonStrictModeWarningsDoNotBlockBoot(): void
    {
        $factory = ConfigFactory::fromContext([]);
        $validator = new ConfigValidator($factory);
        $ctx = new ValidationContext(strict: false);

        $result = $validator->validate([ValidatorWarningConfig::class], $ctx);

        self::assertTrue($result->hasWarnings);
        self::assertFalse($result->hasErrors);
        self::assertFalse($result->blocksBoot);
    }

    #[Test]
    public function multipleRootsAllValidated(): void
    {
        $factory = ConfigFactory::fromContext([]);
        $validator = new ConfigValidator($factory);

        $result = $validator->validate([ValidatorZeusConfig::class, ValidatorWarningConfig::class]);

        self::assertFalse($result->valid);
        self::assertTrue($result->hasErrors);
        self::assertTrue($result->hasWarnings);
    }

    #[Test]
    public function defaultContextUsedWhenNoneProvided(): void
    {
        $factory = ConfigFactory::fromContext(['ZEUS_DSN' => 'postgres://localhost/zeus']);
        $validator = new ConfigValidator($factory);

        $result = $validator->validate([ValidatorZeusConfig::class]);

        self::assertFalse($result->blocksBoot);
    }
}

final class ValidatorZeusConfig implements Config
{
    public bool $configured {
        get => $this->dsn !== '';
    }

    public function __construct(
        #[Env(key: 'ZEUS_DSN', description: 'Zeus database DSN')]
        public string $dsn,
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class ValidatorWarningConfig implements Config
{
    public bool $configured {
        get => true;
    }

    public function validate(ValidationContext $context): array
    {
        return [
            Issue::warning(
                code: 'validator.fixture.warning',
                message: 'This config always emits a warning.',
            ),
        ];
    }
}
