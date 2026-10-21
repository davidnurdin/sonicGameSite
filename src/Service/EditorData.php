<?php

namespace App\Service;

use App\Entity\Level;
use App\Entity\Tileset;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the editors send, checked (the browser is not trusted), and the data of the game they start from
 * (data/game/, tools/game-data.py): its zones and act 1 of each.
 */
final class EditorData
{
    /**
     * The objects of the game the level editor places (type => name, family), see sonicGamePhp's
     * level<N>.objects.php. The bosses and the objects of the scripted parts are not offered.
     */
    public const OBJECTS = [
        1 => ['Super anneau', 'monitor'], 2 => ['Baskets', 'monitor'], 3 => ['Vie', 'monitor'], 4 => ['Bouclier', 'monitor'],
        5 => ['Invincibilité', 'monitor'], 81 => ['Flèche', 'monitor'], 82 => ['Continue', 'monitor'],
        6 => ['Émeraude', 'item'], 7 => ['Panneau d\'arrivée', 'item'], 80 => ['Fleur', 'item'],
        8 => ['Crabmeat', 'enemy'], 14 => ['Buzz Bomber', 'enemy'], 16 => ['Moto Bug', 'enemy'], 17 => ['Newtron', 'enemy'],
        27 => ['Ball Hog', 'enemy'], 31 => ['Caterkiller', 'enemy'], 38 => ['Chopper', 'enemy'], 45 => ['Yadrin', 'enemy'],
        53 => ['Unidus', 'enemy'], 60 => ['Jaws', 'enemy'], 68 => ['Burrobot', 'enemy'], 58 => ['Petit canon', 'enemy'],
        9 => ['Plateforme qui balance', 'platform'], 11 => ['Plateforme en bois', 'platform'], 12 => ['Plateforme qui tombe', 'platform'],
        15 => ['Plateforme qui glisse', 'platform'], 41 => ['Marche flottante', 'platform'], 56 => ['Plateforme volante', 'platform'],
        59 => ['Plateforme haut-bas', 'platform'], 69 => ['Plateforme qui monte', 'platform'],
        22 => ['Lance-flammes', 'hazard'], 26 => ['Sphère électrique', 'hazard'], 61 => ['Boule à pointes', 'hazard'],
        62 => ['Lance', 'hazard'], 63 => ['Lanceur de boules de feu', 'hazard'], 57 => ['Mur à pointes', 'hazard'],
        33 => ['Bumper', 'other'], 76 => ['Flipper', 'other'], 78 => ['Balance', 'other'], 65 => ['Bulles', 'other'],
        29 => ['Interrupteur', 'other'], 30 => ['Porte à interrupteur', 'other'], 19 => ['Porte de téléport', 'other'],
        23 => ['Porte vers la gauche', 'other'], 24 => ['Porte vers la droite', 'other'], 25 => ['Porte', 'other'],
    ];

    /** The songs of the game a level can have (SoundManager::MUSIC_BY_INDEX). */
    public const MUSICS = [0x00 => 'Green Hill', 0x01 => 'Bridge', 0x02 => 'Jungle', 0x03 => 'Labyrinth', 0x04 => 'Scrap Brain',
        0x05 => 'Sky Base', 0x10 => 'Stage spécial', 0x0B => 'Boss', 0x06 => 'Titre', 0x0E => 'Fin'];

    /**
     * What a block does to Sonic (the game's CollisionSystem::B_*). The tile editor offers these; the zones of
     * the game have others too (bridges, teleporters), kept as they are.
     */
    public const BEHAVIOURS = [0 => 'Rien', 1 => 'Pics', 23 => 'Électrique', 2 => 'Tremplin', 9 => 'Ressort vers le haut',
        4 => 'Ressort vers le haut (moitié droite)', 3 => 'Ressort vers la gauche', 5 => 'Ressort vers la droite',
        18 => 'Grand ressort', 6 => 'Tapis vers la gauche', 7 => 'Tapis vers la droite', 12 => 'Courant vers la gauche',
        13 => 'Glissade vers la droite', 15 => 'Glissade vers la gauche', 8 => 'Eau', 17 => 'Bumper (vertical)',
        21 => 'Bumper (horizontal)'];

    /** The zones in the order of the game. */
    private const ZONES = ['green-hill', 'bridge', 'jungle', 'labyrinth', 'scrap-brain', 'sky-base', 'bonus'];

