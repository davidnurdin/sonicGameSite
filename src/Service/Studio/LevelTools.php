<?php

namespace App\Service\Studio;

use App\Entity\Level;
use App\Entity\Sound;
use App\Entity\SpriteSet;
use App\Entity\Tileset;
use App\Service\EditorData;
use App\Service\SoundData;

/** The levels: read (numbers or a text map), make, change by operations, draw, test with a bot. */
final class LevelTools implements ToolProvider
{
    private const ID = ['type' => 'integer', 'minimum' => 1];
    private const REGION = ['type' => 'object', 'description' => 'a part of the level, in blocks', 'properties' => [
        'x' => ['type' => 'integer'], 'y' => ['type' => 'integer'], 'w' => ['type' => 'integer'], 'h' => ['type' => 'integer']]];
    private const MAX_OPS = 2000;
    private const RINGS = [120, 121, 122, 123];
    /** Objects that stand on the ground (the others fly, swim or hang). */
    private const GROUNDED = [1, 2, 3, 4, 5, 81, 82, 7, 80, 8, 16, 17, 27, 31, 45, 68, 58, 22, 29, 76, 78];

    public function __construct(private Studio $studio, private Solidity $solidity, private LevelRenderer $renderer, private EditorData $editorData)
    {
    }

    public function tools(): array
    {
        $ops = ['type' => 'array', 'maxItems' => self::MAX_OPS, 'description' => 'Operations, in order (block = an index of the level\'s tileset): '
            . '{"op":"fill","x","y","w","h","block"} a rectangle of one block; {"op":"set","x","y","block"}; '
            . '{"op":"rows","x","y","rows":[[block,...],...]} blocks from (x, y), -1 keeps a block; '
            . '{"op":"copy_area","x","y","w","h","to_x","to_y"} a part copied elsewhere (its objects too); '
            . '{"op":"resize","width","height","fill_block"?,"anchor_x":"left"|"right","anchor_y":"top"|"bottom"} (bottom: rows added or removed at the top); '
            . '{"op":"add_object","type","x","y"}; {"op":"add_objects","objects":[[type,x,y],...]}; '
            . '{"op":"remove_objects","x"?,"y"?,"w"?,"h"?,"type"?} (in a rectangle, all if none); {"op":"start","x","y"}',
            'items' => ['type' => 'object', 'properties' => ['op' => ['type' => 'string', 'enum' => ['fill', 'set', 'rows', 'copy_area', 'resize', 'add_object', 'add_objects', 'remove_objects', 'start']]],
                'required' => ['op']]];
        $set = ['type' => 'object', 'description' => 'Settings: name, tileset_id, sprites_id (null: the game\'s), music (a song of the game), '
            . 'music_sound_id (a music of create_sound, null: none), sfx ({game sound index: sound id}, null values remove)',
            'properties' => ['name' => ['type' => 'string', 'maxLength' => 40], 'tileset_id' => self::ID, 'sprites_id' => ['type' => ['integer', 'null']],
                'music' => ['type' => 'integer'], 'music_sound_id' => ['type' => ['integer', 'null']], 'sfx' => ['type' => 'object']]];

        return [
            new Tool('get_level', 'Read a level', 'A level: its settings, objects, start, and its blocks: "map" (default) a text map, one character a block, '
                . 'with a legend (what is solid, slopes, rings, springs, objects), or "numbers" (the indexes of its tileset, rows from the top). A region reads a part.',
                ['properties' => ['id' => self::ID, 'view' => ['type' => 'string', 'enum' => ['map', 'numbers', 'none'], 'default' => 'map'], 'region' => self::REGION],
                    'required' => ['id']], $this->get(...)),
            new Tool('create_level', 'Make a level', 'A new level on this account (private). With a tileset (yours, a public one or a zone of the game) and a size: '
                . 'empty, with a flat ground of 3 rows (ground true); or template: a copy of the act 1 of a zone ('
                . implode(', ', ['green-hill', 'bridge', 'jungle', 'labyrinth', 'scrap-brain', 'sky-base', 'bonus']) . '). Then edit_level.',
                ['properties' => ['name' => ['type' => 'string', 'maxLength' => 40], 'tileset_id' => self::ID, 'width' => ['type' => 'integer', 'default' => 64],
                    'height' => ['type' => 'integer', 'default' => 16], 'ground' => ['type' => 'boolean', 'default' => true],
                    'template' => ['type' => 'string']], 'required' => ['name']], $this->create(...), write: true),
            new Tool('edit_level', 'Change a level', 'Changes a level of this account (or one it was added to): operations on its blocks and objects, then '
                . 'settings. All or nothing: if one operation is wrong, nothing is changed. Saved in its history (taken back on the site if needed).',
                ['properties' => ['id' => self::ID, 'ops' => $ops, 'set' => $set], 'required' => ['id']], $this->edit(...), write: true),
            new Tool('render_level', 'Picture of a level', 'A picture of a level as the game draws it (smaller: px pixels a block; 32 is the game\'s size), '
                . 'with its objects and Sonic at his start. collision: tints what is solid (red: floor, orange: walls, violet: only a ceiling). grid: lines between blocks.',
                ['properties' => ['id' => self::ID, 'px' => ['type' => 'integer', 'enum' => [4, 8, 16, 32], 'default' => 8], 'region' => self::REGION,
                    'collision' => ['type' => 'boolean', 'default' => false], 'grid' => ['type' => 'boolean', 'default' => false]], 'required' => ['id']],
                $this->render(...), heavy: true),
            new Tool('test_level', 'Test a level', 'A fake game: a bot with the physics of the game plays the level (2 tries: fast, careful) and says if it reaches '
                . 'the goal, where it got stuck or died, with its path on a picture; and checks the level (start, objects in walls or in the air, goal, rings). '
                . 'Badniks stand still and platforms do not move in it. Use it after each big change.',
                ['properties' => ['id' => self::ID, 'seconds' => ['type' => 'integer', 'minimum' => 10, 'maximum' => 300, 'default' => 180],
                    'picture' => ['type' => 'boolean', 'default' => true]], 'required' => ['id']], $this->test(...), heavy: true),
        ];
    }

