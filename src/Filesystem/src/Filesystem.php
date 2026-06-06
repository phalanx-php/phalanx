<?php

declare(strict_types=1);

namespace Phalanx\Filesystem;

use Phalanx\Scope\TaskScope;
use Phalanx\Service\ServiceBundle;

final class Filesystem
{
    private function __construct()
    {
    }

    public static function services(?int $maxOpen = null): ServiceBundle
    {
        return new FilesystemServiceBundle($maxOpen);
    }

    public static function files(TaskScope $scope): Files
    {
        return $scope->service(Files::class);
    }
}
