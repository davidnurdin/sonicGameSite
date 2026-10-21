<?php

namespace App\State\PublicApi;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Tileset;

/** The zones of the game and the public tiles (App\ApiResource\Tileset); one also the tiles of a public level. */
final class TilesetProvider extends PublicProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $tileset = $this->view->em->find(Tileset::class, (int) $uriVariables['id']);

            return $this->view->visible($tileset) ? $this->view->tileset($tileset) : null;
        }
        $query = CreationQuery::of($this->view->em, Tileset::class, $context, 'c.public = true OR c.zone IS NOT NULL');
        $zone = self::filter($context, 'zone');
        if ($zone !== null) $query->andWhere(in_array($zone, ['true', '1'], true) ? 'c.zone IS NOT NULL' : 'c.zone IS NULL');
        // the zones first, in the order of the game
        $query->orderBy('CASE WHEN c.zone IS NULL THEN 1 ELSE 0 END', 'ASC')->addOrderBy('CASE WHEN c.zone IS NULL THEN c.updatedAt ELSE 0 END', 'DESC')
            ->addOrderBy('c.id', 'ASC');

        return $this->page($query, $operation, $context, $this->view->tileset(...));
    }
}
