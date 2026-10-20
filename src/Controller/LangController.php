<?php

namespace App\Controller;

use App\EventSubscriber\LocaleSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class LangController extends AbstractController
{
    /** FR / EN of the header: kept a year, back to the same page. */
    #[Route('/langue/{lang}', name: 'lang', requirements: ['lang' => 'fr|en'], methods: ['GET'])]
    public function lang(string $lang, Request $request): RedirectResponse
    {
        // only a page of this site (no open redirect)
        $back = $request->query->get('back', '/');
        if (!is_string($back) || !preg_match('#^/(?!/)[^\\\\]*$#', $back)) $back = '/';
        $response = $this->redirect($back);
        $response->headers->setCookie(Cookie::create(LocaleSubscriber::COOKIE, $lang, new \DateTimeImmutable('+1 year'))
            ->withSecure($request->isSecure())->withSameSite(Cookie::SAMESITE_LAX));

        return $response;
    }
}
