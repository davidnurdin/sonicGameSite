<?php

namespace App\State\PublicApi;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Sound;

/** The public musics and sound effects (App\ApiResource\Sound); one also those of a public level. */
final class SoundProvider extends PublicProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $sound = $this->view->em->find(Sound::class, (int) $uriVariables['id']);

            return $this->view->visible($sound) ? $this->view->sound($sound) : null;
        }
        $query = CreationQuery::of($this->view->em, Sound::class, $context);
        if (($kind = self::filter($context, 'kind')) !== null) $query->andWhere('c.kind = :kind')->setParameter('kind', $kind);

        return $this->page($query, $operation, $context, $this->view->sound(...));
    }
}
