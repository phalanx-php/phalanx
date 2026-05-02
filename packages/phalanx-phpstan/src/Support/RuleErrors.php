<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Support;

use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

final class RuleErrors
{
    /**
     * @return list<IdentifierRuleError>
     */
    public static function build(string $message, string $identifier, int $line): array
    {
        return [
            RuleErrorBuilder::message($message)
                ->identifier($identifier)
                ->line($line)
                ->build(),
        ];
    }
}