    // ---- reading

    private function get(Args $args, Context $c): Result
    {
        /** @var Level $level */
        $level = $this->studio->find('level', $args->raw('id'), $c->user);
        $view = $args->choice('view', ['map', 'numbers', 'none'], 'map');
        $out = $this->studio->summary($level, $c->user) + [
            'width' => $level->getWidth(), 'height' => $level->getHeight(), 'start' => $level->getStart(),
            'tileset' => ['id' => $level->getTileset()->getId(), 'name' => $level->getTileset()->getName(), 'blocks' => $level->getTileset()->getCount()],
            'sprites_id' => $level->getSpriteSet()?->getId(), 'music' => $level->getMusic(), 'music_sound_id' => $level->getMusicSound()?->getId(),
            'sfx' => (object) $level->getSfx(), 'objects' => $level->getObjects(),
        ];
        [$x0, $y0, $w, $h] = $this->region($args->raw('region'), $level->getWidth(), $level->getHeight());
        if ($view !== 'none' && $w * $h > 12000) throw new ToolError("Too many blocks to read at once ({$w}x{$h}): read a region (12000 blocks at most).");
        if ($view === 'numbers') {
            $rows = [];
            for ($y = $y0; $y < $y0 + $h; $y++) $rows[] = array_values(unpack('C*', substr($level->getBlocks(), $y * $level->getWidth() + $x0, $w)));
            $out['region'] = ['x' => $x0, 'y' => $y0, 'w' => $w, 'h' => $h];
            $out['rows'] = $rows;
        } elseif ($view === 'map') {
            $out['region'] = ['x' => $x0, 'y' => $y0, 'w' => $w, 'h' => $h];
            $out += $this->map($level, $x0, $y0, $w, $h);
        }

        return new Result($out);
    }

