<?php

namespace App\Service\Studio;

use App\Controller\EditorController;
use App\Controller\SoundController;
use App\Controller\SpriteController;
use App\Entity\Level;
use App\Entity\Sound;
use App\Entity\SpriteSet;
use App\Entity\Tileset;
use App\Entity\User;
use App\Service\Creations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the tools of the studio share: a creation found by its kind and id, if the player may see it (read) or change
 * it (write: his own, or one he was added to); what it is in a few words, with the links of the site; the limits.
 */
final class Studio
{
    public const KINDS = ['level', 'tileset', 'sprites', 'sound'];
    /** How many of each kind a player may have (the editors' limits). */
    public const LIMITS = ['level' => EditorController::MAX_EACH, 'tileset' => EditorController::MAX_EACH,
        'sprites' => SpriteController::MAX_EACH, 'sound' => SoundController::MAX_EACH];

    public function __construct(
        public readonly Creations $creations,
        public readonly EntityManagerInterface $em,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** A creation the player may see (write: may change); a ToolError says why not. */
    public function find(string $kind, mixed $id, User $user, bool $write = false): object
    {
        if (!in_array($kind, self::KINDS, true)) throw new ToolError('kind: level, tileset, sprites or sound.');
        if (!is_int($id) || $id < 1) throw new ToolError("id: the number of a $kind is expected.");
        $creation = $this->creations->find($kind, $id);
        if ($creation === null || !$this->creations->canSee($creation, $user)) throw new ToolError("No $kind #$id that this account can see.");
        if ($write && !$this->creations->canEdit($creation, $user)) {
            throw new ToolError($creation->isTrashed() ? "The $kind #$id is in the trash: restore it first (restore)."
                : ($creation instanceof Tileset && $creation->getZone() !== null ? "#$id is a zone of the game: fork it to change it (fork)."
                : "The $kind #$id is not this account's: fork it to change a copy (fork)."));
        }

        return $creation;
    }

    /** A creation in a few words: what lists and answers show. */
    public function summary(object $creation, User $user): array
    {
        $kind = Creations::kind($creation);
        $out = [
            'kind' => $kind,
            'id' => $creation->getId(),
            'name' => $creation->getName(),
            'author' => $creation->getUser()?->getUsername() ?? 'Sonic PHP (zone of the game)',
            'mine' => $creation->isOwnedBy($user),
            'can_edit' => $this->creations->canEdit($creation, $user),
            'public' => $creation->isPublic(),
            'updated' => $creation->getUpdatedAt()->format(DATE_ATOM),
        ];
        if ($creation->isTrashed()) $out['in_trash_since'] = $creation->getTrashedAt()->format(DATE_ATOM);
        if ($creation->getForkedFrom() !== null) $out['forked_from'] = ['id' => $creation->getForkedFrom()->getId(), 'name' => $creation->getForkedFrom()->getName()];
        $out += match (true) {
            $creation instanceof Level => ['size' => [$creation->getWidth(), $creation->getHeight()], 'tileset_id' => $creation->getTileset()->getId(),
                'objects' => count($creation->getObjects()), 'rating' => $creation->getRating(), 'votes' => $creation->getVoteCount(),
                'plays' => $creation->getPlays(), 'favourites' => $creation->getFavourites()],
            $creation instanceof Tileset => ['blocks' => $creation->getCount()] + ($creation->getZone() !== null ? ['zone' => $creation->getZone()] : []),
            $creation instanceof SpriteSet => ['redrawn_sheets' => array_keys($creation->getSheets())],
            $creation instanceof Sound => ['sound_kind' => $creation->getKind()],
        };

        return $out + ['links' => $this->links($creation)];
    }

    /** The pages of the site (absolute): to see it, to change it in the editor, to play it (a level). */
    public function links(object $creation): array
    {
        [$view, $edit] = Creations::ROUTES[Creations::kind($creation)];
        $id = ['id' => $creation->getId()];
        $links = ['page' => $this->urls->generate($view, $id, UrlGeneratorInterface::ABSOLUTE_URL)];
        if (!($creation instanceof Tileset && $creation->getZone() !== null)) $links['editor'] = $this->urls->generate($edit, $id, UrlGeneratorInterface::ABSOLUTE_URL);
        if ($creation instanceof Level) $links['play'] = $this->urls->generate('level_play', $id, UrlGeneratorInterface::ABSOLUTE_URL);

        return $links;
    }

    /** A new creation of this kind may be made (the editors' limits). */
    public function checkRoom(string $kind, User $user): void
    {
        $repository = $this->em->getRepository(Creations::KINDS[$kind]);
        if (count($repository->ofUser($user)) >= self::LIMITS[$kind]) {
            throw new ToolError("This account already has " . self::LIMITS[$kind] . " of kind $kind: put some in the trash first (trash).");
        }
    }

    /** A name for a creation (40 characters, no tags). */
    public static function name(Args $args, string $field = 'name'): string
    {
        $name = \App\Service\EditorData::name($args->raw($field));
        if ($name === null) throw new ToolError("$field: a name of 1 to 40 characters is expected.");

        return $name;
    }
}
