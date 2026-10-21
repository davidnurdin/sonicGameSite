<?php

namespace App\Controller;

use App\Entity\SpriteSet;
use App\Entity\User;
use App\Repository\SpriteSetRepository;
use App\Service\Creations;
use App\Service\EditorData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The sprite editor (public/js/editor-sprites.js): sets of the game's sheets drawn again, that a level may use (the
 * game draws them while it is played). Saved as JSON, with the CSRF token "editor" in the X-CSRF-Token header, like
 * the level and tile editors (EditorController).
 */
class SpriteController extends AbstractController
{
    /** Sets an account may have. */
    private const MAX_EACH = 50;

    public function __construct(private EntityManagerInterface $em, private SpriteSetRepository $sets, private Packages $assets,
        private Creations $creations)
    {
    }

    #[Route('/sprites/nouveau', name: 'sprites_new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $user = $this->user();
        if (!$this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) return $this->redirectToRoute('editor');
        if (count($this->sets->ofUser($user)) >= self::MAX_EACH) {
            $this->addFlash('editor', ['m' => 'Tu as déjà %n% jeux de sprites.', 'n' => self::MAX_EACH]);

            return $this->redirectToRoute('editor');
        }
        $from = $request->request->getInt('from') ? $this->sets->find($request->request->getInt('from')) : null;
        if (!$from instanceof SpriteSet || !$this->canSee($from)) $from = null;
        $set = $from !== null
            ? new SpriteSet($user, mb_substr($from->getName(), 0, 33) . ' (copie)', $from->getSheets())
            : new SpriteSet($user, EditorData::name($request->request->get('name')) ?? 'SPRITES');
        $set->setForkedFrom($from);
        $this->em->persist($set);
        $this->em->flush();
        $this->creations->created($set, $user, $from);

