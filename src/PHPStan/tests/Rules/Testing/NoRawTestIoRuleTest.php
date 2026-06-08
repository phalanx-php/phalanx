<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Testing;

use Phalanx\PHPStan\Rules\Testing\NoRawTestIoRule;
use Phalanx\PHPStan\Support\TestingPathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<NoRawTestIoRule>
 */
final class NoRawTestIoRuleTest extends RuleTestCase
{
    private ?TestingPathPolicy $pathPolicy = null;

    #[Test]
    public function flagsRawTestIoInHighLevelTests(): void
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
                [
                    'High-level Phalanx tests should not use raw test IO via file_get_contents(); '
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    17,
                ],
                [
                    'High-level Phalanx tests should not use raw test IO via file_put_contents(); '
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    18,
                ],
                [
                    'High-level Phalanx tests should not use raw test IO via mkdir(); '
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    19,
                ],
                [
                    'High-level Phalanx tests should not use raw test IO via touch(); '
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    20,
                ],
                [
                    'High-level Phalanx tests should not use raw test IO via unlink(); '
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    21,
                ],
                [
                    'High-level Phalanx tests should not use raw test IO via rmdir(); '
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    22,
                ],
                [
                    "High-level Phalanx tests should not use raw test IO via fopen('php://memory'); "
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    25,
                ],
                [
                    "High-level Phalanx tests should not use raw test IO via fopen('php://temp'); "
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    28,
                ],
                [
                    'High-level Phalanx tests should not use raw test IO via file_put_contents(); '
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    30,
                ],
            ],
        );
    }

    #[Test]
    public function acceptsPhalanxIoPrimitives(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Acceptance/NoRawTestIoValid.php'],
            [],
        );
    }

    #[Test]
    public function configuredPathsExtendRawIoCoverage(): void
    {
        $fixture = __DIR__ . '/../Fixtures/TestingPaths/src/Module/tests/Unit/ConfiguredNoRawTestIoViolation.php';
        $this->pathPolicy = new TestingPathPolicy(noRawIoPaths: ['src/Module/tests']);

        $this->analyse(
            [$fixture],
            [
                [
                    'High-level Phalanx tests should not use raw test IO via file_put_contents(); '
                    . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
                    11,
                ],
            ],
        );
    }

    #[Test]
    public function configuredExemptionsSuppressRawIoCoverage(): void
    {
        $fixture = __DIR__ . '/../Fixtures/TestingPaths/src/Module/tests/Unit/ConfiguredNoRawTestIoViolation.php';
        $this->pathPolicy = new TestingPathPolicy(
            noRawIoPaths: ['src/Module/tests'],
            noRawIoExemptPaths: ['src/Module/tests/Unit/ConfiguredNoRawTestIoViolation.php'],
        );

        $this->analyse([$fixture], []);
    }

    #[Test]
    public function configuredPathsDoNotMatchArbitraryNestedSegments(): void
    {
        $fixture = __DIR__ . '/../Fixtures/TestingPaths/src/Module/tests/Unit/ConfiguredNoRawTestIoViolation.php';
        $this->pathPolicy = new TestingPathPolicy(noRawIoPaths: ['Module/tests']);

        $this->analyse([$fixture], []);
    }

    protected function getRule(): Rule
    {
        return new NoRawTestIoRule($this->pathPolicy ?? new TestingPathPolicy());
    }
}
