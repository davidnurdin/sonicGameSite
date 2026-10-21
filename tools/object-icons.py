#!/usr/bin/env python3
"""The pictures of the game's objects for the level editor (badniks, monitors, platforms...), taken from the game:

    DISPLAY=:77 python3 tools/object-icons.py <game> [php command]

Runs the native game (an X display: Xvfb) on a level holding every object of EditorData::OBJECTS, in its mode
SONIC_OBJECT_ICONS (each object drawn in a cell, on magenta), takes a screenshot of its window and cuts it:

- public/img/objects.png: one picture an object, side by side;
- public/data/object-icons.json: {type: [x, y, w, h, dx, dy]}: its place in objects.png, and where it is drawn
  from the top left corner of the object's block (level pixels).
"""
import json
import os
import subprocess
import sys
import tempfile
import time

from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MAGENTA = (255, 0, 255)
SPACING = 4          # blocks between two objects of the level
ROW = 8              # the blocks' row of the objects


def main():
    game = os.path.abspath(sys.argv[1])
    php = sys.argv[2].split() if len(sys.argv) > 2 else ['php8.2', '-d', 'extension=sdl.so', '-d', 'extension=sdl_image.so',
                                                          '-d', 'extension=sdl_ttf.so', '-d', 'extension=sdl_mixer.so']
    types = json.loads(subprocess.check_output(['php', '-r', 'require "vendor/autoload.php"; '
                                                'echo json_encode(array_keys(App\\Service\\EditorData::OBJECTS));'], cwd=ROOT))
    zone = json.load(open(os.path.join(ROOT, 'data/game/zone-green-hill.json')))
    work = tempfile.mkdtemp(prefix='object-icons-')
    width = SPACING * len(types) + 8
    level = {'width': width, 'height': 16, 'blocks': '', 'start': [1, ROW], 'music': 0, 'sp': 0,
             'objects': [[t, SPACING * (i + 1), ROW] for i, t in enumerate(types)],
             'solidity': zone['solidity'], 'behaviour': zone.get('behaviour', [])}
    import base64
    level['blocks'] = base64.b64encode(bytes(width * 16)).decode()
    with open(os.path.join(work, 'level.json'), 'w') as f:
        json.dump(level, f)
    Image.open(os.path.join(ROOT, 'data/game/zone-green-hill.png')).save(os.path.join(work, 'tiles.png'))
    cells_file = os.path.join(work, 'cells.json')
    boot = os.path.join(work, 'boot.php')
    with open(boot, 'w') as f:
        f.write(f"""<?php
define('SONIC_LANG', 'fr');
define('SONIC_CUSTOM_LEVEL', {json.dumps(os.path.join(work, 'level.json'))});
define('SONIC_OBJECT_ICONS', {json.dumps(cells_file)});
$argv = ['app.php']; $_SERVER['argv'] = $argv; chdir({json.dumps(game)});
require {json.dumps(os.path.join(game, 'app.php'))};
""")
    env = dict(os.environ, SDL_AUDIODRIVER='dummy')
    proc = subprocess.Popen(php + [boot], cwd=game, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(600):
            if os.path.exists(cells_file) or proc.poll() is not None:
                break
            time.sleep(0.1)
        if not os.path.exists(cells_file):
            sys.exit('the game did not draw the objects')
        time.sleep(1.0)
        window = subprocess.check_output(['xwininfo', '-root', '-tree'], text=True)
        wid = next(line.split()[0] for line in window.splitlines() if '"SonicGame"' in line)
        shot = os.path.join(work, 'shot.png')
        subprocess.check_call(['import', '-window', wid, shot])
    finally:
        proc.terminate()
    cells = json.load(open(cells_file))
    image = Image.open(shot).convert('RGB')
    scale = min(image.width / cells['width'], image.height / cells['height'])
    ox = (image.width - cells['width'] * scale) / 2
    oy = (image.height - cells['height'] * scale) / 2
    size = cells['cell']
    pictures = {}
    for i, (type_, x, y, box_x, box_bottom) in enumerate(cells['cells']):
        crop = image.crop((round(ox + x * scale), round(oy + y * scale), round(ox + (x + size) * scale), round(oy + (y + size) * scale)))
        crop = crop.resize((size, size), Image.NEAREST).convert('RGBA')
        crop.putdata([(0, 0, 0, 0) if p[:3] == MAGENTA else p for p in crop.getdata()])
        box = crop.getbbox()
        if box is None or str(type_) in pictures:
            continue
        # the object's box: its bottom middle at (size / 2, size - 4) of the cell (Game::renderObjectIcons)
        tx = SPACING * (types.index(type_) + 1)
        dx = box[0] - size // 2 + (box_x - tx * 32)
        dy = box[1] - (size - 4) + (box_bottom - ROW * 32)
        picture = crop.crop(box)
        # a moving object (swinging ball, platform...) may start far from its block: on its block in the editor
        if abs(dx + picture.width / 2 - 16) > 40 or dy + picture.height < -8 or dy > 40:
            dx, dy = 16 - picture.width // 2, 32 - picture.height
        pictures[str(type_)] = (picture, dx, dy)
    # side by side, in one picture
    sheet = Image.new('RGBA', (sum(p.width + 1 for p, _, _ in pictures.values()), max(p.height for p, _, _ in pictures.values())))
    index = {}
    x = 0
    for type_, (picture, dx, dy) in pictures.items():
        sheet.paste(picture, (x, 0))
        index[type_] = [x, 0, picture.width, picture.height, dx, dy]
        x += picture.width + 1
    sheet.save(os.path.join(ROOT, 'public/img/objects.png'), optimize=True)
    with open(os.path.join(ROOT, 'public/data/object-icons.json'), 'w') as f:
        json.dump(index, f, separators=(',', ':'))
    missing = [t for t in types if str(t) not in index]
    print(f'{len(index)} objects', f'(no picture: {missing})' if missing else '')


if __name__ == '__main__':
    main()
