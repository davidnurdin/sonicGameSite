<?php

namespace App\State\PublicApi;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\UrlGeneratorInterface as IriType;
use App\ApiResource;
use App\Entity;
use App\Repository\PlayerStatsRepository;
use App\Service\Creations;
use App\Service\EditorData;
use App\Service\Game;
use App\Service\SoundData;
use App\Service\Studio\Solidity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the public API shows of the site: only what a visitor who is not signed in sees on it (Creations::canSee
 * with no user), turned into the DTOs of App\ApiResource. Never an entity, never anything private (e-mails, private
 * creations, the keys of private levels, the names of API keys).
 */
final class PublicView
{
    private const RINGS = [0x78, 0x79, 0x7A, 0x7B];
    private const GOAL = 7;

    public function __construct(
        public readonly EntityManagerInterface $em,
        public readonly Creations $creations,
        private PlayerStatsRepository $stats,
        private IriConverterInterface $iris,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        #[Autowire(env: 'GAME_URL')] private string $gameUrl,
    ) {
    }

    /** What a visitor may see of a creation (public, a zone of the game, or a part of a public level; not in a trash). */
    public function visible(?object $creation): bool
    {
        return $creation !== null && $this->creations->canSee($creation, null);
    }

    public function level(Entity\Level $level): ApiResource\Level
    {
        $dto = new ApiResource\Level();
        $dto->id = $level->getId();
        $dto->name = $level->getName();
        $dto->author = ApiResource\Player::ref($level->getUser()->getUsername());
        $dto->authorName = $level->getUser()->getUsername();
        $dto->tileset = ApiResource\Tileset::ref($level->getTileset()->getId());
        $dto->sprites = $level->getSpriteSet() !== null ? ApiResource\SpriteSet::ref($level->getSpriteSet()->getId()) : null;
        $dto->music = $this->visible($level->getMusicSound()) ? ApiResource\Sound::ref($level->getMusicSound()->getId()) : null;
        $dto->gameMusic = $level->getMusic();
        $dto->gameMusicName = EditorData::MUSICS[$level->getMusic()] ?? 'Green Hill';
        foreach ($level->getSfx() as $index => $id) {
            if (!$this->visible($this->em->find(Entity\Sound::class, (int) $id))) continue;
            $dto->soundEffects[] = ['gameSound' => (int) $index, 'gameSoundName' => SoundData::GAME_SFX[(int) $index] ?? '?',
                'sound' => $this->iri(ApiResource\Sound::class, ['id' => (int) $id])];
        }
        $dto->width = $level->getWidth();
        $dto->height = $level->getHeight();
        [$x, $y] = $level->getStart();
        $dto->start = ['x' => (int) $x, 'y' => (int) $y];
        $dto->objectCount = count($level->getObjects());
        $blocks = count_chars($level->getBlocks(), 1);
        $dto->ringCount = array_sum(array_map(fn(int $n) => $blocks[$n] ?? 0, self::RINGS));
        $dto->hasGoal = (bool) array_filter($level->getObjects(), fn(array $o) => (int) $o[0] === self::GOAL);
        $dto->forkedFrom = $this->visible($level->getForkedFrom()) ? ApiResource\Level::ref($level->getForkedFrom()->getId()) : null;
        $dto->replayCount = (int) $this->em->createQuery('SELECT COUNT(r.id) FROM ' . Entity\Replay::class . ' r WHERE r.siteLevel = :l')
            ->setParameter('l', $level)->getSingleScalarResult();
        $dto->createdAt = $level->getCreatedAt();
        $dto->updatedAt = $level->getUpdatedAt();
        $id = ['id' => $level->getId()];
        $dto->links = [
            'page' => $this->url('level', $id), 'play' => $this->url('level_play', $id), 'thumbnail' => $this->url('level_png', $id),
            'map' => $this->iri(ApiResource\LevelMap::class, $id), 'history' => $this->iri(ApiResource\HistoryEntry::class, $id, '/levels/{id}/history'),
        ];

        return $dto;
    }

