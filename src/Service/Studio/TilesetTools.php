<?php

namespace App\Service\Studio;

use App\Entity\Level;
use App\Entity\Tileset;
use App\Service\EditorData;

/** The sets of tiles: read (the image, each block in pixels), make, change block by block. */
final class TilesetTools implements ToolProvider
{
    private const ID = ['type' => 'integer', 'minimum' => 1];
    private const PIXELS = ['type' => 'object', 'description' => '{"palette": {".": "transparent", "a": "#rrggbb", ...}, "rows": [...], "scale": 1|2|4|8} '
        . '(32/scale rows of 32/scale characters) or {"png": base64}'];
    private const MAX_OPS = 300;

    public function __construct(private Studio $studio, private Solidity $solidity)
    {
    }

    public function tools(): array
    {
        $ops = ['type' => 'array', 'maxItems' => self::MAX_OPS, 'description' => 'Operations, in order: '
            . '{"op":"set_block","index","pixels"?,"shape"?,"front"?,"behaviour"?} draws and/or sets a block (index = the number of blocks: adds one at the end; '
            . 'shape: 0-46 collision, front: drawn in front of Sonic, behaviour: see get_reference "behaviours"); '
            . '{"op":"copy_block","from_index","from_tileset_id"?,"to_index"} a block (picture, collision, behaviour) of this or another tileset you can see; '
            . '{"op":"recolour","map":{"#rrggbb" or "transparent":"#rrggbb" or "transparent"},"blocks"?:[index...]} colours replaced (all blocks if none given); '
            . '{"op":"collision_mask","index","rows"?:[32 x "8 hex digits"],"floor"?,"walls"?,"ceiling"?} a collision drawn pixel by pixel (rows null: back to its shape); '
            . '{"op":"remove_last_blocks","count"} (not those used by levels); '
            . '{"op":"animate","index","frames":[block...],"ticks"} makes a block animated in the game: it shows the pictures of frames (2-16 blocks of this '
            . 'set, often extra blocks at the end, drawn with set_block) in turn, each for ticks frames of the game (60 a second; 6-10 for water), and keeps '
            . 'its own collision and behaviour; frames [] stops it. See get_reference "tiles" (animations).',
            'items' => ['type' => 'object', 'properties' => ['op' => ['type' => 'string', 'enum' => ['set_block', 'copy_block', 'recolour', 'collision_mask', 'remove_last_blocks', 'animate']]],
                'required' => ['op']]];

        return [
            new Tool('get_tileset', 'Read a tileset', 'A tileset: each block\'s collision shape, front, behaviour, animation (the blocks it shows in turn), and the '
                . 'image of all its blocks (16 a row, 32 x 32 each; scale 2 doubles it to see the pixels better).',
                ['properties' => ['id' => self::ID, 'image' => ['type' => 'boolean', 'default' => true], 'scale' => ['type' => 'integer', 'enum' => [1, 2], 'default' => 1]],
                    'required' => ['id']], $this->get(...)),
            new Tool('get_tileset_block', 'Read blocks', 'Blocks of a tileset as pixel art text (palette + 32 rows of 32 characters), with their collision: to copy a style, '
                . 'change a block, or see exactly what is drawn. Up to 8 blocks at once.',
                ['properties' => ['id' => self::ID, 'indexes' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 8]], 'required' => ['id', 'indexes']],
                $this->blocks(...)),
            new Tool('create_tileset', 'Make a tileset', 'A new empty tileset on this account (private): blocks empty blocks (1 by default). Draw them with edit_tileset. '
                . 'To start from a zone of the game or another tileset, use fork.',
                ['properties' => ['name' => ['type' => 'string', 'maxLength' => 40], 'blocks' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Tileset::MAX, 'default' => 1]],
                    'required' => ['name']], $this->create(...), write: true),
            new Tool('edit_tileset', 'Change a tileset', 'Changes a tileset of this account: draws blocks (pixel art as text), copies blocks, recolours, sets collisions, '
                . 'behaviours and animations (water, waterfalls, lava, lights). All or nothing. Levels using it change too.',
                ['properties' => ['id' => self::ID, 'ops' => $ops, 'set' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 40]]]],
                    'required' => ['id', 'ops']], $this->edit(...), write: true, heavy: true),
        ];
    }

    private function get(Args $args, Context $c): Result
    {
        /** @var Tileset $tileset */
        $tileset = $this->studio->find('tileset', $args->raw('id'), $c->user);
        $masks = $tileset->getMasks();
        $animations = $tileset->getAnimations();
        $blocks = [];
        foreach ($tileset->getSolidity() as $i => $value) {
            $blocks[] = array_filter(['index' => $i, 'shape' => $value & 0x3F, 'front' => ($value & 0x80) !== 0 ?: null,
                'behaviour' => ($tileset->getBehaviour()[$i] ?? 0) ?: null, 'collision_mask' => isset($masks[$i]) ?: null,
                'ring' => $i >= 120 && $i <= 123 ?: null,
                'animation' => isset($animations[$i]) ? ['frames' => $animations[$i]['f'], 'ticks' => $animations[$i]['t']] : null], fn($v) => $v !== null);
        }
        $images = [];
        if ($args->bool('image', true)) {
            $canvas = Canvas::fromPng($tileset->getImage());
            if ($canvas !== null) $images[] = $canvas->grow($args->int('scale', 1, 2, 1))->png();
        }

        return new Result($this->studio->summary($tileset, $c->user) + ['blocks_detail' => $blocks,
            'image_layout' => '16 blocks a row, 32 x 32 pixels each: block i at x = (i % 16) * 32, y = floor(i / 16) * 32'], $images);
    }

    private function blocks(Args $args, Context $c): Result
    {
        /** @var Tileset $tileset */
        $tileset = $this->studio->find('tileset', $args->raw('id'), $c->user);
        $sheet = Canvas::fromPng($tileset->getImage()) ?? throw new ToolError('This tileset\'s image cannot be read.');
        $out = [];
        foreach ($args->list('indexes', 8) as $k => $index) {
            if (!is_int($index) || $index < 0 || $index >= $tileset->getCount()) throw new ToolError("indexes[$k]: a block 0-" . ($tileset->getCount() - 1) . '.');
            $shape = $tileset->getSolidity()[$index] & 0x3F;
            $out[] = ['index' => $index, 'shape' => $shape, 'front' => ($tileset->getSolidity()[$index] & 0x80) !== 0,
                'behaviour' => $tileset->getBehaviour()[$index] ?? 0, 'collision_mask' => $tileset->getMasks()[$index] ?? null,
                'pixels' => Pixels::text($this->block($sheet, $index)) ?? 'too many colours: use get_tileset (image)'];
        }

        return new Result(['tileset' => $tileset->getId(), 'blocks' => $out]);
    }

    private function create(Args $args, Context $c): Result
    {
        $this->studio->checkRoom('tileset', $c->user);
        $count = $args->int('blocks', 1, Tileset::MAX, 1);
        $rows = intdiv($count + Tileset::COLUMNS - 1, Tileset::COLUMNS);
        $tileset = new Tileset($c->user, Studio::name($args), Canvas::blank(Tileset::COLUMNS * Tileset::SIZE, $rows * Tileset::SIZE)->png(), array_fill(0, $count, 0));
        $this->studio->em->persist($tileset);
        $this->studio->em->flush();
        $this->studio->creations->created($tileset, $c->user);

        return new Result(['created' => $this->studio->summary($tileset, $c->user), 'next' => 'edit_tileset set_block to draw its blocks.']);
    }

    private function edit(Args $args, Context $c): Result
    {
        /** @var Tileset $tileset */
        $tileset = $this->studio->find('tileset', $args->raw('id'), $c->user, true);
        $sheet = Canvas::fromPng($tileset->getImage()) ?? throw new ToolError('This tileset\'s image cannot be read.');
        $solidity = $tileset->getSolidity();
        $behaviour = $tileset->getBehaviour();
        $masks = $tileset->getMasks();
        $animations = $tileset->getAnimations();
        $pictures = [];
        for ($i = 0; $i < count($solidity); $i++) $pictures[$i] = $this->block($sheet, $i);
        $others = [];
        foreach ($args->list('ops', self::MAX_OPS) as $i => $_) {
            $op = $args->item('ops', $i);
            switch ($op->choice('op', ['set_block', 'copy_block', 'recolour', 'collision_mask', 'remove_last_blocks', 'animate'])) {
                case 'set_block':
                    $index = $op->int('index', 0, min(Tileset::MAX - 1, count($solidity)));
                    if ($index === count($solidity)) {
                        $solidity[] = 0;
                        $behaviour[] = 0;
                        $pictures[] = Canvas::blank(32, 32);
                    }
                    if ($op->has('pixels')) $pictures[$index] = Pixels::parse($op->raw('pixels'), 32, 32, $op->where() . 'pixels');
                    $shape = $op->has('shape') ? $op->int('shape', 0, 46) : $solidity[$index] & 0x3F;
                    $front = $op->has('front') ? $op->bool('front') : ($solidity[$index] & 0x80) !== 0;
                    $solidity[$index] = $shape | ($front ? 0x80 : 0);
                    if ($op->has('behaviour')) {
                        $b = $op->int('behaviour', 0, 31);
                        if (!isset(EditorData::BEHAVIOURS[$b])) throw new ToolError($op->where() . 'behaviour: one of ' . implode(', ', array_keys(EditorData::BEHAVIOURS)) . '.');
                        $behaviour[$index] = $b;
                    }
                    break;
                case 'copy_block':
                    $from = $op->has('from_tileset_id') ? ($others[$op->raw('from_tileset_id')] ??= $this->studio->find('tileset', $op->raw('from_tileset_id'), $c->user)) : null;
                    $count = $from?->getCount() ?? count($solidity);
                    $source = $op->int('from_index', 0, $count - 1);
                    $index = $op->int('to_index', 0, min(Tileset::MAX - 1, count($solidity)));
                    if ($from !== null) {
                        $fromSheet = Canvas::fromPng($from->getImage()) ?? throw new ToolError('That tileset\'s image cannot be read.');
                        $picture = $this->block($fromSheet, $source);
                        [$s, $b, $m] = [$from->getSolidity()[$source], $from->getBehaviour()[$source] ?? 0, $from->getMasks()[$source] ?? null];
                    } else {
                        [$picture, $s, $b, $m] = [$pictures[$source], $solidity[$source], $behaviour[$source] ?? 0, $masks[$source] ?? null];
                    }
                    $pictures[$index] = $picture;
                    $solidity[$index] = $s;
                    $behaviour[$index] = $b;
                    if ($m !== null) $masks[$index] = $m; else unset($masks[$index]);
                    break;
                case 'recolour':
                    $map = [];
                    foreach ($op->object('map') as $from => $to) $map[Pixels::rgb((string) $from, $op->where() . 'map', true)] = Pixels::rgb($to, $op->where() . "map[$from]", true);
                    $which = $op->has('blocks') ? $op->list('blocks', Tileset::MAX) : array_keys($pictures);
                    foreach ($which as $index) {
                        if (!isset($pictures[$index])) throw new ToolError($op->where() . "blocks: $index is not a block.");
                        $pictures[$index]->recolour($map);
                    }
                    break;
                case 'collision_mask':
                    $index = $op->int('index', 0, count($solidity) - 1);
                    if ($op->raw('rows') === null) {
                        unset($masks[$index]);
                        break;
                    }
                    $w = ($op->bool('floor', true) ? 1 : 0) | ($op->bool('walls', false) ? 2 : 0) | ($op->bool('ceiling', false) ? 4 : 0);
                    $mask = EditorData::masks([$index => ['r' => $op->raw('rows'), 'w' => $w]], count($solidity));
                    if ($mask === null) throw new ToolError($op->where() . 'rows: 32 strings of 8 hex digits (bit 31 = the left pixel).');
                    $masks[$index] = $mask[$index];
                    break;
                case 'remove_last_blocks':
                    $n = $op->int('count', 1, count($solidity) - 1);
                    $keep = count($solidity) - $n;
                    $used = $this->usedBlocks($tileset);
                    if ($used >= $keep) throw new ToolError($op->where() . "levels use block $used: only blocks after it can be removed.");
                    array_splice($solidity, $keep);
                    array_splice($behaviour, $keep);
                    array_splice($pictures, $keep);
                    $masks = array_filter($masks, fn($k) => $k < $keep, ARRAY_FILTER_USE_KEY);
                    // the animations of those blocks, or showing them, go too
                    $animations = array_filter($animations, fn(array $a, $k) => $k < $keep && max($a['f']) < $keep, ARRAY_FILTER_USE_BOTH);
                    break;
                case 'animate':
                    $index = $op->int('index', 0, count($solidity) - 1);
                    $frames = $op->list('frames', EditorData::MAX_FRAMES);
                    if ($frames === []) {
                        unset($animations[$index]);
                        break;
                    }
                    if ($index >= 120 && $index <= 123) throw new ToolError($op->where() . 'index: the blocks of the rings (120-123) cannot be animated.');
                    if (count($frames) < 2) throw new ToolError($op->where() . 'frames: 2 to ' . EditorData::MAX_FRAMES . ' blocks (or [] to stop it).');
                    foreach ($frames as $k => $frame) {
                        if (!is_int($frame) || $frame < 0 || $frame >= count($solidity)) {
                            throw new ToolError($op->where() . "frames[$k]: a block 0-" . (count($solidity) - 1) . ' (draw new blocks first with set_block).');
                        }
                    }
                    $animations[$index] = ['f' => $frames, 't' => $op->int('ticks', 1, 240, 8)];
                    if (count($animations) > EditorData::MAX_ANIMATIONS) throw new ToolError($op->where() . 'at most ' . EditorData::MAX_ANIMATIONS . ' animated blocks.');
                    break;
            }
        }
        if ($args->object('set', false) !== []) {
            $set = new Args($args->object('set'), 'set.');
            if ($set->has('name')) $tileset->setName(Studio::name($set));
        }
        // the image again: 16 blocks a row
        $count = count($solidity);
        $image = Canvas::blank(Tileset::COLUMNS * Tileset::SIZE, intdiv($count + Tileset::COLUMNS - 1, Tileset::COLUMNS) * Tileset::SIZE);
        foreach ($pictures as $i => $picture) $image->draw($picture, ($i % Tileset::COLUMNS) * Tileset::SIZE, intdiv($i, Tileset::COLUMNS) * Tileset::SIZE, false);
        $blocks = EditorData::blocks(base64_encode($image->png()), array_values($solidity), array_values($behaviour), $masks, $animations);
        if ($blocks === null) throw new ToolError('The tileset is not valid after these operations (too big an image?).');
        $tileset->setBlocks(...$blocks);
        $this->studio->em->flush();
        $this->studio->creations->saved($tileset, $c->user, null);

        return new Result(['saved' => $this->studio->summary($tileset, $c->user), 'next' => 'get_tileset to see it.'], [$image->png()]);
    }

    private function block(Canvas $sheet, int $index): Canvas
    {
        return $sheet->crop(($index % Tileset::COLUMNS) * Tileset::SIZE, intdiv($index, Tileset::COLUMNS) * Tileset::SIZE, Tileset::SIZE, Tileset::SIZE);
    }

    /** The highest block the levels made with this set use (-1: none). */
    private function usedBlocks(Tileset $tileset): int
    {
        $max = -1;
        foreach ($this->studio->em->getRepository(Level::class)->findBy(['tileset' => $tileset]) as $level) {
            $max = max($max, ...array_values(unpack('C*', $level->getBlocks())));
        }

        return $max;
    }
}