    /** The level as text: a character a block (its collision, rings, springs, spikes), the objects over it. */
    private function map(Level $level, int $x0, int $y0, int $w, int $h): array
    {
        $tileset = $level->getTileset();
        $chars = [];
        foreach ($tileset->getSolidity() as $i => $value) {
            $shape = $value & 0x3F;
            $behaviour = $tileset->getBehaviour()[$i] ?? 0;
            $surface = $this->solidity->surface($shape);
            $ways = Solidity::ways($shape);
            $chars[$i] = match (true) {
                in_array($i, self::RINGS, true) => 'o',
                $behaviour === 1 || $behaviour === 23 => '^',
                in_array($behaviour, [3, 4, 5, 9, 18, 19, 20], true) => '*',
                $behaviour === 8 => '~',
                $shape === 0 || array_filter($surface, fn($v) => $v !== null) === [] => '.',
                $ways['walls'] => '#',
                !$ways['floor'] => '"',
                $surface[0] !== null && $surface[31] !== null && $surface[0] - $surface[31] > 4 => '/',
                $surface[0] !== null && $surface[31] !== null && $surface[31] - $surface[0] > 4 => '\\',
                $surface[0] === null && $surface[31] !== null => '/',
                $surface[0] !== null && $surface[31] === null => '\\',
                min(array_filter($surface, fn($v) => $v !== null)) >= 12 => '_',
                default => '=',
            };
        }
        $grid = [];
        for ($y = $y0; $y < $y0 + $h; $y++) {
            $line = '';
            for ($x = $x0; $x < $x0 + $w; $x++) $line .= $chars[ord($level->getBlocks()[$y * $level->getWidth() + $x])] ?? '?';
            $grid[] = $line;
        }
        $put = function (int $x, int $y, string $char) use (&$grid, $x0, $y0, $w, $h) {
            if ($x >= $x0 && $y >= $y0 && $x < $x0 + $w && $y < $y0 + $h) $grid[$y - $y0][$x - $x0] = $char;
        };
        foreach ($level->getObjects() as [$type, $x, $y]) {
            $put($x, $y, match (true) {
                $type === Simulator::GOAL => 'G',
                in_array($type, Simulator::MONITORS, true) => 'M',
                in_array($type, Simulator::ENEMIES, true) => 'E',
                in_array($type, Simulator::HAZARDS, true) => 'H',
                in_array($type, Simulator::PLATFORMS, true) => 'P',
                default => 'I',
            });
        }
        [$sx, $sy] = $level->getStart();
        $put($sx, $sy, '@');

        return [
            'map' => $grid,
            'legend' => ['.' => 'empty', '#' => 'full block (wall)', '_' => 'floor with its surface low in the block (16: the ground)',
                '=' => 'other floor (stood on, passed through from below)', '/' => 'slope up to the right', '\\' => 'slope down to the right',
                '"' => 'only a ceiling', 'o' => 'ring', '^' => 'spikes', '*' => 'spring', '~' => 'water', '@' => 'Sonic\'s start', 'G' => 'goal sign',
                'M' => 'monitor', 'E' => 'badnik', 'H' => 'hazard', 'P' => 'platform', 'I' => 'other object', '?' => 'a block missing from the tileset'],
        ];
    }

    // ---- making and changing

    private function create(Args $args, Context $c): Result
    {
        $this->studio->checkRoom('level', $c->user);
        $name = Studio::name($args);
        $tilesets = $this->studio->em->getRepository(Tileset::class);
        if ($args->has('template')) {
            $template = $this->editorData->templates()[(string) $args->raw('template')] ?? null;
            if ($template === null) throw new ToolError('template: green-hill, bridge, jungle, labyrinth, scrap-brain, sky-base or bonus.');
            $tileset = $tilesets->findOneBy(['zone' => $template['zone']]);
            $level = new Level($c->user, $tileset, $name, $template['width'], $template['height'], base64_decode($template['blocks']),
                $template['start'], array_values(array_filter($template['objects'], fn($o) => isset(EditorData::OBJECTS[$o[0]]))));
            $level->setMusic((int) ($template['music'] ?? 0));
        } else {
            $tileset = $this->studio->find('tileset', $args->raw('tileset_id'), $c->user);
            if (!$tileset->canUse($c->user)) throw new ToolError('This tileset may not be used (private and not this account\'s): fork it.');
            $width = $args->int('width', Level::MIN_WIDTH, Level::MAX_SIDE, 64);
            $height = $args->int('height', Level::MIN_HEIGHT, Level::MAX_SIDE, 16);
            if ($width * $height > Level::MAX_BLOCKS) throw new ToolError('At most ' . Level::MAX_BLOCKS . ' blocks (width x height).');
            $blocks = str_repeat("\0", $width * $height);
            if ($args->bool('ground', true)) {
                $ground = array_search(22, array_map(fn($v) => $v & 0x3F, $tileset->getSolidity()), true);
                $blocks = str_repeat("\0", $width * ($height - 3)) . str_repeat(chr($ground === false ? 0 : $ground), $width * 3);
            }
            $level = new Level($c->user, $tileset, $name, $width, $height, $blocks, [3, max(0, $height - 5)], []);
            $level->setMusic([0, 1, 2, 3, 4, 5, 0x10][$tileset->getStyle()] ?? 0);
        }
        $this->thumb($level);
        $this->studio->em->persist($level);
        $this->studio->em->flush();
        $this->studio->creations->created($level, $c->user);

        return new Result(['created' => $this->studio->summary($level, $c->user) + ['width' => $level->getWidth(), 'height' => $level->getHeight(),
            'start' => $level->getStart()], 'next' => 'get_level (view map) to see it, edit_level to change it.']);
    }

