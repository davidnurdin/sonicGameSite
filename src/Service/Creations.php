<?php

namespace App\Service;

use App\Entity\Level;
use App\Entity\Member;
use App\Entity\Revision;
use App\Entity\Sound;
use App\Entity\SpriteSet;
use App\Entity\Tileset;
use App\Entity\User;
use App\Repository\MemberRepository;
use App\Repository\RevisionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the four creations of the editors (a level, tiles, sprites, a sound) have in common: who may change them
 * (the owner and the players he added: Member), their history (Revision: every action, and what the content was
 * after each change, with what changed in words), their forks, and who is in their editor right now.
 */
final class Creations
{
    public const KINDS = ['level' => Level::class, 'tileset' => Tileset::class, 'sprites' => SpriteSet::class, 'sound' => Sound::class];
    /** The page of each kind, its editor, its history. */
    public const ROUTES = [
        'level' => ['level', 'level_edit'], 'tileset' => ['tileset', 'tileset_edit'],
        'sprites' => ['sprites', 'sprites_edit'], 'sound' => ['sound', 'sound_edit'],
    ];
    /** Someone is in an editor if it asked within these seconds (editor-live.js asks every 5). */
    private const PRESENT = 15;
    /** How long a creation stays in its owner's trash before it is deleted for good. */
    public const TRASH_DAYS = 30;

    /** The API key doing the actions of this request (an AI, through the studio's API): said in their history. */
    private ?string $via = null;

    public function __construct(
        private EntityManagerInterface $em,
        private RevisionRepository $revisions,
        private MemberRepository $members,
        #[Autowire(service: 'cache.app')] private CacheItemPoolInterface $cache,
    ) {
    }

    public function setVia(?string $keyName): void
    {
        $this->via = $keyName;
    }

    public static function kind(object $creation): string
    {
        return array_search($creation::class, self::KINDS, true) ?: throw new \InvalidArgumentException($creation::class);
    }

    public function find(string $kind, int $id): ?object
    {
        return isset(self::KINDS[$kind]) ? $this->em->find(self::KINDS[$kind], $id) : null;
    }

    public function isMember(object $creation, ?User $user): bool
    {
        return $user !== null && $creation->getId() !== null && $this->members->isMember(self::kind($creation), $creation->getId(), $user);
    }

    /** The owner, or a player he added. */
    public function canEdit(object $creation, ?User $user): bool
    {
        if ($user === null || $creation->isTrashed() || ($creation instanceof Tileset && $creation->getZone() !== null)) return false;

        return $creation->isOwnedBy($user) || $this->isMember($creation, $user);
    }

    /** Its page: public, or its owner and his players. */
    public function canSee(object $creation, ?User $user): bool
    {
        // in the trash: only its owner (to take it out)
        if ($creation->isTrashed()) return $creation->isOwnedBy($user);
        // tiles and sprites: also those of a public level (it shows them)
        $public = match (true) {
            $creation instanceof Level, $creation instanceof Sound => $creation->canSee($user),
            default => $creation->canUse($user) || $this->em->getRepository($creation::class)->inPublicLevel($creation),
        };

        return $public || $this->isMember($creation, $user);
    }

    /** @return list<object> the forks made from it that $user may see */
    public function forks(object $creation, ?User $user): array
    {
        $forks = $this->em->getRepository($creation::class)->findBy(['forkedFrom' => $creation], ['id' => 'ASC']);

        return array_values(array_filter($forks, fn(object $fork) => $this->canSee($fork, $user)));
    }

    /** Its owner puts it in his trash: nobody else sees it, nobody can use it in a new level (those made with it keep it). */
    public function trash(object $creation, User $user): void
    {
        $creation->setTrashedAt(new \DateTimeImmutable());
        $this->record($creation, $user, 'trash');
        $this->em->flush();
    }

    /** Back from the trash, as it was. */
    public function untrash(object $creation, User $user): void
    {
        $creation->setTrashedAt(null);
        $this->record($creation, $user, 'untrash');
        $this->em->flush();
    }

    /**
     * Deleted for good (its history stays; its forks stay, without the link to it). A set of tiles that levels
     * use (even in a trash) cannot be: false.
     */
    public function purge(object $creation, ?User $user): bool
    {
        if ($creation instanceof Tileset && $this->em->getRepository(Tileset::class)->usedBy($creation) > 0) return false;
        $this->record($creation, $user, 'delete');
        $this->em->remove($creation);
        $this->em->flush();

        return true;
    }

