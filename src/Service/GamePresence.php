<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Who has the game open right now, alone or online: the game's page says so every 45 seconds (POST /api/jeu,
 * one id per tab). Kept 2 minutes in the cache; no database.
 */
class GamePresence
{
    private const KEY = 'game_presence';
    private const TTL = 120;
    private const MAX = 5000;

    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    /** $bye: the page is closed. */
    public function beat(string $id, ?string $name, bool $bye = false): void
    {
        $item = $this->cache->getItem(self::KEY);
        $list = $this->fresh($item->isHit() ? (array) $item->get() : []);
        if ($bye) {
            unset($list[$id]);
        } elseif (isset($list[$id]) || count($list) < self::MAX) {
            $list[$id] = [time(), $name];
        }
        $item->set($list)->expiresAfter(self::TTL);
        $this->cache->save($item);
    }

    /** Open games (the tabs). */
    public function count(): int
    {
        $item = $this->cache->getItem(self::KEY);

        return $item->isHit() ? count($this->fresh((array) $item->get())) : 0;
    }

    private function fresh(array $list): array
    {
        $limit = time() - self::TTL;

        return array_filter($list, fn($entry) => is_array($entry) && ($entry[0] ?? 0) >= $limit);
    }
}
