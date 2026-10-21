<?php

namespace App\State\PublicApi;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\SpriteSet;

/** The public sprites (App\ApiResource\SpriteSet); one also the sprites of a public level. */
final class SpriteSetProvider extends PublicProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $set = $this->view->em->find(SpriteSet::class, (int) $uriVariables['id']);

            return $this->view->visible($set) ? $this->view->spriteSet($set) : null;
        }

        return $this->page(CreationQuery::of($this->view->em, SpriteSet::class, $context), $operation, $context, $this->view->spriteSet(...));
    }
}
