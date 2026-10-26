<?php

namespace App\Controller;

use App\Entity\Member;
use App\Entity\Revision;
use App\Entity\User;
use App\Repository\LevelRepository;
use App\Repository\MemberRepository;
use App\Repository\PlayerStatsRepository;
use App\Repository\ReplayRepository;
use App\Repository\RevisionRepository;
use App\Repository\SoundRepository;
use App\Repository\SpriteSetRepository;
use App\Repository\TilesetRepository;
use App\Repository\UserRepository;
use App\Service\Creations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the creations of the editors share (Creations): their history, the players who change them with their
 * owner, the live state of an open editor, and the profile of a player (his creations, what he did lately).
 */
class CreationController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(private Creations $creations, private RevisionRepository $revisions, private EntityManagerInterface $em)
    {
    }

    /** Every action on a creation, the latest first; also once it is deleted, for those who worked on it. */
    #[Route('/niveau/{id}/historique', name: 'level_history', requirements: ['id' => '\d+'], defaults: ['kind' => 'level'], methods: ['GET'])]
    #[Route('/tiles/{id}/historique', name: 'tileset_history', requirements: ['id' => '\d+'], defaults: ['kind' => 'tileset'], methods: ['GET'])]
    #[Route('/sprites/{id}/historique', name: 'sprites_history', requirements: ['id' => '\d+'], defaults: ['kind' => 'sprites'], methods: ['GET'])]
    #[Route('/son/{id}/historique', name: 'sound_history', requirements: ['id' => '\d+'], defaults: ['kind' => 'sound'], methods: ['GET'])]
    public function history(string $kind, int $id, Request $request): Response
    {
        $user = $this->user();
        $creation = $this->creations->find($kind, $id);
        $page = max(1, $request->query->getInt('page', 1));
        $list = $this->revisions->of($kind, $id, $page, self::PER_PAGE);
        if ($creation !== null ? !$this->creations->canSee($creation, $user)
            : ($user === null || !array_filter($this->revisions->of($kind, $id, 1, 1000), fn(Revision $r) => $r->getUser() === $user))) {
            throw $this->createNotFoundException();
        }
        $total = $this->revisions->countOf($kind, $id);

        return $this->render('creation/history.html.twig', [
            'kind' => $kind,
            'id' => $id,
            'creation' => $creation,
            'name' => $creation?->getName() ?? ($list[0] ?? null)?->getName() ?? '#' . $id,
            'revisions' => $list,
            'canEdit' => $creation !== null && $this->creations->canEdit($creation, $user),
            'current' => $creation !== null ? $this->creations->version($creation) : 0,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'others' => $this->others($list, $kind),
        ]);
    }

    /** The owner adds a player (by his name) to those who change the creation with him, or takes one away (remove). */
    #[Route('/creation/{kind}/{id}/membres', name: 'creation_members', requirements: ['kind' => 'level|tileset|sprites|sound', 'id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function members(string $kind, int $id, Request $request, UserRepository $users, MemberRepository $members): Response
    {
        $user = $this->user();
        $creation = $this->creations->find($kind, $id);
        if ($creation === null || !$creation->isOwnedBy($user) || !$this->isCsrfTokenValid('editor', (string) $request->request->get('_token'))) {
            throw $this->createNotFoundException();
        }
        $back = $this->redirectToRoute(Creations::ROUTES[$kind][0], ['id' => $id, '_fragment' => 'credits']);
        if ($request->request->has('remove')) {
            $member = $members->find($request->request->getInt('remove'));
            if ($member instanceof Member && $member->getKind() === $kind && $member->getCreationId() === $id) {
                $this->creations->record($creation, $user, 'unmember', null, [['%name% ne participe plus', ['%name%' => $member->getUser()->getUsername()]]]);
                $this->em->remove($member);
                $this->em->flush();
            }

            return $back;
        }
        $other = $users->findOneBy(['username' => strtoupper(trim((string) $request->request->get('name')))]);
        if (!$other instanceof User) {
            $this->addFlash('creation', ['m' => 'Aucun joueur ne s\'appelle « %name% ».', 'name' => (string) $request->request->get('name')]);
        } elseif ($other !== $user && !$this->creations->isMember($creation, $other)) {
            $this->em->persist(new Member($kind, $id, $other));
            $this->creations->record($creation, $user, 'member', null, [['%name% participe', ['%name%' => $other->getUsername()]]]);
            $this->em->flush();
        }

        return $back;
    }

    /**
     * An open editor, every few seconds (editor-live.js): the version of the creation and who saved it, and who
     * else is in its editor. The editor tells when somebody saved since it opened (version).
     */
    #[Route('/api/edition/{kind}/{id}', name: 'creation_live', requirements: ['kind' => 'level|tileset|sprites|sound', 'id' => '\d+'], methods: ['POST'])]
    public function live(string $kind, int $id): JsonResponse
    {
        $user = $this->user();
        $creation = $this->creations->find($kind, $id);
        if ($creation === null || !$this->creations->canEdit($creation, $user)) return $this->json(['error' => 'forbidden'], 403);
        $last = $this->creations->lastChange($creation);
        $response = $this->json([
            'version' => $last?->getId() ?? 0,
            'by' => $last?->getUser()?->getUsername(),
            'mine' => $last !== null && $last->getUser() === $user,
            'at' => $last?->getCreatedAt()->format(DATE_ATOM),
            'editors' => $this->creations->heartbeat($creation, $user),
        ]);
        $response->setPrivate();

        return $response;
    }

    /** A player: his creations (the public ones; all of them for himself), those he works on, what he did lately. */
    #[Route('/joueur/{username}', name: 'profile', requirements: ['username' => '[A-Za-z0-9]{1,15}'], methods: ['GET'])]
    public function profile(
        string $username,
        UserRepository $users,
        LevelRepository $levels,
        TilesetRepository $tilesets,
        SpriteSetRepository $sprites,
        SoundRepository $sounds,
        MemberRepository $members,
        PlayerStatsRepository $stats,
        ReplayRepository $replays,
    ): Response {
        $player = $users->findOneBy(['username' => strtoupper($username)]);
        if (!$player instanceof User) throw $this->createNotFoundException();
        if ($username !== $player->getUsername()) return $this->redirectToRoute('profile', ['username' => $player->getUsername()], 301);
        $viewer = $this->user();
        $visible = fn(array $list) => array_values(array_filter($list, fn(object $c) => $this->creations->canSee($c, $viewer)));
        $shared = [];
        foreach ($members->ofUser($player) as $member) {
            $creation = $this->creations->find($member->getKind(), $member->getCreationId());
            if ($creation !== null && $this->creations->canSee($creation, $viewer)) $shared[] = ['kind' => $member->getKind(), 'creation' => $creation];
        }
        // what he did, on what the viewer may see (a deleted creation: only its line, without a link)
        $activity = [];
        foreach ($this->revisions->byUser($player) as $revision) {
            if ($revision->getAction() === 'forked') continue;   // the same as his 'fork' of the copy
            $creation = $this->creations->find($revision->getKind(), $revision->getCreationId());
            if ($creation !== null ? !$this->creations->canSee($creation, $viewer) : $viewer !== $player) continue;
            $activity[] = ['revision' => $revision, 'creation' => $creation];
        }

        return $this->render('creation/profile.html.twig', [
            'player' => $player,
            'me' => $viewer === $player,
            'time' => $stats->timesOf([$player])[$player->getId()] ?? 0,
            'levels' => $visible($levels->ofUser($player)),
            'tilesets' => $visible($tilesets->ofUser($player)),
            'sprites' => $visible($sprites->ofUser($player)),
            'sounds' => $visible($sounds->ofUser($player)),
            'shared' => $shared,
            'activity' => array_slice($activity, 0, 30),
            'replays' => $replays->page($player, 1, 1)[1],
        ]);
    }

    /** @return array<int, object> the creations the lines speak of (forks), by id, if they are still there */
    private function others(array $revisions, string $kind): array
    {
        $out = [];
        foreach ($revisions as $revision) {
            $id = $revision->getOtherId();
            if ($id !== null && !isset($out[$id])) {
                $other = $this->creations->find($kind, $id);
                if ($other !== null && $this->creations->canSee($other, $this->user())) $out[$id] = $other;
            }
        }

        return $out;
    }

    private function user(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
