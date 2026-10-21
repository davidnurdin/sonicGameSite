<?php

namespace App\State\PublicApi;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Revision;

/** The history of a visible creation (App\ApiResource\HistoryEntry), without the content of the versions. */
final class HistoryProvider extends PublicProvider implements ProviderInterface
{
    /** The kind of creation of each list, by the start of its address. */
    private const KINDS = ['/levels/' => 'level', '/tilesets/' => 'tileset', '/sprites/' => 'sprites', '/sounds/' => 'sound'];

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $lang = self::filter($context, 'lang') === 'en' ? 'en' : 'fr';
        if (!$operation instanceof CollectionOperationInterface) {
            $revision = $this->view->em->find(Revision::class, (int) $uriVariables['id']);
            $creation = $revision !== null ? $this->view->creations->find($revision->getKind(), $revision->getCreationId()) : null;

            return $this->view->visible($creation) ? $this->view->history($revision, $lang) : null;
        }
        $kind = null;
        foreach (self::KINDS as $start => $candidate) if (str_starts_with((string) $operation->getUriTemplate(), $start)) $kind = $candidate;
        $id = (int) $uriVariables['id'];
        // a creation nobody may see: as if it did not exist (404)
        if ($kind === null || !$this->view->visible($this->view->creations->find($kind, $id))) return null;
        $query = $this->view->em->createQueryBuilder()->select('r')->from(Revision::class, 'r')
            ->where('r.kind = :kind AND r.creationId = :id')->setParameter('kind', $kind)->setParameter('id', $id)
            ->orderBy('r.id', 'DESC');

        return $this->page($query, $operation, $context, fn(Revision $r) => $this->view->history($r, $lang));
    }
}
