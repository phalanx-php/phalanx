<?php

declare(strict_types=1);

namespace Phalanx\Cli\Doctor;

final class EnvironmentChecker
{
    private const array BUILD_TOOLS = ['gcc', 'make', 'autoconf', 'phpize'];
    private const array OPTIONAL_EXTENSIONS = ['openssl', 'curl', 'mbstring', 'pdo_pgsql', 'pdo_mysql'];

    /** @return list<Check> */
    public function __invoke(): array
    {
        $checks = [];

        $checks[] = (new PhpVersionCheck())();
        $checks[] = (new OpenSwooleCheck())();
        $checks[] = (new PieCheck())();

        foreach (self::BUILD_TOOLS as $tool) {
            $checks[] = (new SystemToolCheck($tool))();
        }

        foreach (self::OPTIONAL_EXTENSIONS as $ext) {
            $checks[] = (new ExtensionCheck($ext))();
        }

        return $checks;
    }
}
