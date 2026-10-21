#!/usr/bin/env python3
"""The game's data the editors start from (data/game/), taken from sonicGamePhp:

    python3 tools/game-data.py <game>

- zone-<key>.png + zone-<key>.json: the blocks of each zone (32x32, 16 a row), their collision byte (the
  low 6 bits: a shape of shapes.json, bit 7: drawn in front of Sonic) and their behaviour (springs, spikes...,
  blockBehaviour.data.php), from tileset<T>.png, level<N>.solidity and the level header (SP);
- level-<key>.json: act 1 of each zone (blocks, Sonic's start, objects), to start a level from;
- in zone-<key>.json, "cycle": its colour cycling (count and speed of the steps, the lightning of Sky Base) and
  "variants": for each picture of the game besides the plain one (-cycleK, -lightL-cycleK, -water, -flash), the
  colours it changes ("rrggbb": "rrggbb"), to give a changed copy of the zone the same (TileVariants.php);
- public/data/solidity-shapes.json: the 47 collision shapes (32 rows of 32 bits, hex), from tileCol.data.php.
"""
import base64
import json
import os
import re
import subprocess
import sys

from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'data/game')
# key, name, level of act 1, tileset file
ZONES = [('green-hill', 'Green Hill', 1, 1), ('bridge', 'Bridge', 4, 4), ('jungle', 'Jungle', 7, 7),
         ('labyrinth', 'Labyrinth', 10, 10), ('scrap-brain', 'Scrap Brain', 13, 14), ('sky-base', 'Sky Base', 16, 18),
         ('bonus', 'Bonus', 28, 31)]
COLUMNS = 16


def php_json(game, code):
    return json.loads(subprocess.check_output(['php', '-r', code], cwd=game))


def colour_variants(game, tileset, strip):
    """{variant: {"rrggbb": "rrggbb"}}: the colours each picture of the zone changes, pixel by pixel against the plain
    one (a colour turned into several: the most frequent; unchanged colours left out)."""
    folder = os.path.join(game, 'assets/tileset/levels')
    names = sorted(f[len(f'tileset{tileset}'):-4] for f in os.listdir(folder)
                   if re.fullmatch(rf'tileset{tileset}-(cycle\d+|light\d-cycle\d+|water|flash)\.png', f))
    base = strip.load()
    out = {}
    for variant in names:
        image = Image.open(os.path.join(folder, f'tileset{tileset}{variant}.png')).convert('RGBA')
        if image.size != strip.size:
            continue
        pixels = image.load()
        votes = {}
        for y in range(strip.height):
            for x in range(strip.width):
                a, b = base[x, y], pixels[x, y]
                if a[3] < 128:
                    continue
                key = '%02x%02x%02x' % a[:3]
                votes.setdefault(key, {})
                target = '%02x%02x%02x' % b[:3]
                votes[key][target] = votes[key].get(target, 0) + 1
        colours = {}
        for colour, targets in votes.items():
            target = max(targets, key=targets.get)
            if target != colour:
                colours[colour] = target
        out[variant] = colours
    return out


def main():
    game = sys.argv[1]
    os.makedirs(OUT, exist_ok=True)
    shapes = php_json(game, r'''$t = require "assets/levels/tileCol.data.php"; $o = [];
        foreach ($t as $k => $v) { $rows = [];
            foreach ($v["value"] as $r) { $n = 0; foreach ($r as $x => $p) if ($p) $n |= 1 << (31 - $x); $rows[] = sprintf("%08x", $n); }
            $o[$k] = $rows; }
        echo json_encode($o);''')
    with open(os.path.join(ROOT, 'public/data/solidity-shapes.json'), 'w') as f:
        json.dump(shapes, f, separators=(',', ':'))

    for key, name, level, tileset in ZONES:
        strip = Image.open(os.path.join(game, f'assets/tileset/levels/tileset{tileset}.png')).convert('RGBA')
        solidity = list(open(os.path.join(game, f'assets/levels/level{level}.solidity'), 'rb').read())
        count = min(strip.width // 32, 256)
        solidity = (solidity + [0] * count)[:count]
        rows = (count + COLUMNS - 1) // COLUMNS
        sheet = Image.new('RGBA', (COLUMNS * 32, rows * 32))
        for i in range(count):
            sheet.paste(strip.crop((i * 32, 0, i * 32 + 32, 32)), ((i % COLUMNS) * 32, (i // COLUMNS) * 32))
        sheet.save(os.path.join(OUT, f'zone-{key}.png'), optimize=True)

        meta = php_json(game, f'echo json_encode(eval("return " . file_get_contents("assets/levels/level{level}.meta") . ";"));')
        behaviour = php_json(game, f'echo json_encode((require "assets/levels/blockBehaviour.data.php")[{meta["SP"]}] ?? []);')
        behaviour = (behaviour + [0] * count)[:count]
        objects = php_json(game, f'echo json_encode(array_map(fn($o) => [$o["type"], $o["x"], $o["y"]], require "assets/levels/level{level}.objects.php"));')
        variants = colour_variants(game, tileset, strip)
        cycle = {'count': int(meta.get('CC', 0)), 'speed': int(meta.get('CS', 0)), 'lightning': bool(int(meta.get('TL', 0)) & 2)}
        with open(os.path.join(OUT, f'zone-{key}.json'), 'w') as f:
            json.dump({'name': name, 'count': count, 'solidity': solidity, 'behaviour': behaviour, 'sp': meta['SP'],
                       'cycle': cycle, 'variants': variants}, f, separators=(',', ':'))
        blocks = open(os.path.join(game, f'assets/levels/level{level}.bin'), 'rb').read()
        width, height = meta['FW'], meta['FH']
        with open(os.path.join(OUT, f'level-{key}.json'), 'w') as f:
            json.dump({'name': f'{name} 1', 'zone': key, 'width': width, 'height': height,
                       'blocks': base64.b64encode(blocks[:width * height]).decode(),
                       'start': [meta['SX'], meta['SY']], 'objects': objects, 'music': meta['MU']}, f, separators=(',', ':'))
        print(key, count, 'blocks', f'{width}x{height}', len(objects), 'objects')


if __name__ == '__main__':
    main()
