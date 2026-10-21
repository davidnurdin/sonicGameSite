<?php

namespace App\Controller;

use App\Entity\Level;
use App\Entity\Sound;
use App\Entity\SoundAudio;
use App\Entity\SpriteSet;
use App\Entity\Tileset;
use App\Entity\User;
use App\Repository\LevelRepository;
use App\Repository\SoundRepository;
use App\Repository\SpriteSetRepository;
use App\Repository\TilesetRepository;
use App\Repository\UserRepository;
use App\Service\Creations;
use App\Service\EditorData;
use App\Service\LevelToken;
use App\Service\SoundData;
use App\Service\StatsToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The level editor and the tile editor (public/js/editor-*.js): the pages, and what they read and save (JSON,
 * with the CSRF token "editor" in the X-CSRF-Token header). A public level or set is seen by everybody; only
 * its owner changes it. The sprite editor is SpriteController.
 */
class EditorController extends AbstractController
{
    private const PER_PAGE = 24;
    /** Levels and sets an account may have. */
    private const MAX_EACH = 100;
    /** Levels a page of the game's menu NIVEAUX DU SITE. */
    private const GAME_PAGE = 6;

    public function __construct(
        private EntityManagerInterface $em,
        private LevelRepository $levels,
        private TilesetRepository $tilesets,
        private SpriteSetRepository $spriteSets,
        private SoundRepository $sounds,
        private TranslatorInterface $translator,
        #[Autowire(env: 'GAME_URL')] private string $gameUrl,
        private Creations $creations,
    ) {
    }

    #[Route('/editeur', name: 'editor', methods: ['GET'])]
    public function hub(Request $request, EditorData $data): Response
    {
        $user = $this->user();
        $page = max(1, $request->query->getInt('page', 1));
        [$public, $total] = $this->levels->publicPage($page, self::PER_PAGE);

        return $this->render('editor/index.html.twig', [
            'myLevels' => $user ? $this->levels->ofUser($user) : [],
            'myTilesets' => $user ? $this->tilesets->ofUser($user) : [],
            'mySprites' => $user ? $this->spriteSets->ofUser($user) : [],
            'publicSprites' => array_filter($this->spriteSets->publicOnes(), fn(SpriteSet $s) => !$s->isOwnedBy($user)),
            'mySounds' => $user ? $this->sounds->ofUser($user) : [],
            'publicSounds' => array_filter($this->sounds->publicOnes(), fn(Sound $s) => !$s->isOwnedBy($user)),
            'publicLevels' => $public,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'zones' => $this->tilesets->zones(),
            'publicTilesets' => $this->tilesets->publicOnes(),
            'usable' => $this->tilesets->usable($user),
            'templates' => $data->templates(),
            'shared' => $user ? $this->shared($user) : [],
        ]);
    }

    // ---- levels

    #[Route('/niveau/nouveau', name: 'level_new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function newLevel(Request $request, EditorData $data): Response
    {
        $user = $this->user();
        if (!$this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) return $this->redirectToRoute('editor');
        if (count($this->levels->ofUser($user)) >= self::MAX_EACH) return $this->back('Tu as déjà %n% niveaux.', self::MAX_EACH);
        $name = EditorData::name($request->request->get('name')) ?? 'NIVEAU';
        $template = $data->templates()[(string) $request->request->get('template')] ?? null;
        if ($template !== null) {
            $tileset = $this->tilesets->findOneBy(['zone' => $template['zone']]);
            $level = new Level($user, $tileset, $name, $template['width'], $template['height'], base64_decode($template['blocks']),
                $template['start'], array_values(array_filter($template['objects'], fn($o) => isset(EditorData::OBJECTS[$o[0]]))));
            $level->setMusic((int) ($template['music'] ?? 0));
        } else {
            $tileset = $this->tilesets->find($request->request->getInt('tileset'));
            if (!$tileset instanceof Tileset || !$tileset->canUse($user)) return $this->redirectToRoute('editor');
            [$width, $height] = [64, 16];
            // a ground of the first block that is solid all over, if the set has one
            $ground = array_search(22, array_map(fn($v) => $v & 0x3F, $tileset->getSolidity()), true);
            $blocks = str_repeat("\0", $width * ($height - 3)) . str_repeat(chr($ground === false ? 0 : $ground), $width * 3);
            $level = new Level($user, $tileset, $name, $width, $height, $blocks, [3, $height - 5], []);
            // the song of the zone of its tiles
            $level->setMusic([0, 1, 2, 3, 4, 5, 0x10][$tileset->getStyle()] ?? 0);
        }
        $this->em->persist($level);
        $this->em->flush();
        $this->creations->created($level, $user);

        return $this->redirectToRoute('level_edit', ['id' => $level->getId()]);
    }

