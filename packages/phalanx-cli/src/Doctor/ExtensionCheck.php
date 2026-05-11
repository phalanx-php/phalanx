<?php

declare(strict_types=1);

namespace Phalanx\Cli\Doctor;

final class ExtensionCheck
{
    public function __construct(
        private(set) string $extension,
        private(set) bool $required = false,
    ) {
    }

    public function __invoke(): Check
    {
        $name = "ext-{$this->extension}";

        if (!extension_loaded($this->extension)) {
            if ($this->required) {
                return Check::fail(
                    $name,
                    'Not loaded',
                    "Install the {$this->extension} extension for PHP " . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                );
            }

            return Check::warn(
                $name,
                'Not loaded (optional)',
            );
        }

        $version = phpversion($this->extension);

        return Check::pass($name, $version !== false ? $version : 'loaded');
    }
}