    private function edit(Args $args, Context $c): Result
    {
        /** @var Level $level */
        $level = $this->studio->find('level', $args->raw('id'), $c->user, true);
        $tileset = $level->getTileset();
        $set = $args->object('set', false);
        $s = new Args($set, 'set.');
        if ($s->has('tileset_id')) {
            $tileset = $this->studio->find('tileset', $s->raw('tileset_id'), $c->user);
            if (!$tileset->canUse($c->user) && $tileset !== $level->getTileset()) throw new ToolError('set.tileset_id: this tileset may not be used (fork it).');
        }
        $count = $tileset->getCount();
        $w = $level->getWidth();
        $h = $level->getHeight();
        $blocks = $level->getBlocks();
        $start = $level->getStart();
        $objects = $level->getObjects();
        $block = function (Args $a, string $name = 'block') use (&$count) {
            return $a->int($name, 0, $count - 1);
        };
        $changed = 0;
        foreach ($args->list('ops', self::MAX_OPS, false) as $i => $_) {
            $op = $args->item('ops', $i);
            switch ($op->choice('op', ['fill', 'set', 'rows', 'copy_area', 'resize', 'add_object', 'add_objects', 'remove_objects', 'start'])) {
                case 'fill':
                    [$x, $y, $fw, $fh] = $this->rect($op, $w, $h);
                    $b = chr($block($op));
                    for ($yy = $y; $yy < $y + $fh; $yy++) $blocks = substr_replace($blocks, str_repeat($b, $fw), $yy * $w + $x, $fw);
                    $changed += $fw * $fh;
                    break;
                case 'set':
                    $x = $op->int('x', 0, $w - 1);
                    $y = $op->int('y', 0, $h - 1);
                    $blocks[$y * $w + $x] = chr($block($op));
                    $changed++;
                    break;
                case 'rows':
                    $x = $op->int('x', 0, $w - 1);
                    $y = $op->int('y', 0, $h - 1);
                    $rows = $op->list('rows', $h - $y);
                    foreach ($rows as $r => $row) {
                        if (!is_array($row) || !array_is_list($row) || count($row) > $w - $x) throw new ToolError($op->where() . "rows[$r]: a list of at most " . ($w - $x) . ' blocks.');
                        foreach ($row as $k => $value) {
                            if ($value === -1) continue;
                            if (!is_int($value) || $value < 0 || $value >= $count) throw new ToolError($op->where() . "rows[$r][$k]: a block 0-" . ($count - 1) . ' or -1.');
                            $blocks[($y + $r) * $w + $x + $k] = chr($value);
                            $changed++;
                        }
                    }
                    break;
                case 'copy_area':
                    [$x, $y, $fw, $fh] = $this->rect($op, $w, $h);
                    $tx = $op->int('to_x', 0, $w - 1);
                    $ty = $op->int('to_y', 0, $h - 1);
                    $part = [];
                    for ($yy = 0; $yy < $fh; $yy++) $part[] = substr($blocks, ($y + $yy) * $w + $x, $fw);
                    foreach ($part as $yy => $row) {
                        if ($ty + $yy >= $h) break;
                        $row = substr($row, 0, $w - $tx);
                        $blocks = substr_replace($blocks, $row, ($ty + $yy) * $w + $tx, strlen($row));
                        $changed += strlen($row);
                    }
                    foreach ($objects as [$t, $ox, $oy]) {
                        $nx = $ox - $x + $tx;
                        $ny = $oy - $y + $ty;
                        if ($ox >= $x && $oy >= $y && $ox < $x + $fw && $oy < $y + $fh && $nx < $w && $ny < $h) $objects[] = [$t, $nx, $ny];
                    }
                    break;
                case 'resize':
                    $nw = $op->int('width', Level::MIN_WIDTH, Level::MAX_SIDE);
                    $nh = $op->int('height', Level::MIN_HEIGHT, Level::MAX_SIDE);
                    if ($nw * $nh > Level::MAX_BLOCKS) throw new ToolError($op->where() . 'at most ' . Level::MAX_BLOCKS . ' blocks.');
                    $fill = chr($op->has('fill_block') ? $block($op, 'fill_block') : 0);
                    $dx = $op->choice('anchor_x', ['left', 'right'], 'left') === 'right' ? $nw - $w : 0;
                    $dy = $op->choice('anchor_y', ['top', 'bottom'], 'top') === 'bottom' ? $nh - $h : 0;
                    $new = str_repeat($fill, $nw * $nh);
                    for ($y = 0; $y < $h; $y++) {
                        $ny = $y + $dy;
                        if ($ny < 0 || $ny >= $nh) continue;
                        $from = max(0, -$dx);
                        $length = min($w - $from, $nw - max(0, $dx));
                        if ($length > 0) $new = substr_replace($new, substr($blocks, $y * $w + $from, $length), $ny * $nw + $from + $dx, $length);
                    }
                    $objects = array_values(array_filter(array_map(fn($o) => [$o[0], $o[1] + $dx, $o[2] + $dy], $objects),
                        fn($o) => $o[1] >= 0 && $o[2] >= 0 && $o[1] < $nw && $o[2] < $nh));
                    $start = [max(0, min($nw - 1, $start[0] + $dx)), max(0, min($nh - 1, $start[1] + $dy))];
                    [$w, $h, $blocks] = [$nw, $nh, $new];
                    break;
                case 'add_object':
                    $objects[] = $this->object($op, $w, $h);
                    break;
                case 'add_objects':
                    foreach ($op->list('objects', Level::MAX_OBJECTS) as $k => $o) {
                        if (!is_array($o) || count($o) !== 3) throw new ToolError($op->where() . "objects[$k]: [type, x, y].");
                        $objects[] = $this->object(new Args(['type' => $o[0], 'x' => $o[1], 'y' => $o[2]], $op->where() . "objects[$k]."), $w, $h);
                    }
                    break;
                case 'remove_objects':
                    $x = $op->int('x', 0, $w - 1, 0);
                    $y = $op->int('y', 0, $h - 1, 0);
                    $rw = $op->int('w', 1, $w, $w);
                    $rh = $op->int('h', 1, $h, $h);
                    $type = $op->optionalInt('type', 0, 255);
                    $objects = array_values(array_filter($objects, fn($o) => !($o[1] >= $x && $o[2] >= $y && $o[1] < $x + $rw && $o[2] < $y + $rh
                        && ($type === null || $o[0] === $type))));
                    break;
                case 'start':
                    $start = [$op->int('x', 0, $w - 1), $op->int('y', 0, $h - 1)];
                    break;
            }
            if (count($objects) > Level::MAX_OBJECTS) throw new ToolError('At most ' . Level::MAX_OBJECTS . ' objects.');
        }
        // a new tileset must have every block the level uses
        $max = max(array_values(unpack('C*', $blocks)));
        if ($max >= $count) throw new ToolError("The level uses block $max, its tileset has $count blocks.");

        // the settings
        if ($s->has('name')) $level->setName(Studio::name($s));
        if ($s->has('music')) {
            $music = $s->int('music', 0, 255);
            if (!isset(EditorData::MUSICS[$music])) throw new ToolError('set.music: one of ' . implode(', ', array_keys(EditorData::MUSICS)) . ' (get_reference "level").');
            $level->setMusic($music);
        }
        if (array_key_exists('sprites_id', $set)) {
            $sprites = $set['sprites_id'] === null ? null : $this->studio->find('sprites', $set['sprites_id'], $c->user);
            if ($sprites instanceof SpriteSet && !$sprites->canUse($c->user) && $sprites !== $level->getSpriteSet()) throw new ToolError('set.sprites_id: may not be used (fork it).');
            $level->setSpriteSet($sprites);
        }
        if (array_key_exists('music_sound_id', $set)) {
            $sound = $set['music_sound_id'] === null ? null : $this->studio->find('sound', $set['music_sound_id'], $c->user);
            if ($sound instanceof Sound && !$sound->isMusic()) throw new ToolError('set.music_sound_id: this sound is a sound effect, not a music.');
            $level->setMusicSound($sound);
        }
        if (array_key_exists('sfx', $set)) {
            $sfx = $level->getSfx();
            foreach ($s->object('sfx') as $index => $id) {
                if (!isset(SoundData::GAME_SFX[(int) $index]) || !is_numeric($index)) throw new ToolError("set.sfx: \"$index\" is not a sound of the game (get_reference \"level\").");
                if ($id === null) {
                    unset($sfx[(int) $index]);
                    continue;
                }
                $sound = $this->studio->find('sound', $id, $c->user);
                if ($sound->isMusic()) throw new ToolError("set.sfx[$index]: #$id is a music, not a sound effect.");
                $sfx[(int) $index] = $sound->getId();
            }
            $level->setSfx($sfx);
        }
        $level->update($tileset, $w, $h, $blocks, $start, $objects);
        $this->thumb($level);
        $this->studio->em->flush();
        $this->studio->creations->saved($level, $c->user, null);

        return new Result(['saved' => $this->studio->summary($level, $c->user) + ['width' => $w, 'height' => $h, 'start' => $start,
            'blocks_changed' => $changed, 'objects' => count($objects)], 'next' => 'render_level or get_level to look, test_level to try it.']);
    }