    #[Route('/niveau/{id}', name: 'level', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function level(Level $level): Response
    {
        if (!$this->creations->canSee($level, $this->user())) throw $this->createNotFoundException();

        return $this->render('editor/level.html.twig', ['level' => $level, 'edit' => false, 'objects' => $this->objectNames()]);
    }

    #[Route('/niveau/{id}/editer', name: 'level_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function editLevel(Level $level, Request $request): Response
    {
        if (!$this->creations->canEdit($level, $this->user())) return $this->redirectToRoute('level', ['id' => $level->getId()]);

        return $this->render('editor/level.html.twig', [
            'level' => $level,
            'edit' => true,
            'restored' => $this->creations->revision($level, $request->query->getInt('version'))?->getId(),
            'usable' => $this->tilesets->usable($this->user()),
            'objects' => $this->objectNames(),
            'musics' => EditorData::MUSICS,
            'spriteSets' => $this->spriteSets->usable($this->user()),
            'musicSounds' => $this->sounds->usable($this->user(), Sound::MUSIC),
            'sfxSounds' => $this->sounds->usable($this->user(), Sound::SFX),
            'gameSfx' => SoundData::GAME_SFX,
        ]);
    }

    #[Route('/niveau/{id}.json', name: 'level_json', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function levelJson(Level $level, Request $request): JsonResponse
    {
        if (!$this->creations->canSee($level, $this->user())) throw $this->createNotFoundException();
        // a version of its history (to open it in the editor)
        $old = $this->creations->revision($level, $request->query->getInt('version'))?->getSnapshot();
        if ($old !== null) {
            $tileset = $this->tilesets->find($old['tileset']) ?? $level->getTileset();

            return $this->json(['id' => $level->getId(), 'blocks' => $old['blocks'], 'sfx' => (object) $old['sfx'],
                'tileset' => $this->tilesetData($tileset)] + $old);
        }

        return $this->json($this->levelData($level));
    }

