<?php

namespace App\Controller;

use App\Entity\Level;
use App\Entity\Tileset;
use App\Entity\User;
use App\Repository\LevelRepository;
use App\Repository\TilesetRepository;
use App\Service\EditorData;
use App\Service\LevelToken;
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
 * its owner changes it. Not played in the game yet.
 */
class EditorController extends AbstractController
{
    private const PER_PAGE = 24;
    /** Levels and sets an account may have. */
    private const MAX_EACH = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private LevelRepository $levels,
        private TilesetRepository $tilesets,
        private TranslatorInterface $translator,
        #[Autowire(env: 'GAME_URL')] private string $gameUrl,
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
            'publicLevels' => $public,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'zones' => $this->tilesets->zones(),
            'publicTilesets' => $this->tilesets->publicOnes(),
            'usable' => $this->tilesets->usable($user),
            'templates' => $data->templates(),
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

        return $this->redirectToRoute('level_edit', ['id' => $level->getId()]);
    }

    #[Route('/niveau/{id}', name: 'level', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function level(Level $level): Response
    {
        if (!$level->canSee($this->user())) throw $this->createNotFoundException();

        return $this->render('editor/level.html.twig', ['level' => $level, 'edit' => false, 'objects' => $this->objectNames()]);
    }

    #[Route('/niveau/{id}/editer', name: 'level_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function editLevel(Level $level): Response
    {
        if (!$level->isOwnedBy($this->user())) return $this->redirectToRoute('level', ['id' => $level->getId()]);

        return $this->render('editor/level.html.twig', [
            'level' => $level,
            'edit' => true,
            'usable' => $this->tilesets->usable($this->user()),
            'objects' => $this->objectNames(),
            'musics' => EditorData::MUSICS,
        ]);
    }

    #[Route('/niveau/{id}.json', name: 'level_json', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function levelJson(Level $level): JsonResponse
    {
        if (!$level->canSee($this->user())) throw $this->createNotFoundException();

        return $this->json($this->levelData($level));
    }

    #[Route('/niveau/{id}.png', name: 'level_png', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function levelPng(Level $level): Response
    {
        if (!$level->canSee($this->user())) throw $this->createNotFoundException();
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
        if (!$level->canSee($user)) throw $this->createNotFoundException();
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
        if (!$level->isPublic() && !$tokens->isValid($level, (string) $request->query->get('lt'))) {
            return $response->setData(['error' => 'not found'])->setStatusCode(404);
        }
        $tileset = $level->getTileset();

        return $response->setData([
            'id' => $level->getId(),
            'name' => $level->getName(),
            'width' => $level->getWidth(),
            'height' => $level->getHeight(),
            'blocks' => base64_encode($level->getBlocks()),
            'start' => $level->getStart(),
            'objects' => $level->getObjects(),
            'music' => $level->getMusic(),
            'sp' => $tileset->getStyle(),
            'solidity' => $tileset->getSolidity(),
            'behaviour' => $tileset->getBehaviour(),
            'tiles' => base64_encode($tileset->getImage()),
        ]);
    }

    #[Route('/niveau/{id}', name: 'level_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveLevel(Level $level, Request $request): JsonResponse
    {
        if (!$level->isOwnedBy($this->user()) || !$this->apiToken($request)) return $this->json(['error' => 'forbidden'], 403);
        $body = json_decode($request->getContent(), true);
        $name = is_array($body) ? EditorData::name($body['name'] ?? null) : null;
        $parsed = is_array($body) ? EditorData::level($body) : null;
        $tileset = is_array($body) && is_int($body['tileset'] ?? null) ? $this->tilesets->find($body['tileset']) : null;
        if ($name === null || $parsed === null || !$tileset instanceof Tileset) return $this->json(['error' => 'invalid'], 400);
        // a set it uses already stays usable, even if it is not public any more
        if (!$tileset->canUse($this->user()) && $tileset !== $level->getTileset()) return $this->json(['error' => 'tileset'], 400);
        $level->setName($name);
        $level->setPublic(!empty($body['public']));
        if (is_int($body['music'] ?? null) && isset(EditorData::MUSICS[$body['music']])) $level->setMusic($body['music']);
        $level->update($tileset, ...$parsed);
        if (isset($body['thumb'])) $level->setThumb(EditorData::thumb($body['thumb']));
        $this->em->flush();

        return $this->json(['ok' => true, 'updated' => $level->getUpdatedAt()->format(DATE_ATOM)]);
    }

    #[Route('/niveau/{id}/copier', name: 'level_copy', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function copyLevel(Level $level, Request $request): Response
    {
        $user = $this->user();
        if (!$level->canSee($user) || !$this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('editor');
        }
        if (count($this->levels->ofUser($user)) >= self::MAX_EACH) return $this->back('Tu as déjà %n% niveaux.', self::MAX_EACH);
        $copy = new Level($user, $level->getTileset(), mb_substr($level->getName(), 0, 33) . ' (copie)', $level->getWidth(),
            $level->getHeight(), $level->getBlocks(), $level->getStart(), $level->getObjects());
        $copy->setThumb($level->getThumb());
        $copy->setMusic($level->getMusic());
        $this->em->persist($copy);
        $this->em->flush();

        return $this->redirectToRoute('level_edit', ['id' => $copy->getId()]);
    }

    #[Route('/niveau/{id}/supprimer', name: 'level_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteLevel(Level $level, Request $request): Response
    {
        if ($level->isOwnedBy($this->user()) && $this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) {
            $this->em->remove($level);
            $this->em->flush();
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
        if ($from instanceof Tileset && $from->canUse($user)) {
            $tileset = new Tileset($user, mb_substr($from->getName(), 0, 33) . ' (copie)', $from->getImage(), $from->getSolidity(),
                $from->getBehaviour(), $from->getStyle());
        } else {
            // one empty block: the editor adds the others
            $tileset = new Tileset($user, EditorData::name($request->request->get('name')) ?? 'TILES', self::emptySheet(1), [0]);
        }
        $this->em->persist($tileset);
        $this->em->flush();

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
    public function editTileset(Tileset $tileset): Response
    {
        if (!$tileset->isOwnedBy($this->user())) return $this->redirectToRoute('tileset', ['id' => $tileset->getId()]);

        return $this->render('editor/tileset.html.twig', [
            'tileset' => $tileset,
            'edit' => true,
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
    public function tilesetJson(Tileset $tileset): JsonResponse
    {
        if (!$this->canSeeTileset($tileset)) throw $this->createNotFoundException();

        return $this->json($this->tilesetData($tileset));
    }

    #[Route('/tiles/{id}', name: 'tileset_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveTileset(Tileset $tileset, Request $request): JsonResponse
    {
        if (!$tileset->isOwnedBy($this->user()) || !$this->apiToken($request)) return $this->json(['error' => 'forbidden'], 403);
        if (strlen($request->getContent()) > Tileset::MAX_PNG * 2) return $this->json(['error' => 'too big'], 413);
        $body = json_decode($request->getContent(), true);
        $name = is_array($body) ? EditorData::name($body['name'] ?? null) : null;
        $blocks = is_array($body) ? EditorData::blocks($body['image'] ?? null, $body['solidity'] ?? null, $body['behaviour'] ?? []) : null;
        if ($name === null || $blocks === null) return $this->json(['error' => 'invalid'], 400);
        $tileset->setName($name);
        $tileset->setPublic(!empty($body['public']));
        $tileset->setBlocks(...$blocks);
        $this->em->flush();

        return $this->json(['ok' => true, 'updated' => $tileset->getUpdatedAt()->format(DATE_ATOM)]);
    }

    #[Route('/tiles/{id}/supprimer', name: 'tileset_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteTileset(Tileset $tileset, Request $request): Response
    {
        if (!$tileset->isOwnedBy($this->user()) || !$this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('editor');
        }
        // the levels made with it (even other players') need it
        if ($this->tilesets->usedBy($tileset) > 0) return $this->back('Ces tiles servent à des niveaux : elles ne peuvent pas être supprimées.');
        $this->em->remove($tileset);
        $this->em->flush();

        return $this->redirectToRoute('editor');
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
        return $tileset->canUse($this->user()) || $this->tilesets->inPublicLevel($tileset);
    }

    private function apiToken(Request $request): bool
    {
        return $this->isCsrfTokenValid('editor', (string) $request->headers->get('X-CSRF-Token'));
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
            'image' => $this->generateUrl('tileset_png', ['id' => $tileset->getId(), 'v' => $tileset->getUpdatedAt()->getTimestamp()]),
        ];
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