    public function levelMap(Entity\Level $level): ApiResource\LevelMap
    {
        $dto = new ApiResource\LevelMap();
        $dto->id = $level->getId();
        $dto->level = ApiResource\Level::ref($level->getId());
        $dto->tileset = ApiResource\Tileset::ref($level->getTileset()->getId());
        $dto->width = $level->getWidth();
        $dto->height = $level->getHeight();
        $dto->blocks = array_map(fn(string $row) => array_values(unpack('C*', $row)), str_split($level->getBlocks(), $level->getWidth()));
        [$x, $y] = $level->getStart();
        $dto->start = ['x' => (int) $x, 'y' => (int) $y];
        $dto->objects = array_map(fn(array $o) => [
            'type' => (int) $o[0], 'name' => EditorData::OBJECTS[(int) $o[0]][0] ?? '?', 'family' => EditorData::OBJECTS[(int) $o[0]][1] ?? 'other',
            'x' => (int) $o[1], 'y' => (int) $o[2],
        ], $level->getObjects());

        return $dto;
    }

    public function tileset(Entity\Tileset $tileset): ApiResource\Tileset
    {
        $dto = new ApiResource\Tileset();
        $dto->id = $tileset->getId();
        $dto->name = $tileset->getName();
        if ($tileset->getUser() !== null) {
            $dto->author = ApiResource\Player::ref($tileset->getUser()->getUsername());
            $dto->authorName = $tileset->getUser()->getUsername();
        }
        $dto->zone = $tileset->getZone();
        $dto->public = $tileset->isPublic() || $tileset->getZone() !== null;
        $dto->blockCount = $tileset->getCount();
        $dto->forkedFrom = $this->visible($tileset->getForkedFrom()) ? ApiResource\Tileset::ref($tileset->getForkedFrom()->getId()) : null;
        $dto->createdAt = $tileset->getCreatedAt();
        $dto->updatedAt = $tileset->getUpdatedAt();
        $id = ['id' => $tileset->getId()];
        $dto->links = [
            'page' => $this->url('tileset', $id), 'image' => $this->url('tileset_png', $id),
            'blocks' => $this->iri(ApiResource\TilesetBlocks::class, $id), 'history' => $this->iri(ApiResource\HistoryEntry::class, $id, '/tilesets/{id}/history'),
        ];

        return $dto;
    }

    public function tilesetBlocks(Entity\Tileset $tileset): ApiResource\TilesetBlocks
    {
        $dto = new ApiResource\TilesetBlocks();
        $dto->id = $tileset->getId();
        $dto->tileset = ApiResource\Tileset::ref($tileset->getId());
        $dto->image = $this->url('tileset_png', ['id' => $tileset->getId()]);
        $behaviours = $tileset->getBehaviour();
        $masks = $tileset->getMasks();
        $animations = $tileset->getAnimations();
        $dto->blocks = [];
        foreach ($tileset->getSolidity() as $i => $solidity) {
            $shape = $solidity & 0x3F;
            $ways = isset($masks[$i])
                ? ['floor' => (bool) ($masks[$i]['w'] & 1), 'walls' => (bool) ($masks[$i]['w'] & 2), 'ceiling' => (bool) ($masks[$i]['w'] & 4)]
                : ($shape === 0 ? ['floor' => false, 'walls' => false, 'ceiling' => false] : Solidity::ways($shape));
            $behaviour = (int) ($behaviours[$i] ?? 0);
            $dto->blocks[] = [
                'index' => $i, 'x' => ($i % Entity\Tileset::COLUMNS) * Entity\Tileset::SIZE, 'y' => intdiv($i, Entity\Tileset::COLUMNS) * Entity\Tileset::SIZE,
                'shape' => $shape, 'solidFloor' => $ways['floor'], 'solidWalls' => $ways['walls'], 'solidCeiling' => $ways['ceiling'],
                'front' => (bool) ($solidity & 0x80), 'behaviour' => $behaviour, 'behaviourName' => EditorData::BEHAVIOURS[$behaviour] ?? '?',
                'customCollision' => isset($masks[$i]), 'ring' => in_array($i, self::RINGS, true),
                'animation' => isset($animations[$i]) ? ['frames' => $animations[$i]['f'], 'ticks' => $animations[$i]['t']] : null,
            ];
        }

        return $dto;
    }

