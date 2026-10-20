<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Who is online, from the relay server of the game (php-wasm-network, GET /health): its WebSocket clients and
 * rooms. Asked at most every 15 seconds; null when the relay doesn't answer (the page shows nothing then).
 */
class OnlineStats
{
    public function __construct(
        private CacheInterface $cache,
        #[Autowire(env: 'RELAY_HEALTH_URL')] private string $url,
    ) {
    }

    /** @return array{clients: int, rooms: int, public: int}|null */
    public function get(): ?array
    {
        return $this->cache->get('relay_health', function (ItemInterface $item): ?array {
            $item->expiresAfter(15);
            $context = stream_context_create(['http' => ['timeout' => 1.0, 'ignore_errors' => true]]);
            $body = @file_get_contents($this->url, false, $context);
            $data = is_string($body) ? json_decode($body, true) : null;
            if (!is_array($data) || empty($data['ok'])) return null;

            return ['clients' => (int) ($data['clients'] ?? 0), 'rooms' => (int) ($data['rooms'] ?? 0), 'public' => (int) ($data['public'] ?? 0)];
        });
    }
}
