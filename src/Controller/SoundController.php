<?php

namespace App\Controller;

use App\Entity\Sound;
use App\Entity\User;
use App\Repository\SoundRepository;
use App\Service\EditorData;
use App\Service\SoundData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The sound editor: musics (editor-music.js) and sound effects (editor-sfx.js), played in the browser by
 * sound-engine.js. Saved as JSON with the CSRF token "editor" in the X-CSRF-Token header, like the other editors.
 * Not played in the game.
 */
class SoundController extends AbstractController
{
    /** Sounds an account may have. */
    private const MAX_EACH = 100;

    public function __construct(private EntityManagerInterface $em, private SoundRepository $sounds)
    {
    }

    #[Route('/son/nouveau', name: 'sound_new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $user = $this->user();
        if (!$this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) return $this->redirectToRoute('editor');
        if (count($this->sounds->ofUser($user)) >= self::MAX_EACH) {
            $this->addFlash('editor', ['m' => 'Tu as déjà %n% sons.', 'n' => self::MAX_EACH]);

            return $this->redirectToRoute('editor');
        }
        $from = $request->request->getInt('from') ? $this->sounds->find($request->request->getInt('from')) : null;
        if ($from instanceof Sound && $from->canSee($user)) {
            $sound = new Sound($user, mb_substr($from->getName(), 0, 33) . ' (copie)', $from->getKind(), $from->getData());
        } else {
            $music = $request->request->get('kind') === Sound::MUSIC;
            $sound = new Sound($user, EditorData::name($request->request->get('name')) ?? ($music ? 'MUSIQUE' : 'SON'),
                $music ? Sound::MUSIC : Sound::SFX, $music ? SoundData::newMusic() : SoundData::newSfx());
        }
        $this->em->persist($sound);
        $this->em->flush();

        return $this->redirectToRoute('sound_edit', ['id' => $sound->getId()]);
    }

    #[Route('/son/{id}', name: 'sound', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Sound $sound): Response
    {
        if (!$sound->canSee($this->user())) throw $this->createNotFoundException();

        return $this->render('editor/sound.html.twig', $this->page($sound, false));
    }

    #[Route('/son/{id}/editer', name: 'sound_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Sound $sound): Response
    {
        if (!$sound->isOwnedBy($this->user())) return $this->redirectToRoute('sound', ['id' => $sound->getId()]);

        return $this->render('editor/sound.html.twig', $this->page($sound, true));
    }

    /** {name, public, data}: data is checked by SoundData (music or sfx, as the sound is). */
    #[Route('/son/{id}', name: 'sound_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function save(Sound $sound, Request $request): JsonResponse
    {
        if (!$sound->isOwnedBy($this->user()) || !$this->isCsrfTokenValid('editor', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'forbidden'], 403);
        }
        if (strlen($request->getContent()) > 512 * 1024) return $this->json(['error' => 'too big'], 413);
        $body = json_decode($request->getContent(), true);
        $name = is_array($body) ? EditorData::name($body['name'] ?? null) : null;
        $data = is_array($body) ? ($sound->isMusic() ? SoundData::music($body['data'] ?? null) : SoundData::sfx($body['data'] ?? null)) : null;
        if ($name === null || $data === null) return $this->json(['error' => 'invalid'], 400);
        $sound->setName($name);
        $sound->setPublic(!empty($body['public']));
        $sound->setData($data);
        $this->em->flush();

        return $this->json(['ok' => true, 'updated' => $sound->getUpdatedAt()->format(DATE_ATOM)]);
    }

    #[Route('/son/{id}/supprimer', name: 'sound_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Sound $sound, Request $request): Response
    {
        if ($sound->isOwnedBy($this->user()) && $this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) {
            $this->em->remove($sound);
            $this->em->flush();
        }

        return $this->redirectToRoute('editor');
    }

    private function page(Sound $sound, bool $edit): array
    {
        return [
            'sound' => $sound,
            'edit' => $edit,
            'voices' => SoundData::VOICES,
            'drums' => SoundData::DRUMS,
            'waves' => SoundData::WAVES,
        ];
    }

    private function user(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
