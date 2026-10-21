<?php

namespace App\State\PublicApi;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Tileset;

/** Each block of visible tiles (App\ApiResource\TilesetBlocks). */
final class TilesetBlocksProvider extends PublicProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?object
    {
        $tileset = $this->view->em->find(Tileset::class, (int) $uriVariables['id']);

        return $this->view->visible($tileset) ? $this->view->tilesetBlocks($tileset) : null;
    }
}
