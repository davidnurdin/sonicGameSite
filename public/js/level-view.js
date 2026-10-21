// A level drawn like the game draws it: blocks of 32 pixels from the sheet of its set of tiles (16 a row), the
// objects, Sonic at his start. Only the visible part is drawn, in a canvas that stays in view inside a scrolled
// box (native scroll: wheel, touch, scrollbars). Used by the level page (just looking) and the level editor.
window.LevelView = (() => {
    'use strict';

    const SIZE = 32;
    const COLUMNS = 16;
    const FAMILY_COLOURS = { monitor: '#3a8dff', item: '#ffd400', enemy: '#ff5a5a', platform: '#c87420', hazard: '#ff8c00', other: '#a070ff' };
    // what is written on an object (the others: the first letters of their name)
    const MARKS = { 1: '10', 2: '>>', 3: '1UP', 4: 'O', 5: '*', 6: '<>', 7: 'FIN', 80: 'Y', 81: '->', 82: 'C' };

    const loadImage = (src) => new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = src;
    });

    // a collision (32 rows of 8 hex digits, bit 31 = the left pixel): a 32x32 canvas, the solid pixels in red
    const maskCanvas = (rows) => {
        const c = document.createElement('canvas');
        c.width = c.height = SIZE;
        const g = c.getContext('2d');
        const data = g.createImageData(SIZE, SIZE);
        rows.forEach((hex, y) => {
            const bits = parseInt(hex, 16);
            for (let x = 0; x < SIZE; x++) {
                if ((bits >>> (31 - x)) & 1) data.data.set([255, 40, 60, 150], (y * SIZE + x) * 4);
            }
        });
        g.putImageData(data, 0, 0);
        return c;
    };
    // the 47 collision shapes of the game
    let shapesPromise = null;
    const loadShapes = (url) => shapesPromise ??= fetch(url).then((r) => r.json()).then((list) => list.map(maskCanvas));
    // the collisions drawn in the tile editor (Tileset::$masks): block => canvas
    const maskCanvases = (masks) => Object.fromEntries(Object.entries(masks || {}).filter(([, m]) => m).map(([n, m]) => [n, maskCanvas(m.r)]));

    const decodeBlocks = (base64) => Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    const encodeBlocks = (bytes) => {
        let s = '';
        for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
        return btoa(s);
    };

    // the objects' pictures, taken from the game (tools/object-icons.py): {img, index: {type: [x, y, w, h, dx, dy]}}
    let iconsPromise = null;
    const loadIcons = (json, image) => iconsPromise ??= Promise.all([fetch(json).then((r) => r.json()), loadImage(image)])
        .then(([index, img]) => ({ img, index }));

    const objectMark = (type, objects) => MARKS[type] || String((objects[type] || ['?'])[0]).replace(/[^A-Za-z]/g, '').slice(0, 2).toUpperCase();

    class View {
        // box: the scrolled element; it gets the canvas and a sizer (the size of the whole level)
        constructor(box, options = {}) {
            this.box = box;
            this.options = options;
            this.canvas = document.createElement('canvas');
            this.canvas.className = 'lv-canvas';
            this.sizer = document.createElement('div');
            this.sizer.className = 'lv-sizer';
            box.append(this.canvas, this.sizer);
            this.ctx = this.canvas.getContext('2d');
            this.zoom = options.zoom || 1;
            this.grid = false;
            this.collisions = false;
            this.level = null;
            this.sheet = null;
            this.solidity = [];
            this.masks = {};
            this.shapes = null;
            this.objects = options.objects || {};
            this.hover = null;         // the editor: the block under the mouse, a rectangle being drawn
            this.sonic = null;
            this.icons = null;
            this.pending = false;
            box.addEventListener('scroll', () => this.draw(), { passive: true });
            new ResizeObserver(() => this.resize()).observe(box);
            if (options.sonic) loadImage(options.sonic).then((img) => { this.sonic = img; this.draw(); });
            if (options.shapes) loadShapes(options.shapes).then((s) => { this.shapes = s; this.draw(); });
            if (options.icons) loadIcons(options.icons, options.iconsImage).then((i) => { this.icons = i; this.draw(); }, () => {});
        }

        setLevel(level) {
            this.level = level;
            this.sizer.style.width = `${level.width * SIZE * this.zoom}px`;
            this.sizer.style.height = `${level.height * SIZE * this.zoom}px`;
            this.draw();
        }

        async setTileset(tileset) {
            this.sheet = await loadImage(tileset.image);
            this.solidity = tileset.solidity;
            this.masks = maskCanvases(tileset.masks);
            this.draw();
        }

        // keeps the point under (clientX, clientY), or the centre, where it is
        setZoom(zoom, clientX, clientY) {
            const rect = this.box.getBoundingClientRect();
            const ax = clientX === undefined ? rect.width / 2 : clientX - rect.left;
            const ay = clientY === undefined ? rect.height / 2 : clientY - rect.top;
            const wx = (this.box.scrollLeft + ax) / this.zoom;
            const wy = (this.box.scrollTop + ay) / this.zoom;
            this.zoom = zoom;
            if (this.level) this.setLevel(this.level);
            this.box.scrollLeft = wx * zoom - ax;
            this.box.scrollTop = wy * zoom - ay;
            this.draw();
        }

        resize() {
            const dpr = window.devicePixelRatio || 1;
            const w = this.box.clientWidth;
            const h = this.box.clientHeight;
            this.canvas.style.width = `${w}px`;
            this.canvas.style.height = `${h}px`;
            this.canvas.width = Math.round(w * dpr);
            this.canvas.height = Math.round(h * dpr);
            this.draw();
        }

        // the level pixel under a point of the page
        pixelAt(clientX, clientY) {
            const rect = this.box.getBoundingClientRect();
            return {
                x: (clientX - rect.left + this.box.scrollLeft) / this.zoom,
                y: (clientY - rect.top + this.box.scrollTop) / this.zoom,
            };
        }

        blockAt(clientX, clientY) {
            const p = this.pixelAt(clientX, clientY);
            const x = Math.floor(p.x / SIZE);
            const y = Math.floor(p.y / SIZE);
            return this.level && x >= 0 && y >= 0 && x < this.level.width && y < this.level.height ? { x, y } : null;
        }

        draw() {
            if (this.pending) return;
            this.pending = true;
            requestAnimationFrame(() => { this.pending = false; this.render(); });
        }

        render() {
            const { ctx, level } = this;
            const dpr = this.canvas.width / Math.max(1, this.box.clientWidth);
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.fillStyle = '#0a1440';
            ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
            if (!level) return;
            const scale = this.zoom * dpr;
            const left = this.box.scrollLeft / this.zoom;
            const top = this.box.scrollTop / this.zoom;
            ctx.setTransform(scale, 0, 0, scale, -left * scale, -top * scale);
            ctx.imageSmoothingEnabled = false;
            const x0 = Math.max(0, Math.floor(left / SIZE));
            const y0 = Math.max(0, Math.floor(top / SIZE));
            const x1 = Math.min(level.width, Math.ceil((left + this.box.clientWidth / this.zoom) / SIZE) + 1);
            const y1 = Math.min(level.height, Math.ceil((top + this.box.clientHeight / this.zoom) / SIZE) + 1);
            // outside the level
            ctx.fillStyle = '#05091f';
            ctx.fillRect(level.width * SIZE, 0, 99999, 99999);
            ctx.fillRect(0, level.height * SIZE, 99999, 99999);
            for (let y = y0; y < y1; y++) {
                for (let x = x0; x < x1; x++) {
                    const block = level.blocks[y * level.width + x];
                    if (this.sheet) {
                        ctx.drawImage(this.sheet, (block % COLUMNS) * SIZE, Math.floor(block / COLUMNS) * SIZE, SIZE, SIZE, x * SIZE, y * SIZE, SIZE, SIZE);
                    }
                    if (this.collisions) {
                        const value = this.solidity[block] || 0;
                        const shape = this.masks[block] || (this.shapes && this.shapes[value & 0x3f]);
                        if (shape) ctx.drawImage(shape, x * SIZE, y * SIZE);
                        if (value & 0x80) {
                            ctx.fillStyle = '#ffd400';
                            ctx.fillRect(x * SIZE + 26, y * SIZE + 1, 5, 5);
                        }
                    }
                }
            }
            if (this.grid && this.zoom >= 0.5) {
                ctx.strokeStyle = 'rgba(255,255,255,.18)';
                ctx.lineWidth = 1 / scale;
                ctx.beginPath();
                for (let x = x0; x <= x1; x++) { ctx.moveTo(x * SIZE, y0 * SIZE); ctx.lineTo(x * SIZE, y1 * SIZE); }
                for (let y = y0; y <= y1; y++) { ctx.moveTo(x0 * SIZE, y * SIZE); ctx.lineTo(x1 * SIZE, y * SIZE); }
                ctx.stroke();
            }
            this.renderObjects(ctx, x0, y0, x1, y1);
            if (this.sonic && level.start) {
                // the first picture of public/img/sonics.png (23x32, blue Sonic), where the game puts him
                ctx.drawImage(this.sonic, 0, 0, 23, 32, level.start[0] * SIZE + 4, level.start[1] * SIZE, 23, 32);
            }
            if (this.hover) this.renderHover(ctx, scale);
        }

        renderObjects(ctx, x0, y0, x1, y1) {
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = 'bold 11px system-ui, sans-serif';
            for (const [type, x, y] of this.level.objects) {
                if (x < x0 - 2 || y < y0 - 2 || x > x1 + 1 || y > y1 + 1) continue;
                const icon = this.icons?.index[type];
                if (icon) {
                    const [sx, sy, w, h, dx, dy] = icon;
                    ctx.drawImage(this.icons.img, sx, sy, w, h, x * SIZE + dx, y * SIZE + dy, w, h);
                    continue;
                }
                const family = (this.objects[type] || [])[1] || 'other';
                ctx.fillStyle = FAMILY_COLOURS[family];
                ctx.strokeStyle = '#0a1440';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.roundRect(x * SIZE + 3, y * SIZE + 3, SIZE - 6, SIZE - 6, 6);
                ctx.fill();
                ctx.stroke();
                ctx.fillStyle = family === 'item' ? '#10183a' : '#fff';
                ctx.fillText(objectMark(type, this.objects), x * SIZE + SIZE / 2, y * SIZE + SIZE / 2 + 1);
            }
        }

        renderHover(ctx, scale) {
            const { x, y, w = 1, h = 1, colour = '#ffd400' } = this.hover;
            ctx.strokeStyle = colour;
            ctx.lineWidth = 2 / scale;
            ctx.strokeRect(x * SIZE, y * SIZE, w * SIZE, h * SIZE);
        }

        // the level around Sonic's start (40x20 blocks at most), small (480x240 at most): lists, links
        thumbnail() {
            const { level } = this;
            const w = Math.min(level.width, 40);
            const h = Math.min(level.height, 20);
            const clamp = (v, max) => Math.max(0, Math.min(max, Math.round(v)));
            const x0 = clamp(level.start[0] - w / 3, level.width - w);
            const y0 = clamp(level.start[1] - h * 0.6, level.height - h);
            const ratio = Math.min(480 / (w * SIZE), 240 / (h * SIZE), 1);
            const c = document.createElement('canvas');
            c.width = Math.max(1, Math.round(w * SIZE * ratio));
            c.height = Math.max(1, Math.round(h * SIZE * ratio));
            const g = c.getContext('2d');
            g.fillStyle = '#0a1440';
            g.fillRect(0, 0, c.width, c.height);
            const step = SIZE * ratio;
            for (let y = 0; y < h; y++) {
                for (let x = 0; x < w; x++) {
                    const block = level.blocks[(y0 + y) * level.width + x0 + x];
                    g.drawImage(this.sheet, (block % COLUMNS) * SIZE, Math.floor(block / COLUMNS) * SIZE, SIZE, SIZE,
                        x * step, y * step, step + 0.5, step + 0.5);
                }
            }
            if (this.sonic) g.drawImage(this.sonic, 0, 0, 23, 32, (level.start[0] - x0) * step + 4 * ratio, (level.start[1] - y0) * step, 23 * ratio, 32 * ratio);
            return c.toDataURL('image/png').split(',')[1];
        }
    }

    // the level page: just looking (drag with the mouse to move, zoom buttons)
    function viewer(root) {
        const box = root.querySelector('.lv-box');
        const view = new View(box, { sonic: root.dataset.sonic, shapes: root.dataset.shapes, icons: root.dataset.icons,
            iconsImage: root.dataset.iconsImage, objects: JSON.parse(root.dataset.objects || '{}') });
        fetch(root.dataset.json).then((r) => r.json()).then(async (data) => {
            await view.setTileset(data.tileset);
            view.setLevel({ ...data, blocks: decodeBlocks(data.blocks) });
            // the start of the level in view
            box.scrollTop = Math.max(0, data.start[1] * SIZE * view.zoom - box.clientHeight / 2);
            box.scrollLeft = Math.max(0, data.start[0] * SIZE * view.zoom - box.clientWidth / 3);
        });
        let drag = null;
        box.addEventListener('pointerdown', (e) => {
            if (e.pointerType !== 'mouse') return;
            drag = { x: e.clientX, y: e.clientY, left: box.scrollLeft, top: box.scrollTop };
            box.setPointerCapture(e.pointerId);
        });
        box.addEventListener('pointermove', (e) => {
            if (!drag) return;
            box.scrollLeft = drag.left - (e.clientX - drag.x);
            box.scrollTop = drag.top - (e.clientY - drag.y);
        });
        box.addEventListener('pointerup', () => { drag = null; });
        root.querySelectorAll('[data-zoom]').forEach((b) => b.addEventListener('click', () => {
            view.setZoom(Math.min(2, Math.max(0.25, view.zoom * Number(b.dataset.zoom))));
        }));
        root.querySelector('[data-toggle="collisions"]')?.addEventListener('change', (e) => { view.collisions = e.target.checked; view.draw(); });
    }

    document.querySelectorAll('[data-level-viewer]').forEach(viewer);

    return { View, SIZE, COLUMNS, loadImage, loadShapes, loadIcons, maskCanvas, decodeBlocks, encodeBlocks, objectMark, FAMILY_COLOURS };
})();
