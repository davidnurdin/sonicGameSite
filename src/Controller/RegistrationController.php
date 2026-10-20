<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegistrationController extends AbstractController
{
    /** Quick sign-up: a name and a password, then signed in straight away. */
    #[Route('/inscription', name: 'register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        Security $security,
        #[Autowire(service: 'limiter.registration')] RateLimiterFactoryInterface $registrationLimiter,
        TranslatorInterface $translator,
    ): Response {
        if ($this->getUser()) return $this->redirectToRoute('home');

        $user = new User();
        $form = $this->createForm(RegistrationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // a bot (the hidden field is filled): looks done, nothing saved
            if ($form->get('website')->getData()) return $this->redirectToRoute('home');
            if (!$registrationLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError($translator->trans('Trop d\'inscriptions depuis ta connexion, réessaie dans une heure.')));
            } elseif ($form->isValid()) {
                $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
                $em->persist($user);
                $em->flush();
                $this->addFlash('success', $translator->trans('Bienvenue %name% ! Ton nom sera utilisé dans les parties en ligne.', ['%name%' => $user->getUsername()]));

                return $security->login($user, 'form_login', 'main') ?? $this->redirectToRoute('home');
            }
        }

        return $this->render('registration/register.html.twig', ['form' => $form]);
    }
}
