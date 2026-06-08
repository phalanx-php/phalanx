<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance;

final class NoRawTestIoViolation
{
    public function run(): void
    {
        fopen('php://memory', 'w+');
        fopen('php://temp', 'w+');
        fopen('/dev/null', 'r');
        sys_get_temp_dir();
        tempnam('.', 'phalanx-');
        tmpfile();
        file_get_contents('fixture.txt');
        file_put_contents('fixture.txt', 'fixture');
        mkdir('fixtures');
        touch('fixture.txt');
        unlink('fixture.txt');
        rmdir('fixtures');

        $memory = 'php://memory';
        fopen($memory, 'w+');

        $temp = 'php://' . 'temp';
        \fopen($temp, 'w+');

        \file_put_contents('fixture.txt', 'fixture');
    }
}