    private function rect(Args $op, int $w, int $h): array
    {
        $x = $op->int('x', 0, $w - 1);
        $y = $op->int('y', 0, $h - 1);

        return [$x, $y, $op->int('w', 1, $w - $x), $op->int('h', 1, $h - $y)];
    }

    private function object(Args $a, int $w, int $h): array
    {
        $type = $a->int('type', 0, 255);
        if (!isset(EditorData::OBJECTS[$type])) throw new ToolError($a->where() . "type: $type is not an object of the editor (get_reference \"objects\").");

        return [$type, $a->int('x', 0, $w - 1), $a->int('y', 0, $h - 1)];
    }

    private function region(mixed $region, int $width, int $height): array
    {
        if ($region === null) return [0, 0, $width, $height];
        if (!is_array($region)) throw new ToolError('region: {x, y, w, h} in blocks.');
        $r = new Args($region, 'region.');
        $x = $r->int('x', 0, $width - 1, 0);
        $y = $r->int('y', 0, $height - 1, 0);

        return [$x, $y, $r->int('w', 1, $width - $x, $width - $x), $r->int('h', 1, $height - $y, $height - $y)];
    }

    // ---- looking, testing

    private function render(Args $args, Context $c): Result
    {
        /** @var Level $level */
        $level = $this->studio->find('level', $args->raw('id'), $c->user);
        $px = $args->int('px', 4, 32, 8);
        if (!in_array($px, [4, 8, 16, 32], true)) throw new ToolError('px: 4, 8, 16 or 32.');
        [$x, $y, $w, $h] = $this->region($args->raw('region'), $level->getWidth(), $level->getHeight());
        // 4 million pixels at most: smaller blocks if needed
        while ($px > 4 && $w * $h * $px * $px > 4_000_000) $px = intdiv($px, 2);
        if ($w * $h * $px * $px > 4_000_000) throw new ToolError('Too big a picture: ask for a region.');
        $picture = $this->renderer->render($this->levelData($level), $this->tilesData($level->getTileset()), $px, ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h],
            $args->bool('collision', false), [], [], $args->bool('grid', false));

