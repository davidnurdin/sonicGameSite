<?php

namespace App\Command;

use App\Service\Creations;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/** What has been in a trash for more than Creations::TRASH_DAYS days, deleted for good (at each start of the site, and when a trash is opened). */
#[AsCommand(name: 'app:trash', description: 'Supprime pour de bon ce qui est dans une corbeille depuis trop longtemps')]
final class TrashCommand
{
    public function __construct(private Creations $creations)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->writeln($this->creations->purgeOld() . ' creations deleted from the trashes');

        return 0;
    }
}
