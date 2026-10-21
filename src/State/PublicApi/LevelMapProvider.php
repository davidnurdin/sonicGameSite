<?php

namespace App\State\PublicApi;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Level;

/** The blocks and objects of a public level (App\ApiResource\LevelMap). */
final class LevelMapProvider extends PublicProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?object
    {
        $level = $this->view->em->find(Level::class, (int) $uriVariables['id']);

        return $this->view->visible($level) ? $this->view->levelMap($level) : null;
    }
}
