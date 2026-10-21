<?php

namespace App\Service\Studio;

use App\Entity\SpriteSet;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The sets of sprites: the sheets of the game redrawn (recoloured, drawn over), sheet by sheet. */
final class SpriteTools implements ToolProvider
{
    private const ID = ['type' => 'integer', 'minimum' => 1];
    private const SHEET = ['type' => 'string', 'enum' => ['sonic', 'enemies', 'objects', 'rom', 'eggman', 'boss']];
    private const MAX_REGION = 128 * 128;

    public function __construct(private Studio $studio, #[Autowire('%kernel.project_dir%/public/img/sheets')] private string $sheets)
    {
    }

    public function tools(): array
    {
        $region = ['type' => 'object', 'description' => 'pixels', 'properties' => ['x' => ['type' => 'integer'], 'y' => ['type' => 'integer'],
            'w' => ['type' => 'integer'], 'h' => ['type' => 'integer']]];

        return [
            new Tool('get_sprites', 'Read a set of sprites', 'A set of sprites: which sheets it redraws (the others are the game\'s), their sizes.',
                ['properties' => ['id' => self::ID], 'required' => ['id']], $this->get(...)),
            new Tool('get_sprite_sheet', 'Look at a sheet', 'A sheet of a set of sprites (id) or of the game (no id): as an image (scale 1-4), or a region of it as pixel '
                . 'art text (128 x 128 pixels at most) to see the exact colours before recolouring or drawing.',
                ['properties' => ['id' => self::ID, 'sheet' => self::SHEET, 'as' => ['type' => 'string', 'enum' => ['image', 'pixels'], 'default' => 'image'],
                    'region' => $region, 'scale' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'default' => 1]], 'required' => ['sheet']],
                $this->sheet(...), heavy: true),
            new Tool('create_sprites', 'Make a set of sprites', 'A new set of sprites on this account (private), with the game\'s sheets. Change them with edit_sprites; '
                . 'a level plays with it when its sprites_id is set (edit_level).',
                ['properties' => ['name' => ['type' => 'string', 'maxLength' => 40]], 'required' => ['name']], $this->create(...), write: true),
            new Tool('edit_sprites', 'Change sprites', 'Changes sheets of a set of sprites of this account. Operations: {"op":"recolour","sheet","map":{"#rrggbb" or "transparent":"#rrggbb" or "transparent"},'
                . '"region"?} (the easiest: a red Sonic, other badniks); {"op":"draw","sheet","x","y","pixels":{palette, rows, scale}} pixel art drawn at x, y (its '
                . 'transparent pixels erase); {"op":"reset","sheet"} back to the game\'s. A sheet keeps its size and layout (the game cuts the frames at the same places). '
                . 'All or nothing.',
                ['properties' => ['id' => self::ID, 'ops' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object']],
                    'set' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 40]]]], 'required' => ['id', 'ops']],
                $this->edit(...), write: true, heavy: true),
        ];
    }

    private function get(Args $args, Context $c): Result
    {
        /** @var SpriteSet $set */
        $set = $this->studio->find('sprites', $args->raw('id'), $c->user);
        $sheets = [];
        foreach (SpriteSet::SHEETS as $sheet => [$w, $h, $label]) $sheets[$sheet] = ['size' => [$w, $h], 'what' => $label, 'redrawn' => $set->getSheet($sheet) !== null];

        return new Result($this->studio->summary($set, $c->user) + ['sheets' => $sheets]);
    }

    private function sheet(Args $args, Context $c): Result
    {
        $sheet = $args->choice('sheet', array_keys(SpriteSet::SHEETS));
        $set = $args->has('id') ? $this->studio->find('sprites', $args->raw('id'), $c->user) : null;
        $canvas = $this->load($set, $sheet);
        if ($args->has('region')) {
            $r = new Args($args->object('region'), 'region.');
            $x = $r->int('x', 0, $canvas->width - 1, 0);
            $y = $r->int('y', 0, $canvas->height - 1, 0);
            $canvas = $canvas->crop($x, $y, $r->int('w', 1, $canvas->width - $x, $canvas->width - $x), $r->int('h', 1, $canvas->height - $y, $canvas->height - $y));
        }
        $info = ['sheet' => $sheet, 'of' => $set ? 'set #' . $set->getId() : 'the game', 'size' => [$canvas->width, $canvas->height],
            'colours' => array_slice(array_map(fn($k) => '#' . $k, array_keys($canvas->colours())), 0, 40)];
        if ($args->choice('as', ['image', 'pixels'], 'image') === 'pixels') {
            if ($canvas->width * $canvas->height > self::MAX_REGION) throw new ToolError('pixels: a region of 128 x 128 pixels at most.');

            return new Result($info + ['pixels' => Pixels::text($canvas) ?? 'too many colours: look at it as an image']);
        }

        return new Result($info, [$canvas->grow($args->int('scale', 1, 4, 1))->png()]);
    }

    private function create(Args $args, Context $c): Result
    {
        $this->studio->checkRoom('sprites', $c->user);
        $set = new SpriteSet($c->user, Studio::name($args));
        $this->studio->em->persist($set);
        $this->studio->em->flush();
        $this->studio->creations->created($set, $c->user);

        return new Result(['created' => $this->studio->summary($set, $c->user), 'next' => 'get_sprite_sheet to look, edit_sprites to change.']);
    }

    private function edit(Args $args, Context $c): Result
    {
        /** @var SpriteSet $set */
        $set = $this->studio->find('sprites', $args->raw('id'), $c->user, true);
        $sheets = $set->getSheets();
        $canvases = [];
        foreach ($args->list('ops', 100) as $i => $_) {
            $op = $args->item('ops', $i);
            $kind = $op->choice('op', ['recolour', 'draw', 'reset']);
            $sheet = $op->choice('sheet', array_keys(SpriteSet::SHEETS));
            if ($kind === 'reset') {
                unset($sheets[$sheet]);
                $canvases[$sheet] = null;
                continue;
            }
            $canvas = $canvases[$sheet] ??= $this->load(isset($sheets[$sheet]) ? $set : null, $sheet);

            if ($kind === 'recolour') {
                $map = [];
                foreach ($op->object('map') as $from => $to) $map[Pixels::rgb((string) $from, $op->where() . 'map', true)] = Pixels::rgb($to, $op->where() . "map[$from]", true);
                $r = $op->has('region') ? new Args($op->object('region'), $op->where() . 'region.') : null;
                $canvas->recolour($map, $r?->int('x', 0, $canvas->width - 1, 0) ?? 0, $r?->int('y', 0, $canvas->height - 1, 0) ?? 0,
                    $r?->int('w', 1, $canvas->width, $canvas->width), $r?->int('h', 1, $canvas->height, $canvas->height));
            } else {
                $pixels = $op->raw('pixels');
                $rows = is_array($pixels) ? ($pixels['rows'] ?? null) : null;
                $scale = is_array($pixels) ? ($pixels['scale'] ?? 1) : 1;
                if (!is_array($rows) || $rows === [] || !is_string($rows[0] ?? null) || !is_int($scale)) throw new ToolError($op->where() . 'pixels: {palette, rows, scale}.');
                $w = mb_strlen($rows[0]) * $scale;
                $h = count($rows) * $scale;
                if ($w * $h > 256 * 256) throw new ToolError($op->where() . 'pixels: 256 x 256 at most at once.');
                $picture = Pixels::parse($pixels, $w, $h, $op->where() . 'pixels');
                $canvas->draw($picture, $op->int('x', -$w + 1, $canvas->width - 1), $op->int('y', -$h + 1, $canvas->height - 1), false);
            }
        }
        foreach ($canvases as $sheet => $canvas) {
            if ($canvas === null) continue;
            $png = $canvas->png();
            if (strlen($png) > SpriteSet::MAX_PNG) throw new ToolError("The sheet $sheet is too big once drawn.");
            $sheets[$sheet] = base64_encode($png);
        }
        if ($args->object('set', false) !== []) {
            $s = new Args($args->object('set'), 'set.');
            if ($s->has('name')) $set->setName(Studio::name($s));
        }
        $set->setSheets($sheets);
        $this->studio->em->flush();
        $this->studio->creations->saved($set, $c->user, null);
        $images = [];
        foreach ($canvases as $canvas) if ($canvas !== null && count($images) < 2) $images[] = $canvas->png();

        return new Result(['saved' => $this->studio->summary($set, $c->user)], $images);
    }

    /** A sheet of a set (its own) or of the game. */
    private function load(?SpriteSet $set, string $sheet): Canvas
    {
        $png = $set?->getSheet($sheet) ?? (string) file_get_contents($this->sheets . '/' . $sheet . '.png');

        return Canvas::fromPng($png) ?? throw new ToolError("The sheet $sheet cannot be read.");
    }
}