    /** @return list<array{kind: string, creation: object}> what $user has in his trash, the latest put first */
    public function trashOf(User $user): array
    {
        $out = [];
        foreach (self::KINDS as $kind => $class) {
            $list = $this->em->getRepository($class)->createQueryBuilder('c')->where('c.user = :user AND c.trashedAt IS NOT NULL')
                ->setParameter('user', $user)->getQuery()->getResult();
            foreach ($list as $creation) $out[] = ['kind' => $kind, 'creation' => $creation];
        }
        usort($out, fn(array $a, array $b) => $b['creation']->getTrashedAt() <=> $a['creation']->getTrashedAt());

        return $out;
    }

    /** What has been in a trash for more than TRASH_DAYS days ($user: only his), deleted: how many. */
    public function purgeOld(?User $user = null): int
    {
        $before = new \DateTimeImmutable('-' . self::TRASH_DAYS . ' days');
        $deleted = 0;
        foreach (self::KINDS as $class) {
            $qb = $this->em->getRepository($class)->createQueryBuilder('c')->where('c.trashedAt < :before')->setParameter('before', $before);
            if ($user !== null) $qb->andWhere('c.user = :user')->setParameter('user', $user);
            foreach ($qb->getQuery()->getResult() as $creation) $deleted += (int) $this->purge($creation, null);
        }

        return $deleted;
    }

    /** @return list<Member> */
    public function members(object $creation): array
    {
        return $creation->getId() === null ? [] : $this->members->of(self::kind($creation), $creation->getId());
    }

    /** The version an editor starts from: the last change of the content (0: none recorded yet). */
    public function version(object $creation): int
    {
        return $this->revisions->lastContent(self::kind($creation), $creation->getId())?->getId() ?? 0;
    }

    public function lastChange(object $creation): ?Revision
    {
        return $this->revisions->lastContent(self::kind($creation), $creation->getId());
    }

    /** A version of the creation (a Revision with content), or null. */
    public function revision(object $creation, int $id): ?Revision
    {
        $revision = $this->revisions->find($id);

        return $revision !== null && $revision->hasData() && $revision->getKind() === self::kind($creation)
            && $revision->getCreationId() === $creation->getId() ? $revision : null;
    }

    /**
     * A line in its history ($creation must have its id: flushed once). For an action that changes its content, what
     * it is now and what changed since the last version; null if nothing changed (a save of the same thing).
     */
    public function record(object $creation, ?User $user, string $action, ?object $other = null, array $extra = []): ?Revision
    {
        $kind = self::kind($creation);
        $data = $hash = null;
        $changes = $extra;
        if (in_array($action, Revision::CONTENT, true)) {
            $snapshot = self::snapshot($creation);
            $json = (string) json_encode($snapshot);
            $hash = sha1($json);
            $last = $this->revisions->lastContent($kind, $creation->getId());
            if ($action === 'save' && $last?->getHash() === $hash) return null;
            $data = (string) gzcompress($json, 6);
            $before = $last?->getSnapshot();
            $changes = array_merge($extra, $before === null
                ? [[$action === 'save' ? 'Première version de l\'historique' : 'Première version']]
                : self::changes($kind, $before, $snapshot));
        }
        if ($this->via !== null) $changes[] = ['Par l\'API, clé « %key% »', ['%key%' => $this->via]];
        $revision = new Revision($kind, $creation->getId(), $user, $action, $creation->getName(), $changes, $other?->getId(), $data, $hash);
        $this->em->persist($revision);

        return $revision;
    }

    /**
     * A save from an editor that opened version $body['version']: if somebody saved since, what to tell it (409),
     * unless it chose to replace that version (force). Null: it may save.
     */
    public function conflict(object $creation, mixed $body): ?array
    {
        if (!is_array($body) || !empty($body['force']) || !is_int($body['version'] ?? null)) return null;
        $last = $this->lastChange($creation);
        if ($body['version'] === ($last?->getId() ?? 0)) return null;

        return ['error' => 'conflict', 'version' => $last?->getId() ?? 0, 'by' => $last?->getUser()?->getUsername(),
            'at' => $last?->getCreatedAt()->format(DATE_ATOM)];
    }

    /** A save from an editor, flushed: its line in the history (an old version taken again: restored). */
    public function saved(object $creation, ?User $user, mixed $body): void
    {
        $restored = is_array($body) && is_int($body['restored'] ?? null) ? $this->revision($creation, $body['restored']) : null;
        $date = $restored?->getCreatedAt()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i');
        $this->record($creation, $user, $restored ? 'restore' : 'save', null,
            $restored ? [['Reprise de la version du %date%', ['%date%' => $date]]] : []);
        $this->em->flush();
    }

