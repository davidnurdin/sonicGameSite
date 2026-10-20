<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Who is online, from the relay server of the game (php-wasm-network, GET /health?rooms): its players, the
 * pages of the site that follow a room, and the public rooms. Asked at most every 3 seconds; null when the
 * relay doesn't answer (the page shows nothing then).
 */
class OnlineStats
{
    /** The rooms of this game (Online::META of the game: its version must be the same to play together). */
    public const GAME = 'sonic';
    public const VERSION = 4;

    public function __construct(
        private CacheInterface $cache,
        #[Autowire(env: 'RELAY_HEALTH_URL')] private string $url,
        private GamePresence $presence,
    ) {
    }

    /** Players in the game right now: the open games (GamePresence), at least the players of the relay's rooms. */
    public function inGame(): int
    {
        return max($this->presence->count(), $this->get()['players'] ?? 0);
    }

    /**
     * @return array{players: int, watchers: int, rooms: int, public: int, list: list<array{room: string, players: int,
     *     watchers: int, max: int, locked: bool, names: list<string>, sites: list<string>, mode: string, level: int, state: string}>}|null
     */
    public function get(): ?array
    {
        return $this->cache->get('relay_health_rooms', function (ItemInterface $item): ?array {
            $item->expiresAfter(3);
            $context = stream_context_create(['http' => ['timeout' => 1.0, 'ignore_errors' => true]]);
            $body = @file_get_contents($this->url . (str_contains($this->url, '?') ? '&' : '?') . 'rooms', false, $context);
            $data = is_string($body) ? json_decode($body, true) : null;
            if (!is_array($data) || empty($data['ok'])) return null;

            $list = [];
            foreach ($data['list'] ?? [] as $room) {
                $meta = $room['meta'] ?? [];
                $info = is_array($room['info'] ?? null) ? $room['info'] : [];
                if (($meta['game'] ?? '') !== self::GAME || ($meta['v'] ?? 0) !== self::VERSION) continue;
                // kept a moment by the relay once empty: not shown
                if ((int) ($room['players'] ?? 0) + (int) ($room['watchers'] ?? 0) === 0) continue;
                $list[] = [
                    'room' => (string) $room['room'],
                    'players' => (int) ($room['players'] ?? 0),
                    'watchers' => (int) ($room['watchers'] ?? 0),
                    'max' => (int) ($room['max'] ?? 6),
                    'locked' => !empty($room['locked']),
                    'names' => array_values(array_map('strval', (array) ($info['names'] ?? []))),
                    'sites' => array_values(array_map('strval', (array) ($info['sites'] ?? []))),
                    'mode' => (string) ($info['mode'] ?? 'race'),
                    'level' => (int) ($info['level'] ?? 1),
                    'state' => ($info['state'] ?? '') === 'match' ? 'match' : 'lobby',
                ];
            }
            usort($list, fn(array $a, array $b) => [$b['players'], $b['watchers']] <=> [$a['players'], $a['watchers']]);

            return [
                // the relay's clients without "players" (an older relay): everybody is a player
                'players' => (int) ($data['players'] ?? $data['clients'] ?? 0),
                'watchers' => (int) ($data['watchers'] ?? 0),
                'rooms' => (int) ($data['rooms'] ?? 0),
                'public' => (int) ($data['public'] ?? 0),
                'list' => $list,
            ];
        });
    }
}