        return new Result(['level' => $level->getId(), 'region' => ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h], 'px_per_block' => $px,
            'size' => [$picture->width, $picture->height]], [$picture->png()]);
    }

    private function test(Args $args, Context $c): Result
    {
        /** @var Level $level */
        $level = $this->studio->find('level', $args->raw('id'), $c->user);
        $data = $this->levelData($level);
        $tileset = $level->getTileset();
        $blocks = $this->solidity->blocks($tileset->getSolidity(), $tileset->getMasks());
        $run = (new Simulator($data, $blocks, $tileset->getBehaviour()))->play($args->int('seconds', 10, 300, 180));
        $best = $run['attempts'][0];
        $marks = [];
        foreach ($best['events'] as $e) {
            if (in_array($e['type'], ['death', 'stuck for good', 'hurt'], true)) $marks[] = [$e['at'][0] * 32, $e['at'][1] * 32 - 16, $e['type'] === 'hurt' ? 'ffd400' : 'ff2020'];
        }
        $images = [];
        if ($args->bool('picture', true)) {
            $px = $level->getWidth() * $level->getHeight() * 64 > 4_000_000 ? 4 : 8;
            $images[] = $this->renderer->render($data, $this->tilesData($tileset), $px, [], false, $best['path'], $marks)->png();
        }
        foreach ($run['attempts'] as &$attempt) unset($attempt['path']);
        unset($attempt);

        return new Result([
            'level' => $level->getId(),
            'verdict' => $run['finished'] ? 'The bot reached the goal.' : 'The bot did not reach the goal: see where it ended (ended_at, events) and fix that place.',
            'attempts' => $run['attempts'],
            'checks' => $this->checks($level, $blocks),
            'note' => 'Positions are in blocks ([x, y], y at Sonic\'s feet). An approximation of the game: badniks stand still, platforms do not move.',
        ], $images);
    }

    /** What is wrong in a level, without playing it. */
    private function checks(Level $level, array $blocks): array
    {
        $w = $level->getWidth();
        $h = $level->getHeight();
        $cells = $level->getBlocks();
        $solid = function (int $px, int $py, string $side = '') use ($blocks, $cells, $w, $h) {
            if ($px < 0 || $py < 0 || $px >= $w * 32 || $py >= $h * 32) return false;
            $b = $blocks[ord($cells[($py >> 5) * $w + ($px >> 5)])] ?? null;

            return $b !== null && ($side === '' || $b[$side]) && (($b['rows'][$py & 31] >> (31 - ($px & 31))) & 1) === 1;
        };
        $out = [];
        [$sx, $sy] = $level->getStart();
        if ($solid($sx * 32 + 12, $sy * 32 + 16, 'walls')) $out[] = "The start [$sx, $sy] is inside a wall.";
        $ground = null;
        for ($py = $sy * 32 + 32; $py < $h * 32; $py++) if ($solid($sx * 32 + 12, $py, 'floor')) { $ground = $py; break; }
        if ($ground === null) $out[] = "There is no ground under the start [$sx, $sy]: Sonic falls out of the level.";
        $goals = 0;
        foreach ($level->getObjects() as [$type, $x, $y]) {
            $name = EditorData::OBJECTS[$type][0] ?? "#$type";
            if ($type === Simulator::GOAL) $goals++;
            $foot = $y * 32 + 16;
            if ($solid($x * 32 + 16, $foot - 4)) $out[] = "Object $type ($name) at [$x, $y] is inside a solid block.";
            if (in_array($type, self::GROUNDED, true)) {
                $gap = null;
                for ($d = 0; $d <= 64; $d++) if ($solid($x * 32 + 16, $foot + $d, 'floor')) { $gap = $d; break; }
                if ($gap === null || $gap > 3) $out[] = "Object $type ($name) at [$x, $y] floats " . ($gap === null ? 'over nothing' : "$gap pixels over the ground")
                    . ' (its foot is at y 16 of its block: put it in the row of a ground whose surface is at y 16).';
            }
        }
        if ($goals === 0) $out[] = 'No goal sign (object 7): the level never ends.';
        if ($goals > 1) $out[] = "$goals goal signs: one is enough.";
        $rings = 0;
        foreach (unpack('C*', $cells) as $b) if (in_array($b, self::RINGS, true)) $rings++;

        return ['problems' => $out ?: ['None found.'], 'rings' => $rings, 'objects' => count($level->getObjects()), 'goal_signs' => $goals];
    }

    /** A level as the renderer and the bot take it. */
    private function levelData(Level $level): array
    {
        return ['width' => $level->getWidth(), 'height' => $level->getHeight(), 'blocks' => $level->getBlocks(), 'start' => $level->getStart(),
            'objects' => $level->getObjects()];
    }

    private function tilesData(Tileset $tileset): array
    {
        return ['image' => $tileset->getImage(), 'solidity' => $tileset->getSolidity(), 'masks' => $tileset->getMasks()];
    }

    /** The thumbnail of the lists, as the editor makes it. */
    private function thumb(Level $level): void
    {
        $level->setThumb($this->renderer->thumbnail($this->levelData($level), $this->tilesData($level->getTileset())));
    }
}
