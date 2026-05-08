<?php

declare(strict_types=1);

namespace Phalanx\Boot;

use Closure;

final class Optional extends BootRequirement
{
    public const string KIND_ENV = 'optional.env';
    public const string KIND_SERVICE = 'optional.service';
    public const string KIND_CALLABLE = 'optional.callable';

    /** @param Closure(AppContext): BootEvaluation $check */
    private function __construct(
        string $kind,
        string $description,
        private Closure $check,
    ) {
        parent::__construct($kind, $description);
    }

    public static function env(string $name, ?string $fallback = null, ?string $description = null): self
    {
        $message = $description ?? sprintf('Optional environment variable "%s"', $name);
        return new self(
            self::KIND_ENV,
            $message,
            static fn (AppContext $ctx): BootEvaluation =>
                $ctx->has($name) && $ctx->get($name) !== '' && $ctx->get($name) !== null
                    ? BootEvaluation::pass(sprintf('%s set', $name))
                    : BootEvaluation::warn(
                        sprintf('Optional env "%s" not set; using fallback "%s"', $name, $fallback ?? '<none>'),
                    ),
        );
    }

    public static function service(string $id, ?string $description = null): self
    {
        $message = $description ?? sprintf('Optional service "%s"', $id);
        return new self(
            self::KIND_SERVICE,
            $message,
            static fn (AppContext $ctx): BootEvaluation =>
                BootEvaluation::pass(sprintf('Service "%s" optional (resolved at runtime if present)', $id)),
        );
    }

    public static function callable(callable $fn, string $description): self
    {
        return new self(
            self::KIND_CALLABLE,
            $description,
            static fn (AppContext $ctx): BootEvaluation =>
                ($result = $fn($ctx)) instanceof BootEvaluation
                    ? $result
                    : ($result === true
                        ? BootEvaluation::pass($description)
                        : BootEvaluation::warn(
                            sprintf('%s unavailable', $description),
                            is_string($result) ? $result : null,
                        )),
        );
    }

    public function evaluate(AppContext $context): BootEvaluation
    {
        return ($this->check)($context);
    }
}
