<?php

namespace App\Command;

use App\Entity\Tileset;
use App\Repository\TilesetRepository;
use App\Service\EditorData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The zones of the game as public sets of blocks (data/game/, tools/game-data.py), at each start of the site. */
#[AsCommand(name: 'app:zones', description: 'Les zones du jeu comme jeux de tiles publics')]
final class ZonesCommand
{
    public function __construct(private EditorData $data, private TilesetRepository $tilesets, private EntityManagerInterface $em)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        foreach ($this->data->zones() as $key => $zone) {
            $png = (string) file_get_contents($zone['file']);
            $tileset = $this->tilesets->findOneBy(['zone' => $key]);
            if ($tileset === null) {
                $this->em->persist($tileset = new Tileset(null, $zone['name'], $png, $zone['solidity']));
                $tileset->setZone($key);
                $tileset->setPublic(true);
            } elseif ($tileset->getImage() !== $png || $tileset->getSolidity() !== $zone['solidity']) {
                $tileset->setBlocks($png, $zone['solidity']);
            }
        }
        $this->em->flush();
        $io->writeln(count($this->data->zones()) . ' zones');

        return 0;
    }
}
