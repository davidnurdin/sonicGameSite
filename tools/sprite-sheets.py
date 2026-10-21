#!/usr/bin/env python3
"""The game's sheets of sprites the sprite editor starts from (SpriteSet::SHEETS), taken from sonicGamePhp:

    python3 tools/sprite-sheets.py <game>

public/img/sheets/<sheet>.png: the game's tileset/sprites/tileset-<sheet>.png, its colour key (LevelManager::
SPRITE_SHEETS) made transparent.
"""
import os
import sys

from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
# the game's LevelManager::SPRITE_SHEETS
KEYS = {
    'sonic': (0, 72, 0),
    'enemies': (170, 0, 170),
    'objects': (170, 0, 170),
    'rom': (170, 0, 170),
    'eggman': (0, 170, 255),
    'boss': (170, 0, 170),
}


def main():
    game = os.path.abspath(sys.argv[1])
    out = os.path.join(ROOT, 'public/img/sheets')
    os.makedirs(out, exist_ok=True)
    for sheet, key in KEYS.items():
        image = Image.open(os.path.join(game, 'assets/tileset/sprites/tileset-' + sheet + '.png')).convert('RGBA')
        image.putdata([(0, 0, 0, 0) if p[3] == 0 or p[:3] == key else p for p in image.getdata()])
        image.save(os.path.join(out, sheet + '.png'), optimize=True)
        print(sheet, image.size)


if __name__ == '__main__':
    main()