    public function spriteSet(Entity\SpriteSet $set): ApiResource\SpriteSet
    {
        $dto = new ApiResource\SpriteSet();
        $dto->id = $set->getId();
        $dto->name = $set->getName();
        $dto->author = ApiResource\Player::ref($set->getUser()->getUsername());
        $dto->authorName = $set->getUser()->getUsername();
        $dto->public = $set->isPublic();
        $redrawn = $set->getSheets();
        foreach (Entity\SpriteSet::SHEETS as $sheet => [$width, $height, $label]) {
            $dto->sheets[] = ['sheet' => $sheet, 'label' => $label, 'width' => $width, 'height' => $height, 'redrawn' => isset($redrawn[$sheet]),
                'image' => $this->url('sprites_png', ['id' => $set->getId(), 'sheet' => $sheet])];
        }
        $dto->forkedFrom = $this->visible($set->getForkedFrom()) ? ApiResource\SpriteSet::ref($set->getForkedFrom()->getId()) : null;
        $dto->createdAt = $set->getCreatedAt();
        $dto->updatedAt = $set->getUpdatedAt();
        $id = ['id' => $set->getId()];
        $dto->links = ['page' => $this->url('sprites', $id), 'history' => $this->iri(ApiResource\HistoryEntry::class, $id, '/sprites/{id}/history')];

        return $dto;
    }

    public function sound(Entity\Sound $sound): ApiResource\Sound
    {
        $dto = new ApiResource\Sound();
        $dto->id = $sound->getId();
        $dto->name = $sound->getName();
        $dto->kind = $sound->getKind();
        $dto->author = ApiResource\Player::ref($sound->getUser()->getUsername());
        $dto->authorName = $sound->getUser()->getUsername();
        $dto->public = $sound->isPublic();
        $data = $sound->getData();
        $dto->duration = round($sound->isMusic()
            ? (int) ($data['bars'] ?? 0) * 16 * 15 / max(1, (int) ($data['tempo'] ?? 120))
            : (((int) ($data['attack'] ?? 0) + (int) ($data['sustain'] ?? 0) + (int) ($data['decay'] ?? 0)) * max(1, (int) ($data['repeat'] ?? 1))
                + (int) ($data['gap'] ?? 0) * (max(1, (int) ($data['repeat'] ?? 1)) - 1)) / 1000, 2);
        $dto->data = $data;
        $dto->forkedFrom = $this->visible($sound->getForkedFrom()) ? ApiResource\Sound::ref($sound->getForkedFrom()->getId()) : null;
        $dto->createdAt = $sound->getCreatedAt();
        $dto->updatedAt = $sound->getUpdatedAt();
        $id = ['id' => $sound->getId()];
        $dto->links = ['page' => $this->url('sound', $id), 'audio' => $this->url('sound_wav', $id),
            'history' => $this->iri(ApiResource\HistoryEntry::class, $id, '/sounds/{id}/history')];

        return $dto;
    }

    /**
     * Players, with what their public profile shows.
     *
     * @param list<Entity\User> $users
     * @return list<ApiResource\Player>
     */
    public function players(array $users): array
    {
        if ($users === []) return [];
        $times = $this->stats->timesOf($users);
        $counts = [];
        foreach (['levels' => Entity\Level::class, 'tilesets' => Entity\Tileset::class, 'sprites' => Entity\SpriteSet::class, 'sounds' => Entity\Sound::class] as $key => $class) {
            $rows = $this->em->createQuery("SELECT IDENTITY(c.user) AS u, COUNT(c.id) AS n FROM $class c WHERE c.user IN (:users) AND c.public = true AND c.trashedAt IS NULL GROUP BY c.user")
                ->setParameter('users', $users)->getArrayResult();
            foreach ($rows as $row) $counts[(int) $row['u']][$key] = (int) $row['n'];
        }
        $replays = [];
        foreach ($this->em->createQuery('SELECT IDENTITY(r.user) AS u, COUNT(r.id) AS n FROM ' . Entity\Replay::class . ' r WHERE r.user IN (:users) GROUP BY r.user')
            ->setParameter('users', $users)->getArrayResult() as $row) $replays[(int) $row['u']] = (int) $row['n'];

        return array_map(function (Entity\User $user) use ($times, $counts, $replays) {
            $dto = new ApiResource\Player();
            $dto->username = $user->getUsername();
            $dto->memberSince = $user->getCreatedAt();
            $dto->playTime = (int) round($times[$user->getId()] ?? 0);
            $dto->publicCreations = array_merge(['levels' => 0, 'tilesets' => 0, 'sprites' => 0, 'sounds' => 0], $counts[$user->getId()] ?? []);
            $dto->replayCount = $replays[$user->getId()] ?? 0;
            $name = ['author' => $user->getUsername()];
            $dto->links = [
                'profile' => $this->url('profile', ['username' => $user->getUsername()]),
                'levels' => $this->collection('/levels', $name),
                'tilesets' => $this->collection('/tilesets', $name),
                'sprites' => $this->collection('/sprites', $name),
                'sounds' => $this->collection('/sounds', $name),
                'replays' => $this->collection('/replays', ['player' => $user->getUsername()]),
            ];

            return $dto;
        }, $users);
    }

