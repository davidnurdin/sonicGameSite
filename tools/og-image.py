#!/usr/bin/env python3
"""The picture of the links of the site (Open Graph / Twitter, 1200x630): public/img/og-fr.png and og-en.png.

    python3 tools/og-image.py tools/og-background.png <game>

<screenshot.png>: a screenshot of the native game in Green Hill (window 1280x800, the game view at 220,187 -
1060,613, as taken by `import -window root`); <game>: the folder of sonicGamePhp (its font). The Sonic are the
ones of public/img/sonics.png (the background of the site).
"""
import os
import sys

from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 630
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SANS = '/usr/share/fonts/truetype/noto/NotoSans-Black.ttf'

TEXTS = {
    'fr': ('Le Sonic de la Master System, réécrit en PHP', 'Dans ton navigateur · seul ou en ligne jusqu\'à 6'),
    'en': ('The Master System Sonic, rewritten in PHP', 'In your browser · alone or online, up to 6'),
}


def background(shot):
    view = Image.open(shot).convert('RGB').crop((220, 187, 1060, 613))
    big = view.resize((view.width * 2, view.height * 2), Image.NEAREST)
    # without the HUD (top left, lives at the bottom left): the right part, down to the ground
    x, y = 240, big.height - H
    return big.crop((x, y, x + W, y + H))


def shade(img):
    # darker at the top, under the text
    overlay = Image.new('RGBA', (W, H))
    draw = ImageDraw.Draw(overlay)
    for y in range(330):
        draw.line([(0, y), (W, y)], fill=(8, 20, 70, int(170 * (1 - y / 330) ** 1.3)))
    return Image.alpha_composite(img.convert('RGBA'), overlay)


def outlined(draw, xy, text, font, fill, outline, width, anchor='mm'):
    draw.text(xy, text, font=font, fill=fill, anchor=anchor, stroke_width=width, stroke_fill=outline)


def grass_top(img, x):
    """The top of the grass of the background at x: just over the dark brown line under the grass."""
    for y in range(300, H):
        r, g, b, _ = img.getpixel((x, y))
        if 40 <= r <= 100 and g < 20 and b < 20:
            return y - 12
    return H - 100


def sonics(img):
    sheet = Image.open(os.path.join(ROOT, 'public/img/sonics.png')).convert('RGBA')
    fw, fh, scale = 23, 32, 3
    poses = [8, 12, 9, 0, 10, 13]       # running, rolling, waiting...
    xs = [20, 290, 450, 600, 830, 1040]
    for colour, (x, pose) in enumerate(zip(xs, poses)):
        frame = sheet.crop((pose * fw, colour * fh, (pose + 1) * fw, (colour + 1) * fh))
        frame = frame.resize((fw * scale, fh * scale), Image.NEAREST)
        ground = min(grass_top(img, x + fw * scale // 2 + dx) for dx in (-12, 0, 12))
        y = ground + 6 - fh * scale - (30 if pose in (12, 13) else 0)
        img.alpha_composite(frame, (x, y))


def main():
    shot, game = sys.argv[1], sys.argv[2]
    logo = ImageFont.truetype(os.path.join(game, 'assets/fonts/NiseSegaSonic.TTF'), 150)
    line1 = ImageFont.truetype(SANS, 40)
    line2 = ImageFont.truetype(SANS, 30)
    base = shade(background(shot))
    for lang, (first, second) in TEXTS.items():
        img = base.copy()
        sonics(img)
        draw = ImageDraw.Draw(img)
        outlined(draw, (W / 2 + 6, 112), 'SONIC PHP', logo, (10, 24, 80), (10, 24, 80), 10)
        outlined(draw, (W / 2, 104), 'SONIC PHP', logo, (255, 212, 0), (20, 64, 200), 8)
        outlined(draw, (W / 2, 218), first, line1, (255, 255, 255), (8, 20, 70), 5)
        outlined(draw, (W / 2, 268), second, line2, (255, 242, 122), (8, 20, 70), 5)
        outlined(draw, (W - 24, H - 24), 'sonic.mimichat.fr', line2, (255, 255, 255), (8, 20, 70), 5, 'rd')
        img.convert('RGB').save(os.path.join(ROOT, f'public/img/og-{lang}.png'), optimize=True)


if __name__ == '__main__':
    main()
