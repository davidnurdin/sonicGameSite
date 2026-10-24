<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The visitors since the start of the count (the footer of the pages): one per address, counted when a page's
 * JavaScript says so (public/js/visit.js, POST /api/visit), not for robots (their user agent, a browser driven by
 * a program). The addresses are kept as keyed hashes only.
 */
class VisitorCounter
{
    /** The day the count started. */
    public const SINCE = '2026-10-24';
    /** User agents of robots that do run JavaScript (crawlers, previews, headless browsers). */
    private const ROBOTS = '/bot|crawl|spider|slurp|headless|phantom|puppeteer|playwright|selenium|lighthouse|preview|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch/i';

    public function __construct(
        private Connection $db,
        private CacheInterface $cache,
        #[Autowire('%kernel.secret%')] private string $secret,
    ) {
    }

    public static function isRobot(?string $userAgent): bool
    {
        return $userAgent === null || trim($userAgent) === '' || preg_match(self::ROBOTS, $userAgent) === 1;
    }

    /** A visit from $address: counted if it is the first one from there. Returns whether it was. */
    public function visit(string $address): bool
    {
        $hash = hash_hmac('sha256', $address, $this->secret);
        $added = $this->db->executeStatement('INSERT IGNORE INTO visitor (address_hash, created_at) VALUES (?, ?)',
            [$hash, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]) > 0;
        if ($added) $this->cache->delete('visitor_count');
        return $added;
    }

    /** Visitors since SINCE (kept a minute). */
    public function count(): int
    {
        return $this->cache->get('visitor_count', function (ItemInterface $item): int {
            $item->expiresAfter(60);
            return (int)$this->db->fetchOne('SELECT COUNT(*) FROM visitor');
        });
    }
}