    public function __construct(#[Autowire('%kernel.project_dir%/data/game')] private string $dir)
    {
    }

    /** @return array<string, array{name: string, file: string}> key => zone of the game */
    public function zones(): array
    {
        $zones = [];
        foreach (self::ZONES as $key) {
            $file = $this->dir . "/zone-$key.json";
            if (is_file($file)) $zones[$key] = json_decode((string) file_get_contents($file), true) + ['file' => substr($file, 0, -5) . '.png'];
        }

        return $zones;
    }

    /** @return array<string, array> key => act 1 of a zone (name, zone, width, height, blocks base64, start, objects) */
    public function templates(): array
    {
        $templates = [];
        foreach (array_keys($this->zones()) as $key) {
            $file = $this->dir . "/level-$key.json";
            if (is_file($file)) $templates[$key] = json_decode((string) file_get_contents($file), true);
        }

        return $templates;
    }

    public static function name(mixed $name): ?string
    {
        if (!is_string($name)) return null;
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');

        return $name !== '' && mb_strlen($name) <= 40 ? $name : null;
    }

    /** The width and height of a PNG, or null. */
    public static function pngSize(string $png): ?array
    {
        if (strlen($png) < 33 || !str_starts_with($png, "\x89PNG\r\n\x1a\n") || substr($png, 12, 4) !== 'IHDR') return null;
        ['w' => $w, 'h' => $h] = unpack('Nw/Nh', substr($png, 16, 8));

        return [$w, $h];
    }

    /** The thumbnail of a level (base64 PNG, 480x240 at most), or null. */
    public static function thumb(mixed $image): ?string
    {
        $png = is_string($image) ? base64_decode($image, true) : false;
        if ($png === false || strlen($png) > 300 * 1024) return null;
        $size = self::pngSize($png);

        return $size !== null && $size[0] >= 1 && $size[1] >= 1 && $size[0] <= 480 && $size[1] <= 240 ? $png : null;
    }

    /**
     * The blocks of a set from the tile editor: {image: base64 PNG, solidity: [byte...], behaviour: [byte...]}.
     *
     * @return array{0: string, 1: list<int>, 2: list<int>}|null
     */
    public static function blocks(mixed $image, mixed $solidity, mixed $behaviour = [], mixed $masks = []): ?array
    {
        $masks = self::masks($masks, count((array) $solidity));
        if ($masks === null) return null;
        if (!is_array($behaviour) || !array_is_list($behaviour) || count($behaviour) > count((array) $solidity)) return null;
        foreach ($behaviour as $value) {
            if (!is_int($value) || $value < 0 || $value > 31) return null;
        }
        if (!is_string($image) || !is_array($solidity) || !array_is_list($solidity)) return null;
        $count = count($solidity);
        if ($count < 1 || $count > Tileset::MAX) return null;
        foreach ($solidity as $value) {
            if (!is_int($value) || $value < 0 || $value > 255 || ($value & 0x3F) > 46) return null;
        }
        $png = base64_decode($image, true);
        if ($png === false || strlen($png) > Tileset::MAX_PNG) return null;
        $rows = intdiv($count + Tileset::COLUMNS - 1, Tileset::COLUMNS);
        if (self::pngSize($png) !== [Tileset::COLUMNS * Tileset::SIZE, $rows * Tileset::SIZE]) return null;

        return [$png, $solidity, array_pad($behaviour, $count, 0), $masks];
    }

    /**
     * The collisions drawn in the editor, by block (see Tileset::$masks): {block: {r: 32 rows of 8 hex digits, w: 0-7}}.
     * Null: not valid.
     * @return array<int, array{r: list<string>, w: int}>|null
     */
    public static function masks(mixed $masks, int $count): ?array
    {
        if (!is_array($masks)) return null;
        $out = [];
        foreach ($masks as $block => $mask) {
            if ($mask === null) continue;
            if (!is_int($block) && !ctype_digit((string) $block)) return null;
            $block = (int) $block;
            if ($block >= $count || !is_array($mask) || !is_array($mask['r'] ?? null) || !array_is_list($mask['r'])
                || count($mask['r']) !== Tileset::SIZE || !is_int($mask['w'] ?? null) || $mask['w'] < 0 || $mask['w'] > 7) return null;
            foreach ($mask['r'] as $row) {
                if (!is_string($row) || !preg_match('/^[0-9a-f]{8}$/i', $row)) return null;
            }
            $out[$block] = ['r' => array_map('strtolower', $mask['r']), 'w' => $mask['w']];
        }
        ksort($out);

        return $out;
    }

    /**
     * A level from the level editor: {width, height, blocks: base64, start: [x, y], objects: [[type, x, y]...]}.
     *
     * @return array{0: int, 1: int, 2: string, 3: array, 4: list<array>}|null
     */
    public static function level(array $data): ?array
    {
        $width = $data['width'] ?? null;
        $height = $data['height'] ?? null;
        if (!is_int($width) || !is_int($height) || $width < Level::MIN_WIDTH || $height < Level::MIN_HEIGHT
            || $width > Level::MAX_SIDE || $height > Level::MAX_SIDE || $width * $height > Level::MAX_BLOCKS) return null;
        $blocks = is_string($data['blocks'] ?? null) ? base64_decode($data['blocks'], true) : false;
        if ($blocks === false || strlen($blocks) !== $width * $height) return null;
        $start = $data['start'] ?? null;
        if (!is_array($start) || count($start) !== 2 || !self::inside($start[0] ?? null, $start[1] ?? null, $width, $height)) return null;
        $objects = $data['objects'] ?? [];
        if (!is_array($objects) || count($objects) > Level::MAX_OBJECTS) return null;
        $kept = [];
        foreach ($objects as $object) {
            if (!is_array($object) || count($object) !== 3 || !is_int($object[0] ?? null) || !isset(self::OBJECTS[$object[0]])
                || !self::inside($object[1] ?? null, $object[2] ?? null, $width, $height)) return null;
            $kept[] = [$object[0], $object[1], $object[2]];
        }

        return [$width, $height, $blocks, [$start[0], $start[1]], $kept];
    }

    private static function inside(mixed $x, mixed $y, int $width, int $height): bool
    {
        return is_int($x) && is_int($y) && $x >= 0 && $y >= 0 && $x < $width && $y < $height;
    }
}
