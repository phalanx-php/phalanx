<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Cancellation\NoCancelledShortcutRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoCancelledShortcutRule>
 */
final class NoCancelledShortcutRuleTest extends RuleTestCase
{
    public function testReportsThrowableCatchThatSwallowsCancellation(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/no-cancelled-shortcut.php'],
            [
                ['catch (Throwable|\Exception) must rethrow or explicitly preserve Phalanx\Cancellation\Cancelled; swallowed cancellation makes a cancelled task look successful.', 16],
                ['catch (Throwable|\Exception) must rethrow or explicitly preserve Phalanx\Cancellation\Cancelled; swallowed cancellation makes a cancelled task look successful.', 58],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new NoCancelledShortcutRule(new PathPolicy());
    }
}
