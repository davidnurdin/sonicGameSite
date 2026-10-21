<?php

namespace App\Controller;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Service\Studio\Tool;
use App\Service\Studio\ToolRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** How to let an AI make levels on one's account (the MCP server, /mcp), and the API keys that allow it. */
class AiController extends AbstractController
{
    /** How long a new key lasts, in days (0: until revoked). */
    private const DAYS = [30, 90, 365, 0];

    public function __construct(private ApiKeyRepository $keys, private EntityManagerInterface $em, private ToolRegistry $tools)
    {
    }

    #[Route('/ia', name: 'ai', methods: ['GET'])]
    public function index(): Response
    {
        $groups = [];
        foreach ($this->tools->all() as $tool) $groups[$this->group($tool)][] = $tool;

        return $this->render('ai/index.html.twig', ['groups' => $groups, 'count' => count($this->tools->all())]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/ia/cles', name: 'ai_keys', methods: ['GET'])]
    public function keys(Request $request): Response
    {
        $session = $request->getSession();
        // the new key, shown once: only its hash is kept
        $new = $session->get('ai_new_key');
        $session->remove('ai_new_key');

        return $this->render('ai/keys.html.twig', ['keys' => $this->keys->ofUser($this->user()), 'new' => $new, 'days' => self::DAYS, 'max' => ApiKey::MAX_PER_USER]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/ia/cles', name: 'ai_keys_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ai_keys', (string) $request->request->get('_token'))) return $this->redirectToRoute('ai_keys');
        $user = $this->user();
        if (count($this->keys->ofUser($user)) >= ApiKey::MAX_PER_USER) {
            $this->addFlash('trash', 'Tu as déjà 10 clés : supprimes-en une d\'abord.');

            return $this->redirectToRoute('ai_keys');
        }
        $name = trim(strip_tags((string) $request->request->get('name')));
        if ($name === '') $name = 'IA';
        $days = (int) $request->request->get('days', 90);
        if (!in_array($days, self::DAYS, true)) $days = 90;
        $key = ApiKey::generate();
        $this->em->persist(new ApiKey($user, mb_substr($name, 0, 40), $key, $request->request->get('access') !== 'read',
            $days > 0 ? new \DateTimeImmutable("+$days days") : null));
        $this->em->flush();
        $request->getSession()->set('ai_new_key', $key);

        return $this->redirectToRoute('ai_keys');
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/ia/cles/{id}/supprimer', name: 'ai_keys_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $key = $this->keys->find($id);
        if ($key !== null && $key->getUser() === $this->user() && $this->isCsrfTokenValid('ai_keys', (string) $request->request->get('_token'))) {
            $this->em->remove($key);
            $this->em->flush();
            $this->addFlash('trash', 'Clé supprimée : elle ne marche plus.');
        }

        return $this->redirectToRoute('ai_keys');
    }

    /** The part of the page where a tool is listed. */
    private function group(Tool $tool): string
    {
        return match (true) {
            str_contains($tool->name, 'level') => 'Niveaux',
            str_contains($tool->name, 'tileset') => 'Tiles',
            str_contains($tool->name, 'sprite') => 'Sprites',
            str_contains($tool->name, 'sound') => 'Musiques et sons',
            default => 'Compte',
        };
    }

    private function user(): User
    {
        $user = $this->getUser();
        assert($user instanceof User);

        return $user;
    }
}
