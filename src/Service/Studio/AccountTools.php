<?php

namespace App\Service\Studio;

use App\Entity\Level;
use App\Entity\Revision;
use App\Entity\Sound;
use App\Entity\SoundAudio;
use App\Entity\SpriteSet;
use App\Entity\Tileset;
use App\Repository\MemberRepository;
use App\Repository\RevisionRepository;
use App\Service\Creations;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The account and what is common to every creation: lists, history, fork, public or private, the trash. */
final class AccountTools implements ToolProvider
{
    private const KIND = ['type' => 'string', 'enum' => Studio::KINDS, 'description' => 'level, tileset, sprites or sound'];
    private const ID = ['type' => 'integer', 'minimum' => 1];

    public function __construct(
        private Studio $studio,
        private Reference $reference,
        private RevisionRepository $revisions,
        private MemberRepository $members,
        private TranslatorInterface $translator,
    ) {
    }

    public function tools(): array
    {
        return [
            new Tool('whoami', 'Who am I', 'The account this key acts for, what the key may do, how many creations of each kind it has and may have. Start here.',
                ['properties' => (object) []], $this->whoami(...)),
            new Tool('get_reference', 'Reference', 'How the game and its editors work: formats, rules, physics, collision shapes, objects, music, a way to work. '
                . 'Read "workflow" first, then what you need. Topics: ' . implode(', ', Reference::TOPICS) . '.',
                ['properties' => ['topic' => ['type' => 'string', 'enum' => Reference::TOPICS, 'default' => 'workflow']]], $this->getReference(...)),
            new Tool('list_creations', 'List creations', 'Lists levels, tilesets, sprites or sounds: this account\'s ("mine"), those it was added to ("shared"), '
                . 'the public ones of everybody ("public"), the zones of the game (tileset, "zones"), or what is in its trash ("trash").',
                ['properties' => ['kind' => self::KIND, 'scope' => ['type' => 'string', 'enum' => ['mine', 'shared', 'public', 'zones', 'trash'], 'default' => 'mine'],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]], 'required' => ['kind']], $this->list(...)),
            new Tool('get_history', 'History', 'Every action on a creation, the latest first: who, when, what changed (with the name of the API key when it was done through the API).',
                ['properties' => ['kind' => self::KIND, 'id' => self::ID, 'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]], 'required' => ['kind', 'id']],
                $this->history(...)),
            new Tool('fork', 'Fork', 'Makes this account\'s own copy of a creation it can see (a zone of the game, a public creation, its own): the copy says where it comes from. '
                . 'The way to change what is not this account\'s.',
                ['properties' => ['kind' => self::KIND, 'id' => self::ID, 'name' => ['type' => 'string', 'maxLength' => 40]], 'required' => ['kind', 'id']],
                $this->fork(...), write: true),
            new Tool('set_public', 'Public or private', 'Makes a creation of this account public (everybody sees it, may play it and fork it) or private (only the account '
                . 'and the players it added). Do it only when the player wants it published.',
                ['properties' => ['kind' => self::KIND, 'id' => self::ID, 'public' => ['type' => 'boolean']], 'required' => ['kind', 'id', 'public']],
                $this->setPublic(...), write: true),
            new Tool('trash', 'Put in the trash', 'Puts a creation of this account in its trash: hidden from the others, kept 30 days, the player can restore it '
                . '(restore). Its forks and the levels made with it keep working. Nothing is deleted for good through the API.',
                ['properties' => ['kind' => self::KIND, 'id' => self::ID], 'required' => ['kind', 'id']], $this->trash(...), write: true, destructive: true),
            new Tool('restore', 'Restore from the trash', 'Takes a creation of this account out of its trash, as it was.',
                ['properties' => ['kind' => self::KIND, 'id' => self::ID], 'required' => ['kind', 'id']], $this->restore(...), write: true),
        ];
    }

    private function whoami(Args $args, Context $c): Result
    {
        $counts = [];
        foreach (Studio::KINDS as $kind) {
            $counts[$kind] = ['have' => count($this->studio->em->getRepository(Creations::KINDS[$kind])->ofUser($c->user)), 'limit' => Studio::LIMITS[$kind]];
        }

        return new Result([
            'account' => $c->user->getUsername(),
            'key' => ['name' => $c->key->getName(), 'can_write' => $c->key->canWrite(), 'expires' => $c->key->getExpiresAt()?->format(DATE_ATOM)],
            'creations' => $counts,
            'shared_with_this_account' => count($this->members->ofUser($c->user)),
            'next' => 'get_reference topic "workflow".',
        ]);
    }

    private function getReference(Args $args, Context $c): Result
    {
        return new Result(['topic' => $topic = $args->choice('topic', Reference::TOPICS, 'workflow')] + $this->reference->topic($topic));
    }

    private function list(Args $args, Context $c): Result
    {
        $kind = $args->choice('kind', Studio::KINDS);
        $scope = $args->choice('scope', ['mine', 'shared', 'public', 'zones', 'trash'], 'mine');
        $page = $args->int('page', 1, 1000, 1);
        $repository = $this->studio->em->getRepository(Creations::KINDS[$kind]);
        $list = match ($scope) {
            'mine' => $repository->ofUser($c->user),
            'shared' => array_values(array_filter(array_map(fn($m) => $m->getKind() === $kind ? $this->studio->creations->find($kind, $m->getCreationId()) : null,
                $this->members->ofUser($c->user)), fn($x) => $x !== null && !$x->isTrashed())),
            'trash' => array_column(array_filter($this->studio->creations->trashOf($c->user), fn($i) => $i['kind'] === $kind), 'creation'),
            'zones' => $kind === 'tileset' ? $repository->zones() : throw new ToolError('scope "zones" is for kind "tileset".'),
            'public' => match ($kind) {
                'level' => $repository->publicPage($page, 50)[0],
                'sound' => $repository->publicOnes(200),
                default => $repository->publicOnes(200),
            },
        };
        $perPage = 50;
        $total = $scope === 'public' && $kind === 'level' ? $repository->publicPage(1, 1)[1] : count($list);
        if (!($scope === 'public' && $kind === 'level')) $list = array_slice($list, ($page - 1) * $perPage, $perPage);

        return new Result([
            'kind' => $kind, 'scope' => $scope, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage)), 'total' => $total,
            'items' => array_map(fn(object $x) => $this->studio->summary($x, $c->user), $list),
        ]);
    }

    private function history(Args $args, Context $c): Result
    {
        $kind = $args->choice('kind', Studio::KINDS);
        $creation = $this->studio->find($kind, $args->raw('id'), $c->user);
        $page = $args->int('page', 1, 1000, 1);
        $lines = array_map(fn(Revision $r) => [
            'id' => $r->getId(), 'at' => $r->getCreatedAt()->format(DATE_ATOM), 'action' => $r->getAction(), 'by' => $r->getUser()?->getUsername(),
            'changes' => array_map(fn(array $change) => $this->translator->trans($change[0], $change[1] ?? [], null, 'en'), $r->getChanges()),
        ], $this->revisions->of($kind, $creation->getId(), $page, 50));

        return new Result(['creation' => $this->studio->summary($creation, $c->user), 'page' => $page,
            'pages' => max(1, (int) ceil($this->revisions->countOf($kind, $creation->getId()) / 50)), 'lines' => $lines]);
    }

    private function fork(Args $args, Context $c): Result
    {
        $kind = $args->choice('kind', Studio::KINDS);
        $from = $this->studio->find($kind, $args->raw('id'), $c->user);
        $this->studio->checkRoom($kind, $c->user);
        $name = $args->has('name') ? Studio::name($args) : mb_substr($from->getName(), 0, 33) . ' (copie)';
        $user = $c->user;
        $em = $this->studio->em;
        $copy = match (true) {
            $from instanceof Level => $this->forkLevel($from, $name, $c),
            $from instanceof Tileset => new Tileset($user, $name, $from->getImage(), $from->getSolidity(), $from->getBehaviour(), $from->getStyle(), $from->getMasks(), $from->getAnimations()),
            $from instanceof SpriteSet => new SpriteSet($user, $name, $from->getSheets()),
            $from instanceof Sound => new Sound($user, $name, $from->getKind(), $from->getData()),
        };
        $copy->setForkedFrom($from);
        $em->persist($copy);
        $em->flush();
        if ($from instanceof Sound && ($audio = $em->find(SoundAudio::class, $from)?->getWav()) !== null) {
            $em->persist(new SoundAudio($copy, $audio));
            $em->flush();
        }
        $this->studio->creations->created($copy, $user, $from);

        return new Result(['forked' => $this->studio->summary($copy, $user), 'from' => ['kind' => $kind, 'id' => $from->getId(), 'name' => $from->getName()]]);
    }

    /** A copy of a level, with what of it this account may use (as the editor's "Forker"). */
    private function forkLevel(Level $from, string $name, Context $c): Level
    {
        $copy = new Level($c->user, $from->getTileset(), $name, $from->getWidth(), $from->getHeight(), $from->getBlocks(), $from->getStart(), $from->getObjects());
        $copy->setThumb($from->getThumb());
        $copy->setMusic($from->getMusic());
        if ($from->getSpriteSet()?->canUse($c->user)) $copy->setSpriteSet($from->getSpriteSet());
        if ($from->getMusicSound()?->canSee($c->user)) $copy->setMusicSound($from->getMusicSound());
        $sounds = $this->studio->em->getRepository(Sound::class);
        $copy->setSfx(array_filter($from->getSfx(), fn(int $id) => $sounds->find($id)?->canSee($c->user) ?? false));

        return $copy;
    }

    private function setPublic(Args $args, Context $c): Result
    {
        $kind = $args->choice('kind', Studio::KINDS);
        $creation = $this->studio->find($kind, $args->raw('id'), $c->user, true);
        if (!$creation->isOwnedBy($c->user)) throw new ToolError('Only its owner makes it public or private.');
        $creation->setPublic($args->bool('public'));
        $this->studio->em->flush();
        $this->studio->creations->saved($creation, $c->user, null);
        $out = ['creation' => $this->studio->summary($creation, $c->user)];
        if ($creation instanceof Level && $creation->isPublic()) {
            $private = array_filter([$creation->getTileset(), $creation->getSpriteSet(), $creation->getMusicSound()],
                fn($x) => $x !== null && !$x->isPublic() && !($x instanceof Tileset && $x->getZone() !== null));
            if ($private) $out['note'] = 'Some parts it uses are private: ' . implode(', ', array_map(fn($x) => Creations::kind($x) . ' #' . $x->getId(), $private))
                . '. The others can play it with them (a level shows them), but not reuse them; make them public too if the player wants.';
        }

        return new Result($out);
    }

    private function trash(Args $args, Context $c): Result
    {
        $kind = $args->choice('kind', Studio::KINDS);
        $creation = $this->studio->find($kind, $args->raw('id'), $c->user);
        if (!$creation->isOwnedBy($c->user)) throw new ToolError('Only its owner puts it in the trash.');
        if ($creation->isTrashed()) throw new ToolError('It is already in the trash.');
        $this->studio->creations->trash($creation, $c->user);

        return new Result(['trashed' => $this->studio->summary($creation, $c->user), 'note' => 'In the trash for 30 days: restore takes it back.']);
    }

    private function restore(Args $args, Context $c): Result
    {
        $kind = $args->choice('kind', Studio::KINDS);
        $creation = $this->studio->find($kind, $args->raw('id'), $c->user);
        if (!$creation->isOwnedBy($c->user) || !$creation->isTrashed()) throw new ToolError('Not in this account\'s trash.');
        $this->studio->creations->untrash($creation, $c->user);

        return new Result(['restored' => $this->studio->summary($creation, $c->user)]);
    }
}
