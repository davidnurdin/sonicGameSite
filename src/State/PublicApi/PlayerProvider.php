<?php

namespace App\State\PublicApi;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;

/** The members of the site, as their public profile (App\ApiResource\Player). */
final class PlayerProvider extends PublicProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $name = (string) $uriVariables['username'];
            $user = preg_match('/^[A-Za-z0-9]{1,15}$/', $name) ? $this->view->em->getRepository(User::class)->findOneBy(['username' => strtoupper($name)]) : null;

            return $user !== null ? $this->view->players([$user])[0] : null;
        }
        $query = $this->view->em->createQueryBuilder()->select('u')->from(User::class, 'u')->orderBy('u.username', 'ASC');

        return $this->page($query, $operation, $context, $this->view->players(...), all: true);
    }
}
