<?php

declare(strict_types=1);

namespace Phalanx\Exception;

class InvalidServiceConfigurationException extends \LogicException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function missingDependency(string $service, string $dependency): self
    {
        return new self("Service '$service' requires '$dependency' which is not registered");
    }

    public static function singletonDependsOnScoped(string $singleton, string $scoped): self
    {
        return new self(
            "Singleton '$singleton' cannot depend on scoped service '$scoped'. " .
            "Singletons outlive scoped services, creating a captive dependency."
        );
    }

    public static function invalidFactory(string $service, string $reason): self
    {
        return new self("Invalid factory for '$service': $reason");
    }

    public static function duplicateRegistration(string $service): self
    {
        return new self("Service '$service' is already registered");
    }
}
