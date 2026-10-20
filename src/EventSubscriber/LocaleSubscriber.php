<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The language of the site: the visitor's choice (FR / EN in the header, cookie "lang"), else the browser's
 * (Accept-Language): French for French, English for anything else. ?lang= in a shared link wins.
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    public const LOCALES = ['fr', 'en'];
    public const COOKIE = 'lang';

    public static function getSubscribedEvents(): array
    {
        return [
            // before the LocaleListener of Symfony (16), which gives the locale to the translator
            KernelEvents::REQUEST => [['onRequest', 20]],
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // ?lang=: a shared link, in the language of the one who shared it (not kept)
        $chosen = $request->query->get('lang', $request->cookies->get(self::COOKIE));
        $request->setLocale(in_array($chosen, self::LOCALES, true) ? $chosen : ($request->getPreferredLanguage(['en', 'fr']) ?? 'en'));
    }

    public function onResponse(ResponseEvent $event): void
    {
        $event->getResponse()->setVary(['Accept-Language', 'Cookie'], false);
    }
}
