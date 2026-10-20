<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        // kept for a wrong password (the firewall comes back here without ?next=)
        $next = self::next($request) ?? $request->getSession()->get('login_next');
        $request->getSession()->set('login_next', $next);
        if ($this->getUser()) return $next ? $this->redirect($next) : $this->redirectToRoute('home');

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'next' => $next,
        ]);
    }

    /** ?next=: a page of this site to go back to once signed in (an invitation to a room...), or null. */
    public static function next(Request $request): ?string
    {
        $next = $request->query->get('next', $request->request->get('_target_path'));

        return is_string($next) && preg_match('#^/(?!/)[A-Za-z0-9/_.-]{0,100}$#', $next) ? $next : null;
    }

    #[Route('/deconnexion', name: 'logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key of the firewall.');
    }
}
