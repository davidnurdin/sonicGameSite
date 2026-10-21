<?php

namespace App\EventSubscriber;

use App\Service\Creations;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The trashes emptied of what has been there more than Creations::TRASH_DAYS days, once a day: after the answer of
 * the first request of the day (the visitor does not wait), besides each start of the site (app:trash) and each
 * trash opened. No cron to keep on the server.
 */
final class TrashPurgeSubscriber implements EventSubscriberInterface
{
    private const EVERY = 86400;

    public function __construct(
        private Creations $creations,
        #[Autowire(service: 'cache.app')] private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $item = $this->cache->getItem('trash_purge');
        if ($item->isHit()) return;
        // taken first: the requests that come meanwhile do not purge too
        $this->cache->save($item->set(time())->expiresAfter(self::EVERY));
        try {
            $deleted = $this->creations->purgeOld();
            if ($deleted > 0) $this->logger->info('Trash: {n} creations deleted for good', ['n' => $deleted]);
        } catch (\Throwable $e) {
            $this->logger->error('Trash purge failed: {m}', ['m' => $e->getMessage()]);
        }
    }
}
