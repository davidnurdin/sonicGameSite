<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\Creations;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/** For the pages of the creations of the editors: who made them, where they come from, who works on them (Creations). */
class CreationExtension
{
    public function __construct(private Creations $creations, private \App\Service\LevelRatings $ratings, private Security $security, private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    /**
     * @return array{kind: string, owner: ?User, from: ?object, forks: list<object>, members: list<\App\Entity\Member>,
     *     owned: bool, canEdit: bool, routes: array{0: string, 1: string}}
     */
    #[AsTwigFunction('creation_info')]
    public function info(object $creation): array
    {
        $user = $this->user();
        $from = $creation->getForkedFrom();

        return [
            'kind' => Creations::kind($creation),
            'owner' => $creation->getUser(),
            'from' => $from !== null && $this->creations->canSee($from, $user) ? $from : null,
            'forks' => $this->creations->forks($creation, $user),
            'members' => $this->creations->members($creation),
            'owned' => $creation->isOwnedBy($user),
            'canEdit' => $this->creations->canEdit($creation, $user),
            'routes' => Creations::ROUTES[Creations::kind($creation)],
        ];
    }

    /** The level is in the viewer's favourites (one query for the whole page). */
    #[AsTwigFunction('is_favourite')]
    public function isFavourite(\App\Entity\Level $level): bool
    {
        $user = $this->user();

        return $user !== null && isset($this->ratings->favouriteIds($user)[$level->getId()]);
    }

    /** What the viewer thinks of a level: in his favourites, his stars (0: none), why he may not vote (null: he may). */
    #[AsTwigFunction('level_opinion')]
    public function levelOpinion(\App\Entity\Level $level): array
    {
        $user = $this->user();

        return ['fav' => $this->isFavourite($level),
            'stars' => $this->ratings->myVote($level, $user), 'refused' => $this->ratings->cannotVote($level, $user)];
    }

    /** The attributes an editor's root gets for editor-live.js: where to ask, the version it opened, an old one taken again. */
    #[AsTwigFunction('live_attributes', isSafe: ['html'])]
    public function liveAttributes(object $creation, ?int $restored = null): string
    {
        $url = $this->urls->generate('creation_live', ['kind' => Creations::kind($creation), 'id' => $creation->getId()]);
        $t = fn(string $m) => $this->translator->trans($m);
        $attributes = ['data-live' => $url, 'data-version' => (string) $this->creations->version($creation),
            'data-live-i18n' => (string) json_encode([
                'saved' => $t('%name% a enregistré une nouvelle version à %time%.'),
                'reload' => $t('Recharger'), 'overwrite' => $t('Écraser avec la mienne'), 'history' => $t('Historique'),
                'conflict' => $t('%name% a enregistré une autre version à %time%, depuis que tu as ouvert l\'éditeur. Recharge pour partir de la sienne (tes changements non enregistrés seront perdus), ou écrase-la avec la tienne (elle reste dans l\'historique).'),
                'others' => $t('Aussi dans l\'éditeur :'),
                'restored' => $t('Tu as ouvert une ancienne version. Enregistre pour qu\'elle redevienne la version actuelle.'),
            ]),
            'data-history' => $this->urls->generate(Creations::kind($creation) . '_history', ['id' => $creation->getId()]),
        ];
        if ($restored !== null) $attributes['data-restored'] = (string) $restored;

        return implode(' ', array_map(fn($k, $v) => $k . '="' . htmlspecialchars($v, ENT_QUOTES) . '"', array_keys($attributes), $attributes));
    }

    private function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
