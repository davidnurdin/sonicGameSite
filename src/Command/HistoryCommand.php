<?php

namespace App\Command;

use App\Repository\RevisionRepository;
use App\Service\Creations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The creations made before there was a history get their first line (their content as it is), at each start of
 * the site: once they have one, nothing to do.
 */
#[AsCommand(name: 'app:history', description: 'Une première version dans l\'historique des créations qui n\'en ont pas')]
final class HistoryCommand
{
    public function __construct(private Creations $creations, private RevisionRepository $revisions, private EntityManagerInterface $em)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $added = 0;
        foreach (Creations::KINDS as $kind => $class) {
            $known = array_flip($this->revisions->createQueryBuilder('r')->select('DISTINCT r.creationId')
                ->where('r.kind = :kind')->setParameter('kind', $kind)->getQuery()->getSingleColumnResult());
            foreach ($this->em->getRepository($class)->findAll() as $creation) {
                if (isset($known[$creation->getId()]) || $creation->getUser() === null) continue;   // the zones of the game: no history
                $this->creations->record($creation, $creation->getUser(), 'create')?->setCreatedAt($creation->getUpdatedAt());
                $added++;
            }
            $this->em->flush();
            $this->em->clear();
        }
        $io->writeln($added . ' creations with a first version in their history');

        return 0;
    }
}