    /** A creation just made, flushed: its first line ($from: the one it was forked from, whose history says it too). */
    public function created(object $creation, User $user, ?object $from = null): void
    {
        if ($from === null) {
            $this->record($creation, $user, 'create');
        } else {
            $this->record($creation, $user, 'fork', $from, [['Forké depuis « %name% » de %user%',
                ['%name%' => $from->getName(), '%user%' => $from->getUser()?->getUsername() ?? 'Sonic PHP']]]);
            $this->record($from, $user, 'forked', $creation, [['Forké par %user%', ['%user%' => $user->getUsername()]]]);
        }
        $this->em->flush();
    }

    /** What makes the creation (what its editor saves), to keep in its history. */
    public static function snapshot(object $creation): array
    {
        return match (true) {
            $creation instanceof Level => [
                'name' => $creation->getName(), 'public' => $creation->isPublic(), 'width' => $creation->getWidth(),
                'height' => $creation->getHeight(), 'blocks' => base64_encode($creation->getBlocks()), 'start' => $creation->getStart(),
                'objects' => $creation->getObjects(), 'music' => $creation->getMusic(),
                'tileset' => $creation->getTileset()->getId(), 'tilesetName' => $creation->getTileset()->getName(),
                'sprites' => $creation->getSpriteSet()?->getId(), 'spritesName' => $creation->getSpriteSet()?->getName(),
                'musicSound' => $creation->getMusicSound()?->getId(), 'sfx' => $creation->getSfx(),
            ],
            $creation instanceof Tileset => [
                'name' => $creation->getName(), 'public' => $creation->isPublic(), 'image' => base64_encode($creation->getImage()),
                'solidity' => $creation->getSolidity(), 'behaviour' => $creation->getBehaviour(), 'masks' => $creation->getMasks(),
                'style' => $creation->getStyle(),
            ],
            $creation instanceof SpriteSet => ['name' => $creation->getName(), 'public' => $creation->isPublic(), 'sheets' => $creation->getSheets()],
            $creation instanceof Sound => ['name' => $creation->getName(), 'public' => $creation->isPublic(), 'kind' => $creation->getKind(),
                'data' => $creation->getData()],
        };
    }