    #[Route('/niveau/{id}.png', name: 'level_png', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function levelPng(Level $level): Response
    {
        if (!$this->creations->canSee($level, $this->user())) throw $this->createNotFoundException();
        $png = $level->getThumb();
        // not saved by the editor yet: the zone's blocks
        $response = new Response($png ?? $level->getTileset()->getImage(), 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
        $response->setLastModified(\DateTime::createFromImmutable($level->getUpdatedAt()));
        $level->isPublic() ? $response->setPublic() : $response->setPrivate();

        return $response;
    }

    /** JOUER: the game, on this level (?level=, ?lt= its key), back to this page after. */
    #[Route('/niveau/{id}/jouer', name: 'level_play', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function play(Level $level, Request $request, LevelToken $tokens, StatsToken $stats): Response
    {
        $user = $this->user();
        if (!$this->creations->canSee($level, $user)) throw $this->createNotFoundException();
        $query = [
            'lang' => $request->getLocale(),
            'site' => $request->getSchemeAndHttpHost(),
            'back' => $this->generateUrl('level', ['id' => $level->getId()], 0),
            'level' => $level->getId(),
            'lt' => $tokens->create($level),
        ];
        if ($user !== null) $query += ['name' => $user->getUsername(), 'st' => $stats->create($user)];

        return $this->redirect($this->gameUrl . (str_contains($this->gameUrl, '?') ? '&' : '?') . http_build_query($query));
    }

    /**
     * What the game's page needs to play the level (another domain: CORS, no cookie): the level, the collision
     * and behaviour of its blocks, its tiles (PNG, base64). Public, or with the key of a JOUER link.
     */
    #[Route('/niveau/{id}/jeu.json', name: 'level_game', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function game(Level $level, Request $request, LevelToken $tokens): JsonResponse
    {
        $response = new JsonResponse();
        $response->headers->set('Access-Control-Allow-Origin', '*');
        if ($level->isTrashed() || (!$level->isPublic() && !$tokens->isValid($level, (string) $request->query->get('lt')))) {
            return $response->setData(['error' => 'not found'])->setStatusCode(404);
        }
        $tileset = $level->getTileset();

        return $response->setData([
            'id' => $level->getId(),
            'name' => $level->getName(),
            // who made it: the game shows it
            'author' => $level->getUser()->getUsername(),
            // its key (a private level): the game puts it in the replays and the matches played on it
            'lt' => $level->isPublic() ? '' : $tokens->create($level),
            'width' => $level->getWidth(),
            'height' => $level->getHeight(),
            'blocks' => base64_encode($level->getBlocks()),
            'start' => $level->getStart(),
            'objects' => $level->getObjects(),
            'music' => $level->getMusic(),
            'sp' => $tileset->getStyle(),
            'solidity' => $tileset->getSolidity(),
            'behaviour' => $tileset->getBehaviour(),
            'masks' => (object) $tileset->getMasks(),
            'tiles' => base64_encode($tileset->getImage()),
            // its own sprites: {sheet: PNG in base64} (LevelManager::loadCustom)
            'sprites' => (object) ($level->getSpriteSet()?->getSheets() ?? []),
            // its own music and sound effects, as the sound editor rendered them: WAV in base64
            'music_wav' => $this->wav($level->getMusicSound()),
            'sfx_wav' => (object) array_filter(array_map(fn(int $id) => $this->wav($this->sounds->find($id)), $level->getSfx())),
        ]);
    }

    /**
     * The game's menu NIVEAUX DU SITE: a page of levels to play ({st: the token of the account, or none, page}, as
     * text: no CORS preflight). The player's own levels first (with the key of the private ones), then the public ones.
     */
    #[Route('/api/niveaux', name: 'api_levels', methods: ['POST'])]
    public function gameLevels(Request $request, LevelToken $tokens, StatsToken $stats, UserRepository $users): JsonResponse
    {
        $body = json_decode(substr($request->getContent(), 0, 1024), true);
        $body = is_array($body) ? $body : [];
        $id = is_string($body['st'] ?? null) ? $stats->userId($body['st']) : null;
        $user = $id !== null ? $users->find($id) : null;
        $page = max(1, min(1000, (int) ($body['page'] ?? 1)));
        [$list, $count] = $this->levels->gamePage($user, $page, self::GAME_PAGE);
        $response = new JsonResponse([
            'page' => $page,
            'pages' => max(1, (int) ceil($count / self::GAME_PAGE)),
            'account' => $user?->getUsername(),
            'levels' => array_map(fn(Level $level) => [
                'id' => $level->getId(),
                'name' => $level->getName(),
                'author' => $level->getUser()->getUsername(),
                'mine' => $user !== null && $level->isOwnedBy($user),
                'public' => $level->isPublic(),
                'lt' => $level->isPublic() ? '' : $tokens->create($level),
            ], $list),
        ]);
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }

    #[Route('/niveau/{id}', name: 'level_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveLevel(Level $level, Request $request): JsonResponse
    {
        if (!$this->creations->canEdit($level, $this->user()) || !$this->apiToken($request)) return $this->json(['error' => 'forbidden'], 403);
        $body = json_decode($request->getContent(), true);
        if ($conflict = $this->creations->conflict($level, $body)) return $this->json($conflict, 409);
        $name = is_array($body) ? EditorData::name($body['name'] ?? null) : null;
        $parsed = is_array($body) ? EditorData::level($body) : null;
        $tileset = is_array($body) && is_int($body['tileset'] ?? null) ? $this->tilesets->find($body['tileset']) : null;
        if ($name === null || $parsed === null || !$tileset instanceof Tileset) return $this->json(['error' => 'invalid'], 400);
        // a set it uses already stays usable, even if it is not public any more
        if (!$tileset->canUse($this->user()) && $tileset !== $level->getTileset()) return $this->json(['error' => 'tileset'], 400);
        $sprites = is_array($body) && is_int($body['sprites'] ?? null) ? $this->spriteSets->find($body['sprites']) : null;
        if ($sprites !== null && !$sprites->canUse($this->user()) && $sprites !== $level->getSpriteSet()) return $this->json(['error' => 'sprites'], 400);
        $level->setName($name);
        $level->setPublic(!empty($body['public']));
        if (is_int($body['music'] ?? null) && isset(EditorData::MUSICS[$body['music']])) $level->setMusic($body['music']);
        $level->update($tileset, ...$parsed);
        $level->setSpriteSet($sprites);
        // its music: a song of the game (music) or one of the sound editor (musicSound); sounds of the game changed (sfx)
        $musicSound = is_int($body['musicSound'] ?? null) ? $this->sounds->find($body['musicSound']) : null;
        if ($musicSound !== null && ($musicSound->getKind() !== Sound::MUSIC
            || (!$musicSound->canSee($this->user()) && $musicSound !== $level->getMusicSound()))) return $this->json(['error' => 'music'], 400);
        $level->setMusicSound($musicSound);
        $sfx = [];
        foreach (is_array($body['sfx'] ?? null) ? $body['sfx'] : [] as $index => $id) {
            $sound = is_int($id) && isset(SoundData::GAME_SFX[(int) $index]) ? $this->sounds->find($id) : null;
            if ($sound === null || $sound->getKind() !== Sound::SFX
                || (!$sound->canSee($this->user()) && !in_array($id, $level->getSfx(), true))) return $this->json(['error' => 'sfx'], 400);
            $sfx[(int) $index] = $id;
        }
        $level->setSfx($sfx);
        if (isset($body['thumb'])) $level->setThumb(EditorData::thumb($body['thumb']));
        $this->em->flush();
        $this->creations->saved($level, $this->user(), $body);

        return $this->json(['ok' => true, 'updated' => $level->getUpdatedAt()->format(DATE_ATOM), 'version' => $this->creations->version($level)]);
    }

    #[Route('/niveau/{id}/copier', name: 'level_copy', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function copyLevel(Level $level, Request $request): Response
    {
        $user = $this->user();
        if (!$this->creations->canSee($level, $user) || !$this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('editor');
        }
        if (count($this->levels->ofUser($user)) >= self::MAX_EACH) return $this->back('Tu as déjà %n% niveaux.', self::MAX_EACH);
        $copy = new Level($user, $level->getTileset(), mb_substr($level->getName(), 0, 33) . ' (copie)', $level->getWidth(),
            $level->getHeight(), $level->getBlocks(), $level->getStart(), $level->getObjects());
        $copy->setThumb($level->getThumb());
        $copy->setMusic($level->getMusic());
        if ($level->getSpriteSet()?->canUse($user)) $copy->setSpriteSet($level->getSpriteSet());
        if ($level->getMusicSound()?->canSee($user)) $copy->setMusicSound($level->getMusicSound());
        $copy->setSfx(array_filter($level->getSfx(), fn(int $id) => $this->sounds->find($id)?->canSee($user) ?? false));
        $copy->setForkedFrom($level);
        $this->em->persist($copy);
        $this->em->flush();
        $this->creations->created($copy, $user, $level);

        return $this->redirectToRoute('level_edit', ['id' => $copy->getId()]);
    }

    #[Route('/niveau/{id}/supprimer', name: 'level_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteLevel(Level $level, Request $request): Response
    {
        if ($level->isOwnedBy($this->user()) && $this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) {
            $this->creations->trash($level, $this->user());

            return $this->toTrash();
        }

        return $this->redirectToRoute('editor');
    }

    // ---- sets of blocks

    #[Route('/tiles/nouveau', name: 'tileset_new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function newTileset(Request $request): Response
    {
        $user = $this->user();
        if (!$this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) return $this->redirectToRoute('editor');
        if (count($this->tilesets->ofUser($user)) >= self::MAX_EACH) return $this->back('Tu as déjà %n% jeux de tiles.', self::MAX_EACH);
        $from = $request->request->getInt('from') ? $this->tilesets->find($request->request->getInt('from')) : null;
        if ($from instanceof Tileset && $this->creations->canSee($from, $user)) {
            $tileset = new Tileset($user, mb_substr($from->getName(), 0, 33) . ' (copie)', $from->getImage(), $from->getSolidity(),
                $from->getBehaviour(), $from->getStyle(), $from->getMasks());
            $tileset->setForkedFrom($from);
        } else {
            $from = null;
            // one empty block: the editor adds the others
            $tileset = new Tileset($user, EditorData::name($request->request->get('name')) ?? 'TILES', self::emptySheet(1), [0]);
        }
        $this->em->persist($tileset);
        $this->em->flush();
        $this->creations->created($tileset, $user, $from);

        return $this->redirectToRoute('tileset_edit', ['id' => $tileset->getId()]);
    }

    #[Route('/tiles/{id}', name: 'tileset', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function tileset(Tileset $tileset): Response
    {
        if (!$this->canSeeTileset($tileset)) throw $this->createNotFoundException();

        return $this->render('editor/tileset.html.twig', ['tileset' => $tileset, 'edit' => false, 'used' => $this->tilesets->usedBy($tileset)]);
    }

    #[Route('/tiles/{id}/editer', name: 'tileset_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function editTileset(Tileset $tileset, Request $request): Response
    {
        if (!$this->creations->canEdit($tileset, $this->user())) return $this->redirectToRoute('tileset', ['id' => $tileset->getId()]);

        return $this->render('editor/tileset.html.twig', [
            'tileset' => $tileset,
            'edit' => true,
            'restored' => $this->creations->revision($tileset, $request->query->getInt('version'))?->getId(),
            'used' => $this->tilesets->usedBy($tileset),
            'usable' => array_filter($this->tilesets->usable($this->user()), fn(Tileset $t) => $t !== $tileset),
            'behaviours' => EditorData::BEHAVIOURS,
        ]);
    }

    #[Route('/tiles/{id}.png', name: 'tileset_png', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function tilesetPng(Tileset $tileset): Response
    {
        if (!$this->canSeeTileset($tileset)) throw $this->createNotFoundException();
        $response = new Response($tileset->getImage(), 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
        $response->setLastModified(\DateTime::createFromImmutable($tileset->getUpdatedAt()));
        $tileset->isPublic() ? $response->setPublic() : $response->setPrivate();

        return $response;
    }

    #[Route('/tiles/{id}.json', name: 'tileset_json', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function tilesetJson(Tileset $tileset, Request $request): JsonResponse
    {
        if (!$this->canSeeTileset($tileset)) throw $this->createNotFoundException();
        $old = $this->creations->revision($tileset, $request->query->getInt('version'))?->getSnapshot();
        if ($old !== null) {
            return $this->json(['id' => $tileset->getId(), 'zone' => null, 'masks' => (object) $old['masks'],
                'image' => 'data:image/png;base64,' . $old['image']] + $old);
        }

        return $this->json($this->tilesetData($tileset));
    }

    #[Route('/tiles/{id}', name: 'tileset_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveTileset(Tileset $tileset, Request $request): JsonResponse
    {
        if (!$this->creations->canEdit($tileset, $this->user()) || !$this->apiToken($request)) return $this->json(['error' => 'forbidden'], 403);
        if (strlen($request->getContent()) > Tileset::MAX_PNG * 2) return $this->json(['error' => 'too big'], 413);
        $body = json_decode($request->getContent(), true);
        if ($conflict = $this->creations->conflict($tileset, $body)) return $this->json($conflict, 409);
        $name = is_array($body) ? EditorData::name($body['name'] ?? null) : null;
        $blocks = is_array($body) ? EditorData::blocks($body['image'] ?? null, $body['solidity'] ?? null, $body['behaviour'] ?? [], $body['masks'] ?? []) : null;
        if ($name === null || $blocks === null) return $this->json(['error' => 'invalid'], 400);
        $tileset->setName($name);
        $tileset->setPublic(!empty($body['public']));
        $tileset->setBlocks(...$blocks);
        $this->em->flush();
        $this->creations->saved($tileset, $this->user(), $body);

        return $this->json(['ok' => true, 'updated' => $tileset->getUpdatedAt()->format(DATE_ATOM), 'version' => $this->creations->version($tileset)]);
    }

    #[Route('/tiles/{id}/supprimer', name: 'tileset_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteTileset(Tileset $tileset, Request $request): Response
    {
        if (!$tileset->isOwnedBy($this->user()) || !$this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('editor');
        }
        // in the trash: the levels made with it (even other players') keep it
        $this->creations->trash($tileset, $this->user());

        return $this->toTrash();
    }

    // ----

    private function user(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    /** @return array<int, array{0: string, 1: string}> type => name in the page's language, family */
    private function objectNames(): array
    {
        return array_map(fn(array $o) => [$this->translator->trans($o[0]), $o[1]], EditorData::OBJECTS);
    }

    private function canSeeTileset(Tileset $tileset): bool
    {
        return $this->creations->canSee($tileset, $this->user());
    }

    /** @return list<array{kind: string, creation: object}> the creations $user changes with their owners */
    private function shared(User $user): array
    {
        $out = [];
        foreach ($this->em->getRepository(\App\Entity\Member::class)->findBy(['user' => $user], ['id' => 'DESC']) as $member) {
            $creation = $this->creations->find($member->getKind(), $member->getCreationId());
            if ($creation !== null && !$creation->isTrashed()) $out[] = ['kind' => $member->getKind(), 'creation' => $creation];
        }

        return $out;
    }

    private function apiToken(Request $request): bool
    {
        return $this->isCsrfTokenValid('editor', (string) $request->headers->get('X-CSRF-Token'));
    }

    private function toTrash(): Response
    {
        $this->addFlash('trash', 'Mis à la corbeille.');

        return $this->redirectToRoute('trash');
    }

    private function back(string $message, ?int $n = null): Response
    {
        $this->addFlash('editor', ['m' => $message, 'n' => $n]);

        return $this->redirectToRoute('editor');
    }

    private function levelData(Level $level): array
    {
        return [
            'id' => $level->getId(),
            'name' => $level->getName(),
            'public' => $level->isPublic(),
            'width' => $level->getWidth(),
            'height' => $level->getHeight(),
            'blocks' => base64_encode($level->getBlocks()),
            'start' => $level->getStart(),
            'objects' => $level->getObjects(),
            'music' => $level->getMusic(),
            'sprites' => $level->getSpriteSet()?->getId(),
            'musicSound' => $level->getMusicSound()?->getId(),
            'sfx' => (object) $level->getSfx(),
            'tileset' => $this->tilesetData($level->getTileset()),
        ];
    }

    private function tilesetData(Tileset $tileset): array
    {
        return [
            'id' => $tileset->getId(),
            'name' => $tileset->getName(),
            'public' => $tileset->isPublic(),
            'zone' => $tileset->getZone(),
            'solidity' => $tileset->getSolidity(),
            'behaviour' => $tileset->getBehaviour(),
            'masks' => (object) $tileset->getMasks(),
            'image' => $this->generateUrl('tileset_png', ['id' => $tileset->getId(), 'v' => $tileset->getUpdatedAt()->getTimestamp()]),
        ];
    }

    /** A sound of the editor as it rendered it (WAV in base64), or null. */
    private function wav(?Sound $sound): ?string
    {
        $wav = $sound ? $this->em->find(SoundAudio::class, $sound)?->getWav() : null;

        return $wav === null ? null : base64_encode($wav);
    }

    /** A transparent sheet of $count blocks (a PNG made by hand: there is no GD in the image of the site). */
    private static function emptySheet(int $count): string
    {
        $width = Tileset::COLUMNS * Tileset::SIZE;
        $height = intdiv($count + Tileset::COLUMNS - 1, Tileset::COLUMNS) * Tileset::SIZE;
        $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $raw = str_repeat("\0" . str_repeat("\0\0\0\0", $width), $height);

        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($raw, 9)) . $chunk('IEND', '');
    }
}