        return $this->redirectToRoute('sprites_edit', ['id' => $set->getId()]);
    }

    #[Route('/sprites/{id}', name: 'sprites', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(SpriteSet $set): Response
    {
        if (!$this->canSee($set)) throw $this->createNotFoundException();

        return $this->render('editor/sprites.html.twig', ['set' => $set, 'edit' => false, 'sheets' => SpriteSet::SHEETS,
            'used' => $this->sets->usedBy($set)]);
    }

    #[Route('/sprites/{id}/editer', name: 'sprites_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function edit(SpriteSet $set, Request $request): Response
    {
        if (!$this->creations->canEdit($set, $this->user())) return $this->redirectToRoute('sprites', ['id' => $set->getId()]);

        return $this->render('editor/sprites.html.twig', ['set' => $set, 'edit' => true, 'sheets' => SpriteSet::SHEETS,
            'used' => $this->sets->usedBy($set), 'restored' => $this->creations->revision($set, $request->query->getInt('version'))?->getId()]);
    }

    #[Route('/sprites/{id}.json', name: 'sprites_json', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function data(SpriteSet $set, Request $request): JsonResponse
    {
        if (!$this->canSee($set)) throw $this->createNotFoundException();
        // a version of its history (to open it in the editor)
        $old = $this->creations->revision($set, $request->query->getInt('version'))?->getSnapshot();
        $sheets = [];
        foreach (SpriteSet::SHEETS as $sheet => [$width, $height]) {
            $png = $old !== null ? ($old['sheets'][$sheet] ?? null) : null;
            $sheets[$sheet] = [
                'width' => $width,
                'height' => $height,
                'own' => $old !== null ? $png !== null : $set->getSheet($sheet) !== null,
                'image' => $png !== null ? 'data:image/png;base64,' . $png
                    : ($old !== null ? $this->assets->getUrl('img/sheets/' . $sheet . '.png') : $this->sheetUrl($set, $sheet)),
                'game' => $this->assets->getUrl('img/sheets/' . $sheet . '.png'),
            ];
        }

        return $this->json(['id' => $set->getId(), 'name' => $old['name'] ?? $set->getName(), 'public' => $old['public'] ?? $set->isPublic(), 'sheets' => $sheets]);
    }

    /** A sheet of the set: its own picture, or the game's. */
    #[Route('/sprites/{id}/{sheet}.png', name: 'sprites_png', requirements: ['id' => '\d+', 'sheet' => '[a-z]+'], methods: ['GET'])]
    public function png(SpriteSet $set, string $sheet): Response
    {
        if (!$this->canSee($set) || !isset(SpriteSet::SHEETS[$sheet])) throw $this->createNotFoundException();
        $png = $set->getSheet($sheet);
        if ($png === null) return $this->redirect($this->assets->getUrl('img/sheets/' . $sheet . '.png'));
        $response = new Response($png, 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
        $response->setLastModified(\DateTime::createFromImmutable($set->getUpdatedAt()));
        $set->isPublic() ? $response->setPublic() : $response->setPrivate();

        return $response;
    }

    /** {name, public, sheets: {sheet: PNG in base64}}: the sheets drawn again (the others are the game's). */
    #[Route('/sprites/{id}', name: 'sprites_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function save(SpriteSet $set, Request $request): JsonResponse
    {
        if (!$this->creations->canEdit($set, $this->user()) || !$this->isCsrfTokenValid('editor', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'forbidden'], 403);
        }
        if (strlen($request->getContent()) > SpriteSet::MAX_PNG * 2 * count(SpriteSet::SHEETS)) return $this->json(['error' => 'too big'], 413);
        $body = json_decode($request->getContent(), true);
        if ($conflict = $this->creations->conflict($set, $body)) return $this->json($conflict, 409);
        $name = is_array($body) ? EditorData::name($body['name'] ?? null) : null;
        $sheets = is_array($body) ? self::sheets($body['sheets'] ?? null) : null;
        if ($name === null || $sheets === null) return $this->json(['error' => 'invalid'], 400);
        $set->setName($name);
        $set->setPublic(!empty($body['public']));
        $set->setSheets($sheets);
        $this->em->flush();
        $this->creations->saved($set, $this->user(), $body);

        return $this->json(['ok' => true, 'updated' => $set->getUpdatedAt()->format(DATE_ATOM), 'version' => $this->creations->version($set)]);
    }

    #[Route('/sprites/{id}/supprimer', name: 'sprites_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(SpriteSet $set, Request $request): Response
    {
        // in the trash: the levels using it keep it (deleted for good: they go back to the game's sprites)
        if ($set->isOwnedBy($this->user()) && $this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) {
            $this->creations->trash($set, $this->user());
            $this->addFlash('trash', 'Mis à la corbeille.');

            return $this->redirectToRoute('trash');
        }

        return $this->redirectToRoute('editor');
    }

    /** @return array<string, string>|null sheet => PNG in base64, each a PNG the size of the game's sheet */
    private static function sheets(mixed $sheets): ?array
    {
        if (!is_array($sheets)) return null;
        $valid = [];
        foreach ($sheets as $sheet => $base64) {
            if (!isset(SpriteSet::SHEETS[$sheet]) || !is_string($base64)) return null;
            $png = base64_decode($base64, true);
            [$width, $height] = SpriteSet::SHEETS[$sheet];
            if ($png === false || strlen($png) > SpriteSet::MAX_PNG || !SpriteSet::isPng($png, $width, $height)) return null;
            $valid[$sheet] = base64_encode($png);
        }

        return $valid;
    }

    private function sheetUrl(SpriteSet $set, string $sheet): string
    {
        return $set->getSheet($sheet) === null ? $this->assets->getUrl('img/sheets/' . $sheet . '.png')
            : $this->generateUrl('sprites_png', ['id' => $set->getId(), 'sheet' => $sheet, 'v' => $set->getUpdatedAt()->getTimestamp()]);
    }

    private function canSee(SpriteSet $set): bool
    {
        return $this->creations->canSee($set, $this->user());
    }

    private function user(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
