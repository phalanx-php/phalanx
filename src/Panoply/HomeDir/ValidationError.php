<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

/**
 * Thrown by {@see Loader} when a HomeDir YAML document fails validation.
 * Carries all accumulated violations as a typed list so callers can
 * display or log the full error surface without parsing the message string.
 *
 * Final — extension would change exception identity and break callers
 * that catch on this exact type.
 */
final class ValidationError extends \InvalidArgumentException
{
    /**
     * @param list<string> $violations
     */
    public function __construct(private(set) array $violations, string $sourceLabel = '<unknown>')
    {
        $count = count($this->violations);
        $lines = implode("\n", array_map(
            static fn (string $v): string => ' - ' . $v,
            $this->violations,
        ));

        parent::__construct(
            sprintf(
                "HomeDir config validation failed for %s: %d violation(s)\n%s",
                $sourceLabel,
                $count,
                $lines,
            ),
        );
    }
}