    /** @return list<array{0: string, 1?: array}> what changed from $a to $b, in words (French: the keys of the translations) */
    public static function changes(string $kind, array $a, array $b): array
    {
        $out = [];
        if ($a['name'] !== $b['name']) $out[] = ['Nom : « %from% » → « %to% »', ['%from%' => $a['name'], '%to%' => $b['name']]];
        if ($a['public'] !== $b['public']) $out[] = [$b['public'] ? 'Rendu public' : 'Rendu privé'];
        $count = fn(int $n, string $many, string $one) => $n > 0 ? [[$n > 1 ? $many : $one, ['%n%' => $n]]] : [];
        switch ($kind) {
            case 'level':
                if ([$a['width'], $a['height']] !== [$b['width'], $b['height']]) {
                    $out[] = ['Taille : %from% → %to%', ['%from%' => $a['width'] . '×' . $a['height'], '%to%' => $b['width'] . '×' . $b['height']]];
                } else {
                    $x = base64_decode($a['blocks']);
                    $y = base64_decode($b['blocks']);
                    $changed = 0;
                    for ($i = 0, $n = strlen($y); $i < $n; $i++) if (($x[$i] ?? '') !== $y[$i]) $changed++;
                    $out = array_merge($out, $count($changed, '%n% blocs changés', '%n% bloc changé'));
                }
                [$added, $removed] = self::listDiff($a['objects'], $b['objects']);
                $out = array_merge($out, $count($added, '%n% objets posés', '%n% objet posé'), $count($removed, '%n% objets enlevés', '%n% objet enlevé'));
                if ($a['start'] !== $b['start']) $out[] = ['Départ de Sonic déplacé'];
                if ($a['tileset'] !== $b['tileset']) $out[] = ['Tiles : %to%', ['%to%' => $b['tilesetName']]];
                if ($a['sprites'] !== $b['sprites']) $out[] = $b['sprites'] === null ? ['Sprites du jeu'] : ['Sprites : %to%', ['%to%' => $b['spritesName']]];
                if ($a['music'] !== $b['music'] || $a['musicSound'] !== $b['musicSound']) $out[] = ['Musique changée'];
                if ($a['sfx'] != $b['sfx']) $out[] = ['Bruitages changés'];
                break;
            case 'tileset':
                $blocksA = count($a['solidity']);
                $blocksB = count($b['solidity']);
                if ($blocksA !== $blocksB) $out[] = ['Blocs : %from% → %to%', ['%from%' => $blocksA, '%to%' => $blocksB]];
                if ($a['image'] !== $b['image']) {
                    $drawn = self::blocksDrawn(base64_decode($a['image']), base64_decode($b['image']), min($blocksA, $blocksB));
                    $out = array_merge($out, $drawn === null ? [['Dessin changé']] : $count($drawn, 'Dessin de %n% blocs changé', 'Dessin de %n% bloc changé'));
                }
                $collisions = 0;
                for ($i = 0; $i < min($blocksA, $blocksB); $i++) {
                    if (($a['solidity'][$i] ?? 0) !== ($b['solidity'][$i] ?? 0) || ($a['masks'][$i] ?? null) != ($b['masks'][$i] ?? null)) $collisions++;
                }
                $out = array_merge($out, $count($collisions, 'Collision de %n% blocs changée', 'Collision de %n% bloc changée'));
                $behaviours = 0;
                for ($i = 0; $i < min($blocksA, $blocksB); $i++) if (($a['behaviour'][$i] ?? 0) !== ($b['behaviour'][$i] ?? 0)) $behaviours++;
                $out = array_merge($out, $count($behaviours, 'Effet de %n% blocs changé', 'Effet de %n% bloc changé'));
                break;
            case 'sprites':
                foreach (array_keys(SpriteSet::SHEETS) as $sheet) {
                    $x = $a['sheets'][$sheet] ?? null;
                    $y = $b['sheets'][$sheet] ?? null;
                    if ($x === $y) continue;
                    $out[] = [$y === null ? 'Planche « %sheet% » remise à celle du jeu' : 'Planche « %sheet% » redessinée',
                        ['%sheet%' => SpriteSet::SHEETS[$sheet][2]]];
                }
                break;
            case 'sound':
                $x = $a['data'];
                $y = $b['data'];
                if (($b['kind'] ?? '') === Sound::MUSIC) {
                    if ($x['tempo'] !== $y['tempo']) $out[] = ['Tempo : %from% → %to%', ['%from%' => $x['tempo'], '%to%' => $y['tempo']]];
                    if ($x['bars'] !== $y['bars']) $out[] = ['Mesures : %from% → %to%', ['%from%' => $x['bars'], '%to%' => $y['bars']]];
                    $notes = fn(array $m) => array_merge(...array_map(fn(array $v, int $i) => array_map(fn($n) => [$i, ...$n], $v['notes']),
                        $m['voices'], array_keys($m['voices'])));
                    [$added, $removed] = self::listDiff($notes($x), $notes($y));
                    $out = array_merge($out, $count($added, '%n% notes ajoutées', '%n% note ajoutée'), $count($removed, '%n% notes enlevées', '%n% note enlevée'));
                    if (array_column($x['voices'], 'volume') !== array_column($y['voices'], 'volume')) $out[] = ['Volumes changés'];
                } else {
                    $keys = array_keys(array_filter($y, fn($v, $k) => ($x[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
                    if ($keys) $out[] = ['Réglages changés : %list%', ['%list%' => implode(', ', $keys)]];
                }
                break;
        }

        return $out ?: [['Rien de visible']];
    }

    /** How many of the first $count blocks of a set (32x32, 16 a row) are drawn differently; null: a PNG not read. */
    private static function blocksDrawn(string $a, string $b, int $count): ?int
    {
        $x = Png::rgba($a);
        $y = Png::rgba($b);
        if ($x === null || $y === null || $x[0] !== $y[0]) return null;
        $width = $x[0];
        $changed = 0;
        for ($n = 0; $n < $count; $n++) {
            [$bx, $by] = [($n % 16) * 32, intdiv($n, 16) * 32];
            for ($row = 0; $row < 32; $row++) {
                $at = (($by + $row) * $width + $bx) * 4;
                if (substr($x[2], $at, 128) !== substr($y[2], $at, 128)) {
                    $changed++;
                    break;
                }
            }
        }

        return $changed;
    }

    /** @return array{0: int, 1: int} the items of $b not in $a, and those of $a not in $b (counting the same twice) */
    private static function listDiff(array $a, array $b): array
    {
        $key = fn(array $list) => array_count_values(array_map('json_encode', $list));
        $x = $key($a);
        $y = $key($b);
        $added = $removed = 0;
        foreach ($y as $k => $n) $added += max(0, $n - ($x[$k] ?? 0));
        foreach ($x as $k => $n) $removed += max(0, $n - ($y[$k] ?? 0));

        return [$added, $removed];
    }

    /** The editor of $creation is open for $user: the others who are in it now (their names). */
    public function heartbeat(object $creation, User $user): array
    {
        $item = $this->cache->getItem('editing.' . self::kind($creation) . '.' . $creation->getId());
        $now = time();
        $present = array_filter((array) ($item->isHit() ? $item->get() : []), fn(array $p) => $p[1] > $now - self::PRESENT);
        $present[$user->getId()] = [$user->getUsername(), $now];
        $item->set($present)->expiresAfter(self::PRESENT * 4);
        $this->cache->save($item);
        unset($present[$user->getId()]);

        return array_values(array_map(fn(array $p) => $p[0], $present));
    }
}
