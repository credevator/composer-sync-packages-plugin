<?php

namespace Credevator\ComposerSyncPackagesPlugin;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

class CommandProvider implements CommandProviderCapability
{
    public function getCommands()
    {
        return array(new SyncPackagesCommand());
    }
}
