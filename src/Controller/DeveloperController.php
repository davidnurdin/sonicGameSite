<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The page that presents the public API (API Platform, /api/public) to those who want to use it. */
class DeveloperController extends AbstractController
{
    #[Route('/developpeurs', name: 'developers', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('developers/index.html.twig');
    }
}
