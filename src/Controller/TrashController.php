<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Creations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A player's trash (Creations::trash): what he deleted, to take back or to delete for good. */
#[IsGranted('ROLE_USER')]
class TrashController extends AbstractController
{
    public function __construct(private Creations $creations)
    {
    }

    #[Route('/corbeille', name: 'trash', methods: ['GET'])]
    public function index(): Response
    {
        $this->creations->purgeOld($this->user());

        return $this->render('creation/trash.html.twig', ['items' => $this->creations->trashOf($this->user()), 'days' => Creations::TRASH_DAYS]);
    }

    #[Route('/corbeille/{kind}/{id}', name: 'trash_action', requirements: ['kind' => 'level|tileset|sprites|sound', 'id' => '\d+'], methods: ['POST'])]
    public function action(string $kind, int $id, Request $request): Response
    {
        $user = $this->user();
        $creation = $this->creations->find($kind, $id);
        if ($creation === null || !$creation->isOwnedBy($user) || !$creation->isTrashed()
            || !$this->isCsrfTokenValid('trash', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('trash');
        }
        if ($request->request->get('do') === 'restore') {
            $this->creations->untrash($creation, $user);
            $this->addFlash('trash', 'Restauré.');

            return $this->redirectToRoute(Creations::ROUTES[$kind][0], ['id' => $id]);
        }
        if ($request->request->get('do') === 'purge') {
            $this->addFlash('trash', $this->creations->purge($creation, $user) ? 'Supprimé pour de bon.'
                : 'Ces tiles servent encore à des niveaux : elles restent dans la corbeille.');
        }

        return $this->redirectToRoute('trash');
    }

    /** Everything in the trash deleted for good (but tiles that levels still use). */
    #[Route('/corbeille/vider', name: 'trash_empty', methods: ['POST'])]
    public function empty(Request $request): Response
    {
        if ($this->isCsrfTokenValid('trash', (string) $request->request->get('_token'))) {
            $left = 0;
            foreach ($this->creations->trashOf($this->user()) as ['creation' => $creation]) $left += (int) !$this->creations->purge($creation, $this->user());
            $this->addFlash('trash', $left > 0 ? 'Corbeille vidée, sauf des tiles qui servent encore à des niveaux.' : 'Corbeille vidée.');
        }

        return $this->redirectToRoute('trash');
    }

    private function user(): User
    {
        $user = $this->getUser();
        assert($user instanceof User);

        return $user;
    }
}
