<?php

namespace App\State\PublicApi;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Level;
use App\Entity\Replay;

/** The games recorded on the site, as the history page shows them (App\ApiResource\Replay). */
final class ReplayProvider extends PublicProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $replay = $this->view->em->find(Replay::class, (int) $uriVariables['id']);

            return $replay !== null ? $this->view->replay($replay) : null;
        }
        $em = $this->view->em;
        // an online match once: the replay of the first player who sent it (ReplayRepository::history)
        $first = $em->createQueryBuilder()->select('MIN(r2.id)')->from(Replay::class, 'r2')->where('r2.matchId IS NOT NULL')->groupBy('r2.matchId');
        $query = $em->createQueryBuilder()->select('r', 'u')->from(Replay::class, 'r')->join('r.user', 'u')
            ->orderBy('r.createdAt', 'DESC')->addOrderBy('r.id', 'DESC');
        if (($player = self::filter($context, 'player')) !== null) {
            $query->where('u.username = :player')->setParameter('player', strtoupper($player));
        } else {
            $query->where($query->expr()->orX('r.matchId IS NULL', $query->expr()->in('r.id', $first->getDQL())));
        }
        if (($kind = self::filter($context, 'kind')) !== null) $query->andWhere('r.kind = :kind')->setParameter('kind', $kind);
        if (($id = self::filter($context, 'level')) !== null) {
            // only a public level: the games on a private one stay unknown
            $level = $em->find(Level::class, (int) $id);
            $query->andWhere('r.siteLevel = :level')->setParameter('level', $this->view->visible($level) ? $level : 0);
        }

        return $this->page($query, $operation, $context, $this->view->replay(...));
    }
}
