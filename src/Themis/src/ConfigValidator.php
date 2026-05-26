<?php

declare(strict_types=1);

namespace Phalanx\Themis;

final class ConfigValidator
{
    public function __construct(private ConfigFactory $factory)
    {
    }

    /**
     * @param list<class-string<Config>> $roots
     */
    public function validate(array $roots, ?ValidationContext $context = null): ValidationResult
    {
        $ctx = $context ?? new ValidationContext();
        $configs = [];
        $issues = [];

        foreach ($roots as $root) {
            $hydrated = $this->factory->tryHydrate($root, $ctx);
            $configs[] = $hydrated;
            $issues = [...$issues, ...$hydrated->issues];
        }

        return new ValidationResult($configs, $ctx, $issues);
    }
}
