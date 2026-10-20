<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SitemapController extends AbstractController
{
    /** The pages worth finding in a search engine (the rooms come and go). */
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (['home' => 'daily', 'members' => 'daily', 'register' => 'monthly', 'editor' => 'monthly'] as $route => $freq) {
            $xml .= '  <url><loc>' . htmlspecialchars($this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL))
                . "</loc><changefreq>$freq</changefreq></url>\n";
        }
        $response = new Response($xml . "</urlset>\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        $response->setPublic()->setMaxAge(3600);

        return $response;
    }
}
