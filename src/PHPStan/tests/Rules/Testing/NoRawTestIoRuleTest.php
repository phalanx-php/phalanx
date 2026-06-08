<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Testing;

use Phalanx\PHPStan\Rules\Testing\NoRawTestIoRule;
use Phalanx\PHPStan\Support\TestingPathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoRawTestIoRule>
 */
final class NoRawTestIoRuleTest extends RuleTestCase
{
    public function testFlagsRawTestIoInHighLevelTests(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Acceptance/NoRawTestIoViolation.php'],
            [
                [
                    "High-level Phalanx tests should not use raw test IO via fopen('php://memory'); "
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    11,
                ],
                [
                    "High-level Phalanx tests should not use raw test IO via fopen('php://temp'); "
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    12,
                ],
                [
                    "High-level Phalanx tests should not use raw test IO via fopen('/dev/null'); "
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    13,
                ],
                [
                    'High-level Phalanx tests should not use raw test IO via sys_get_temp_dir(); '
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    14,
                ],
                [
                    'High-level Phalanx tests should not use raw test IO via tempnam(); '
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    15,
                ],
                [
                    'High-level Phalanx tests should not use raw test IO via tmpfile(); '
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    16,
                ],
            ],
        );
    }

    public function testAcceptsPhalanxIoPrimitives(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Acceptance/NoRawTestIoValid.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new NoRawTestIoRule(new TestingPathPolicy());
    }
}
