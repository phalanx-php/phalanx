<?php

declare(strict_types=1);

namespace Phalanx\Boot;

use Closure;

final readonly class Required extends BootRequirement
{
    public const string KIND_ENV = 'required.env';
    public const string KIND_SERVICE = 'required.service';
    public const string KIND_CALLABLE = 'required.callable';

    /** @param Closure(AppContext): BootEvaluation $check */
    private function __construct(
        string $kind,
        string $description,
        private Closure $check,
    ) {
        parent::__construct($kind, $description);
    }

    public static function env(string $name, ?string $description = null): self
    {
        $message = $description ?? sprintf('Required environment variable "%s"', $name);
        return new self(
            self::KIND_ENV,
            $message,
            static fn (AppContext $ctx): BootEvaluation =>
                $ctx->has($name) && $ctx->get($name) !== '' && $ctx->get($name) !== null
                    ? BootEvaluation::pass(sprintf('%s present', $name))
                    : BootEvaluation::fail(
                        sprintf('Missing required env var "%s"', $name),
                        sprintf('Add "%s=..." to your .env file or set it in the process environment.', $name),
                    ),
        );
    }

    public static function service(string $id, ?string $description = null): self
    {
        $message = $description ?? sprintf('Required service "%s"', $id);
        return new self(
            self::KIND_SERVICE,
            $message,
            static fn (AppContext $ctx): BootEvaluation =>
                BootEvaluation::pass(sprintf('Service "%s" required (resolved at runtime)', $id)),
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
                        : BootEvaluation::fail(
                            sprintf('%s failed', $description),
                            is_string($result) ? $result : null,
                        )),
        );
    }

    public function evaluate(AppContext $context): BootEvaluation
    {
        return ($this->check)($context);
    }
}