    public function replay(Entity\Replay $replay): ApiResource\Replay
    {
        $dto = new ApiResource\Replay();
        $dto->id = $replay->getId();
        $dto->kind = $replay->getKind();
        $dto->mode = $replay->getKind() === 'solo' ? null : $replay->getMode();
        $dto->gameLevel = $replay->getLevel();
        $site = $replay->getSiteLevel();
        if ($replay->getLevel() === Game::SITE_LEVEL) {
            $dto->level = $this->visible($site) ? ApiResource\Level::ref($site->getId()) : null;
            $dto->levelName = $this->visible($site) ? $site->getName() : 'Niveau du site';
        } else {
            $dto->levelName = Game::levelName($replay->getLevel());
        }
        $dto->player = ApiResource\Player::ref($replay->getUser()->getUsername());
        $dto->players = array_values(array_map('strval', $replay->getPlayers()));
        $dto->ranking = array_map(fn(array $row) => ['player' => (string) ($row[0] ?? ''), 'result' => (string) ($row[1] ?? '')], array_values($replay->getResult()));
        $dto->duration = round($replay->getDuration(), 2);
        $dto->playedAt = $replay->getCreatedAt();
        $dto->links = ['file' => $this->url('api_replay_get', ['id' => $replay->getId()])];
        // as the history page: the game on this replay (not a private level of the site, it could not be played)
        if ($replay->getLevel() !== Game::SITE_LEVEL || $this->visible($site)) {
            $dto->links['watch'] = $this->gameUrl . (str_contains($this->gameUrl, '?') ? '&' : '?')
                . http_build_query(['site' => $this->urls->generate('home', [], UrlGeneratorInterface::ABSOLUTE_URL)]) . '&replay=' . $replay->getId();
        }

        return $dto;
    }

    public function history(Entity\Revision $revision, string $lang): ApiResource\HistoryEntry
    {
        $dto = new ApiResource\HistoryEntry();
        $dto->id = $revision->getId();
        $dto->kind = $revision->getKind();
        $dto->creation = $this->iri([
            'level' => ApiResource\Level::class, 'tileset' => ApiResource\Tileset::class, 'sprites' => ApiResource\SpriteSet::class, 'sound' => ApiResource\Sound::class,
        ][$revision->getKind()], ['id' => $revision->getCreationId()]);
        $dto->action = $revision->getAction();
        $dto->by = $revision->getUser() !== null ? ApiResource\Player::ref($revision->getUser()->getUsername()) : null;
        $dto->at = $revision->getCreatedAt();
        // the name of an API key is for those who work on the creation
        $dto->changes = array_map(fn(array $change) => $change[0] === 'Par l\'API, clé « %key% »'
            ? $this->translator->trans('Par l\'API', [], null, $lang)
            : $this->translator->trans($change[0], $change[1] ?? [], null, $lang), $revision->getChanges());

        return $dto;
    }

    /** The IRI of a resource of the API (its item operation, or a list of it: $uriTemplate, as "/levels/{id}/history"). */
    private function iri(string $class, array $variables, ?string $uriTemplate = null): string
    {
        if ($uriTemplate !== null) return $this->urls->generate('_api_' . $uriTemplate . '_get_collection', $variables);

        return (string) $this->iris->getIriFromResource($class, IriType::ABS_PATH, null, ['uri_variables' => $variables]);
    }

    /** The address of a list of the API (its address template, as "/levels"), with filters. */
    private function collection(string $uriTemplate, array $filters): string
    {
        return $this->urls->generate('_api_' . $uriTemplate . '_get_collection', $filters);
    }

    private function url(string $route, array $parameters): string
    {
        return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
