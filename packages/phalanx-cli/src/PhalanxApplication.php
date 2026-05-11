<?php

declare(strict_types=1);

namespace Phalanx\Cli;

use Phalanx\Cli\Command\DoctorCommand;
use Phalanx\Cli\Command\InstallCommand;
use Phalanx\Cli\Command\NewCommand;
use Symfony\Component\Console\Application;

final class PhalanxApplication extends Application
{
    public function __construct()
    {
        parent::__construct('phalanx', '0.6.1');

        $this->add(new DoctorCommand());
        $this->add(new InstallCommand());
        $this->add(new NewCommand());
    }
}
