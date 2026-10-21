// The tile editor (/tiles/{id}/editer): the blocks of a set (32x32 pixels, 16 a row in one PNG, up to 256) and
// the collision of each (one of the game's 47 shapes, or drawn: the masks, "in front of Sonic"). Draws pixel by pixel, imports a
// PNG or the blocks of another set. Saves with POST /tiles/{id} (JSON, see EditorData::blocks).
(() => {
    'use strict';

    const root = document.getElementById('tile-editor');
    if (!root) return;
    const { SIZE, COLUMNS, loadImage, loadShapes, maskCanvas } = window.LevelView;
    const MAX = 256;
    const T = JSON.parse(root.dataset.i18n);
    const $ = (sel) => root.querySelector(sel);

    // all the blocks, 16 a row (always 16 rows: room for 256)
    const sheet = document.createElement('canvas');
    sheet.width = COLUMNS * SIZE;
    sheet.height = (MAX / COLUMNS) * SIZE;
    const sheetCtx = sheet.getContext('2d', { willReadFrequently: true });
    let solidity = [];
    let behaviour = [];      // what each block does (springs, spikes...: the game's CollisionSystem::B_*)
    let masks = {};          // collisions drawn instead of a shape: block => {px: 1024 pixels (1 = solid), w: 1 floor, 2 walls, 4 ceiling}
    let animations = {};     // animated blocks in the game: block => {f: the blocks shown in turn, t: frames (60 a second) each}
    let current = 0;
    let colour = [255, 255, 255, 255];
    let tool = 'pencil';
    let dirty = false;
    let saving = false;
    let shapes = [];
    const undo = [];
    const redo = [];

    const status = (text, kind = '') => { const el = $('.ed-status'); el.textContent = text; el.dataset.kind = kind; };
    const changed = () => { dirty = true; status(T.unsaved, 'warn'); };
    const at = (n) => [(n % COLUMNS) * SIZE, Math.floor(n / COLUMNS) * SIZE];
    const getBlock = (n) => sheetCtx.getImageData(...at(n), SIZE, SIZE);
    const putBlock = (n, data) => sheetCtx.putImageData(data, ...at(n));

    // ---- undo: the blocks an action changes, and the collisions

    const remember = (blocks = [current]) => {
        undo.push({ solidity: [...solidity], behaviour: [...behaviour], masks: structuredClone(masks), animations: structuredClone(animations), current, blocks: blocks.map((n) => [n, getBlock(n)]) });
        if (undo.length > 100) undo.shift();
        redo.length = 0;
        changed();
    };
    const restore = (from, to) => {
        if (!from.length) return;
        const step = from.pop();
        to.push({ solidity: [...solidity], behaviour: [...behaviour], masks: structuredClone(masks), animations: structuredClone(animations), current, blocks: step.blocks.map(([n]) => [n, getBlock(n)]) });
        solidity = step.solidity;
        behaviour = step.behaviour;
        masks = step.masks;
        animations = step.animations;
        for (const [n, data] of step.blocks) putBlock(n, data);
        current = Math.min(step.current, solidity.length - 1);
        changed();
        refresh();
    };

    // ---- the list of blocks

    const list = $('.te-sheet');
    const listCtx = list.getContext('2d');
    function drawList() {
        const rows = Math.ceil(solidity.length / COLUMNS);
        list.width = sheet.width;
        list.height = rows * SIZE;
        listCtx.fillStyle = '#0a1440';
        listCtx.fillRect(0, 0, list.width, list.height);
        listCtx.drawImage(sheet, 0, 0, sheet.width, rows * SIZE, 0, 0, sheet.width, rows * SIZE);
        // after the last block
        const last = solidity.length;
        listCtx.fillStyle = '#05091f';
        for (let n = last; n < rows * COLUMNS; n++) listCtx.fillRect(...at(n), SIZE, SIZE);
        // the animated blocks: a corner
        listCtx.fillStyle = '#3cf';
        for (const n of Object.keys(animations)) {
            const [x, y] = at(Number(n));
            listCtx.beginPath(); listCtx.moveTo(x + SIZE - 10, y); listCtx.lineTo(x + SIZE, y); listCtx.lineTo(x + SIZE, y + 10); listCtx.fill();
        }
        listCtx.strokeStyle = '#ffd400';
        listCtx.lineWidth = 2;
        listCtx.strokeRect(at(current)[0] + 1, at(current)[1] + 1, SIZE - 2, SIZE - 2);
        // the source of the clone, in whichever block it is
        const source = cloneSource();
        if (source) cloneFrame(listCtx, source, 1);
        $('.te-count').textContent = `${solidity.length} / ${MAX}`;
    }
    list.addEventListener('pointerdown', (e) => {
        const rect = list.getBoundingClientRect();
        const scale = list.width / rect.width;
        // the clone's source in another block: Alt+click on it here
        if (tool === 'clone' && e.altKey) {
            const x = Math.floor((e.clientX - rect.left) * scale), y = Math.floor((e.clientY - rect.top) * scale);
            if (Math.floor(y / SIZE) * COLUMNS + Math.floor(x / SIZE) < solidity.length) { clone.source = [x, y]; clone.offset = null; refresh(); }
            return;
        }
        const n = Math.floor((e.clientY - rect.top) * scale / SIZE) * COLUMNS + Math.floor((e.clientX - rect.left) * scale / SIZE);
        if (n >= solidity.length) return;
        // Shift+click: one more picture for the animation of the block being drawn
        if (e.shiftKey) { setAnimation([...(animations[current]?.f || [current]), n]); return; }
        current = n;
        refresh();
    });

    // ---- the block being drawn, big

    const PIXEL = 14;
    const big = $('.te-pixels');
    big.width = big.height = SIZE * PIXEL;
    const bigCtx = big.getContext('2d');
    const preview = $('.te-preview');
    const previewCtx = preview.getContext('2d');
    function drawBig() {
        bigCtx.imageSmoothingEnabled = false;
        // transparent: a checkerboard
        for (let y = 0; y < SIZE; y += 2) for (let x = 0; x < SIZE; x += 2) {
            bigCtx.fillStyle = (x + y) % 4 ? '#2a3466' : '#1d2550';
            bigCtx.fillRect(x * PIXEL, y * PIXEL, PIXEL * 2, PIXEL * 2);
        }
        bigCtx.drawImage(sheet, ...at(current), SIZE, SIZE, 0, 0, SIZE * PIXEL, SIZE * PIXEL);
        const shape = masks[current] ? maskCanvas(toHex(masks[current].px)) : shapes[solidity[current] & 0x3f];
        if ((paintMask.checked || $('[data-toggle="shape"]').checked) && shape) {
            bigCtx.globalAlpha = 0.9;
            bigCtx.drawImage(shape, 0, 0, SIZE * PIXEL, SIZE * PIXEL);
            bigCtx.globalAlpha = 1;
        }
        bigCtx.strokeStyle = 'rgba(255,255,255,.08)';
        bigCtx.beginPath();
        for (let i = 1; i < SIZE; i++) {
            bigCtx.moveTo(i * PIXEL + 0.5, 0); bigCtx.lineTo(i * PIXEL + 0.5, SIZE * PIXEL);
            bigCtx.moveTo(0, i * PIXEL + 0.5); bigCtx.lineTo(SIZE * PIXEL, i * PIXEL + 0.5);
        }
        bigCtx.stroke();
        // the clone: the brush, and its source when it is in this block
        if (tool === 'clone') {
            const [bx, by] = at(current);
            const source = cloneSource();
            if (source) cloneFrame(bigCtx, [source[0] - bx, source[1] - by, source[2]], PIXEL);
            if (clone.hover) {
                const [x, y, n] = brushAt(clone.hover);
                bigCtx.strokeStyle = 'rgba(255, 255, 255, .9)';
                bigCtx.lineWidth = 2;
                bigCtx.strokeRect((x - bx) * PIXEL + 1, (y - by) * PIXEL + 1, n * PIXEL - 2, n * PIXEL - 2);
            }
        }
        // 3x3 of the block: do the sides join?
        previewCtx.imageSmoothingEnabled = false;
        previewCtx.fillStyle = '#0a1440';
        previewCtx.fillRect(0, 0, preview.width, preview.height);
        for (let y = 0; y < 3; y++) for (let x = 0; x < 3; x++) {
            previewCtx.drawImage(sheet, ...at(current), SIZE, SIZE, x * SIZE * 2, y * SIZE * 2, SIZE * 2, SIZE * 2);
        }
        $('.te-current').textContent = `${T.block} ${current}`;
    }

    let stroke = null;
    const pixelAt = (e) => {
        const rect = big.getBoundingClientRect();
        const x = Math.floor((e.clientX - rect.left) / rect.width * SIZE);
        const y = Math.floor((e.clientY - rect.top) / rect.height * SIZE);
        return x >= 0 && y >= 0 && x < SIZE && y < SIZE ? { x, y } : null;
    };
    big.addEventListener('contextmenu', (e) => e.preventDefault());
    big.addEventListener('pointerdown', (e) => {
        const p = pixelAt(e);
        if (!p) return;
        big.setPointerCapture(e.pointerId);
        if (tool === 'clone' && !paintMask.checked) {
            const g = global(p);
            if (e.altKey || e.button === 2) { clone.source = g; clone.offset = null; refresh(); return; }
            if (!clone.source) { status(T.cloneFirst, 'warn'); return; }
            remember();
            if (!clone.offset || !$('[name="clone-aligned"]').checked) clone.offset = [clone.source[0] - g[0], clone.source[1] - g[1]];
            clone.from = sheetCtx.getImageData(0, 0, sheet.width, sheet.height);
            stroke = { clone: true, last: g };
            stamp(g);
            return;
        }
        if (e.button === 2 || tool === 'picker') { if (!paintMask.checked) pick(p); return; }
        stroke = { remembered: false, last: null };
        paint(p);
    });
    big.addEventListener('pointermove', (e) => {
        const p = pixelAt(e);
        // the source follows the brush: in this block and in the list (another block)
        if (tool === 'clone') { clone.hover = p ? global(p) : null; drawBig(); drawList(); }
        if (!stroke || !p) return;
        if (stroke.clone) {
            const g = global(p);
            const steps = Math.max(Math.abs(g[0] - stroke.last[0]), Math.abs(g[1] - stroke.last[1]), 1);
            for (let i = 1; i <= steps; i++) {
                stamp([Math.round(stroke.last[0] + (g[0] - stroke.last[0]) * i / steps), Math.round(stroke.last[1] + (g[1] - stroke.last[1]) * i / steps)]);
            }
            stroke.last = g;
            return;
        }
        paint(p);
    });
    big.addEventListener('pointerleave', () => { clone.hover = null; drawBig(); drawList(); });
    big.addEventListener('pointerup', () => { stroke = null; drawList(); });

    // ---- clone (K): Alt+click the source (this block or another, in the list), then paint the block: the pixels at
    // the same distance are copied, from the blocks as they were when the stroke began. In sheet pixels.

    const clone = { source: null, offset: null, from: null, hover: null };
    const global = (p) => [at(current)[0] + p.x, at(current)[1] + p.y];
    const cloneSize = () => Number($('[name="clone-size"]').value) || 4;
    const brushAt = ([x, y]) => { const n = cloneSize(); return [x - Math.floor(n / 2), y - Math.floor(n / 2), n]; };
    // where the source is now: following the brush once a stroke set the distance
    const cloneSource = () => {
        if (tool !== 'clone') return null;
        if (clone.hover && clone.offset) return brushAt([clone.hover[0] + clone.offset[0], clone.hover[1] + clone.offset[1]]);
        return clone.source ? brushAt(clone.source) : null;
    };
    function cloneFrame(ctx, [x, y, n], scale) {
        ctx.save();
        ctx.setLineDash([6, 4]);
        ctx.strokeStyle = '#3fd0ff';
        ctx.lineWidth = 2;
        ctx.strokeRect(x * scale, y * scale, n * scale, n * scale);
        ctx.setLineDash([]);
        const cx = (x + n / 2) * scale, cy = (y + n / 2) * scale;
        ctx.beginPath();
        ctx.moveTo(cx - 6, cy); ctx.lineTo(cx + 6, cy); ctx.moveTo(cx, cy - 6); ctx.lineTo(cx, cy + 6);
        ctx.stroke();
        ctx.restore();
    }
    // the brush at g (sheet pixels), inside the block being drawn only
    function stamp(g) {
        const [bx, by] = at(current);
        const data = getBlock(current);
        const from = clone.from.data;
        const [x0, y0, n] = brushAt(g);
        for (let y = Math.max(y0, by); y < Math.min(y0 + n, by + SIZE); y++) {
            for (let x = Math.max(x0, bx); x < Math.min(x0 + n, bx + SIZE); x++) {
                const sx = x + clone.offset[0], sy = y + clone.offset[1];
                if (sx < 0 || sy < 0 || sx >= sheet.width || sy >= sheet.height) continue;
                const i = (sy * sheet.width + sx) * 4;
                data.data.set(from.subarray(i, i + 4), ((y - by) * SIZE + (x - bx)) * 4);
            }
        }
        putBlock(current, data);
        drawBig();
    }

    function pick(p) {
        const d = sheetCtx.getImageData(at(current)[0] + p.x, at(current)[1] + p.y, 1, 1).data;
        if (d[3] === 0) return;
        setColour([d[0], d[1], d[2], 255]);
    }

    function paint(p) {
        const from = stroke.last || p;
        stroke.last = p;
        if (!stroke.remembered) { stroke.remembered = true; remember(); }
        if (paintMask.checked) { paintCollision(from, p); return; }
        const data = getBlock(current);
        const value = tool === 'eraser' ? [0, 0, 0, 0] : colour;
        if (tool === 'fill') {
            floodFill(data, p.x, p.y, value);
            stroke = null;
        } else {
            const steps = Math.max(Math.abs(p.x - from.x), Math.abs(p.y - from.y), 1);
            for (let i = 0; i <= steps; i++) {
                const x = Math.round(from.x + (p.x - from.x) * i / steps);
                const y = Math.round(from.y + (p.y - from.y) * i / steps);
                data.data.set(value, (y * SIZE + x) * 4);
            }
        }
        putBlock(current, data);
        drawBig();
        if (!stroke) drawList();
    }

    // ---- the collision drawn (masks): the pencil and the fill make solid pixels, the eraser takes them away

    const toHex = (px) => Array.from({ length: SIZE }, (_, y) => {
        let bits = 0;
        for (let x = 0; x < SIZE; x++) if (px[y * SIZE + x]) bits = (bits | (1 << (31 - x))) >>> 0;
        return bits.toString(16).padStart(8, '0');
    });
    const fromHex = (rows) => {
        const px = new Uint8Array(SIZE * SIZE);
        rows.forEach((hex, y) => { const bits = parseInt(hex, 16); for (let x = 0; x < SIZE; x++) px[y * SIZE + x] = (bits >>> (31 - x)) & 1; });
        return px;
    };
    // the sides of a shape of the game (Level::applySolidity of the game)
    const shapeWays = (v) => {
        v &= 0x3f;
        return (v >= 0x18 && v <= 0x26 ? 0 : 1) | (v === 22 || v === 30 ? 2 : 0) | (v === 0x16 || (v >= 0x18 && v <= 0x28) ? 4 : 0);
    };
    // drawing starts from the block's shape
    const ownMask = () => {
        if (!masks[current]) {
            const shape = shapes[solidity[current] & 0x3f];
            const d = shape.getContext('2d').getImageData(0, 0, SIZE, SIZE).data;
            masks[current] = { px: Uint8Array.from({ length: SIZE * SIZE }, (_, i) => d[i * 4 + 3] ? 1 : 0), w: shapeWays(solidity[current]) };
        }
        return masks[current];
    };
    function paintCollision(from, p) {
        const px = ownMask().px;
        const value = tool === 'eraser' ? 0 : 1;
        if (tool === 'fill') {
            const old = px[p.y * SIZE + p.x];
            const stack = [[p.x, p.y]];
            if (old !== value) while (stack.length) {
                const [x, y] = stack.pop();
                if (x < 0 || y < 0 || x >= SIZE || y >= SIZE || px[y * SIZE + x] !== old) continue;
                px[y * SIZE + x] = value;
                stack.push([x + 1, y], [x - 1, y], [x, y + 1], [x, y - 1]);
            }
            stroke = null;
        } else {
            const steps = Math.max(Math.abs(p.x - from.x), Math.abs(p.y - from.y), 1);
            for (let i = 0; i <= steps; i++) {
                px[Math.round(from.y + (p.y - from.y) * i / steps) * SIZE + Math.round(from.x + (p.x - from.x) * i / steps)] = value;
            }
        }
        refresh();
    }

    function floodFill(data, x0, y0, value) {
        const d = data.data;
        const old = d.slice((y0 * SIZE + x0) * 4, (y0 * SIZE + x0) * 4 + 4);
        if (old.every((v, i) => v === value[i])) return;
        const same = (i) => d[i] === old[0] && d[i + 1] === old[1] && d[i + 2] === old[2] && d[i + 3] === old[3];
        const stack = [[x0, y0]];
        while (stack.length) {
            const [x, y] = stack.pop();
            if (x < 0 || y < 0 || x >= SIZE || y >= SIZE || !same((y * SIZE + x) * 4)) continue;
            d.set(value, (y * SIZE + x) * 4);
            stack.push([x + 1, y], [x - 1, y], [x, y + 1], [x, y - 1]);
        }
    }

    // ---- tools, colours

    function setTool(name) {
        tool = name;
        root.querySelectorAll('[data-tool]').forEach((b) => b.classList.toggle('on', b.dataset.tool === name));
        $('.ed-clone').hidden = name !== 'clone';
        if (solidity.length) refresh();
    }
    root.querySelectorAll('[data-tool]').forEach((b) => b.addEventListener('click', () => setTool(b.dataset.tool)));

    const hex = (c) => '#' + c.slice(0, 3).map((v) => v.toString(16).padStart(2, '0')).join('');
    const picker = $('[name="colour"]');
    function setColour(c) {
        colour = c;
        picker.value = hex(c);
        root.querySelectorAll('.te-swatch').forEach((s) => s.classList.toggle('on', s.dataset.hex === hex(c)));
        if (tool === 'eraser' || tool === 'picker') setTool('pencil');
    }
    picker.addEventListener('input', () => {
        const v = picker.value;
        setColour([parseInt(v.slice(1, 3), 16), parseInt(v.slice(3, 5), 16), parseInt(v.slice(5, 7), 16), 255]);
    });
    // the 64 colours of the Master System (2 bits a channel)
    const swatches = $('.te-swatches');
    const LEVELS = [0, 85, 170, 255];
    for (const r of LEVELS) for (const g of LEVELS) for (const b of LEVELS) {
        const s = document.createElement('button');
        s.type = 'button';
        s.className = 'te-swatch';
        s.dataset.hex = hex([r, g, b]);
        s.style.background = s.dataset.hex;
        s.title = s.dataset.hex;
        s.addEventListener('click', () => setColour([r, g, b, 255]));
        swatches.append(s);
    }

    // ---- collision of the block

    const shapeList = $('.te-shapes');
    function drawShapes() {
        shapeList.replaceChildren(...shapes.map((shape, i) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'te-shape';
            b.title = `${T.shape} ${i}`;
            const c = document.createElement('canvas');
            c.width = c.height = SIZE;
            const g = c.getContext('2d');
            g.fillStyle = '#1d2550';
            g.fillRect(0, 0, SIZE, SIZE);
            g.drawImage(shape, 0, 0);
            b.append(c);
            b.addEventListener('click', () => {
                remember([]);
                solidity[current] = (solidity[current] & 0x80) | i;
                delete masks[current];
                refresh();
            });
            return b;
        }));
    }
    const behaviourSelect = $('[name="behaviour"]');
    behaviourSelect.addEventListener('change', () => {
        remember([]);
        behaviour[current] = Number(behaviourSelect.value);
        refresh();
    });
    const front = $('[name="front"]');
    front.addEventListener('change', () => {
        remember([]);
        solidity[current] = (solidity[current] & 0x3f) | (front.checked ? 0x80 : 0);
        refresh();
    });
    // ---- the animation of a block: the blocks shown in turn in the game, each for ticks frames (60 a second)
    const animFrames = $('[name="anim-frames"]');
    const animTicks = $('[name="anim-ticks"]');
    function setAnimation(frames) {
        remember([]);
        frames = frames.filter((b) => Number.isInteger(b) && b >= 0 && b < solidity.length).slice(0, 16);
        const ticks = Math.min(240, Math.max(1, Math.round(Number(animTicks.value)) || 8));
        // not the ring blocks: the game draws the rings itself
        if (frames.length < 2 || (current >= 120 && current <= 123)) delete animations[current];
        else animations[current] = { f: frames, t: ticks };
        refresh();
    }
    animFrames.addEventListener('change', () => setAnimation(animFrames.value.split(/[^0-9]+/).filter(Boolean).map(Number)));
    animTicks.addEventListener('change', () => { if (animations[current]) setAnimation(animations[current].f); });
    $('.te-anim-next').addEventListener('click', () => {
        const count = Math.min(16, Number($('[name="anim-count"]').value) || 4);
        setAnimation(Array.from({ length: count }, (_, i) => current + i));
    });
    $('.te-anim-stop').addEventListener('click', () => setAnimation([]));
    // the preview: as the game shows it (the same clock for every block)
    const animCanvas = $('.te-anim');
    const animCtx = animCanvas.getContext('2d');
    animCtx.imageSmoothingEnabled = false;
    (function animate() {
        const a = animations[current];
        const frame = Math.floor(performance.now() * 60 / 1000);
        const shown = a ? a.f[Math.floor(frame / a.t) % a.f.length] : current;
        animCtx.fillStyle = '#0a1440';
        animCtx.fillRect(0, 0, animCanvas.width, animCanvas.height);
        for (let y = 0; y < animCanvas.height; y += SIZE * 2) for (let x = 0; x < animCanvas.width; x += SIZE * 2) {
            animCtx.drawImage(sheet, ...at(shown), SIZE, SIZE, x, y, SIZE * 2, SIZE * 2);
        }
        requestAnimationFrame(animate);
    })();
    $('[data-toggle="shape"]').addEventListener('change', drawBig);
    const paintMask = $('[name="paint-mask"]');
    paintMask.addEventListener('change', refresh);
    const WAYS = { floor: 1, walls: 2, ceiling: 4 };
    for (const [name, bit] of Object.entries(WAYS)) {
        $(`[name="w-${name}"]`).addEventListener('change', (e) => {
            remember([]);
            const mask = ownMask();
            mask.w = e.target.checked ? mask.w | bit : mask.w & ~bit;
            refresh();
        });
    }
    $('.te-mask-reset').addEventListener('click', () => { remember([]); delete masks[current]; refresh(); });

    // ---- blocks: add, copy, empty, the last one away, flip, move

    const empty = () => new ImageData(SIZE, SIZE);
    $('.te-add').addEventListener('click', () => {
        if (solidity.length >= MAX) return;
        remember([solidity.length]);
        putBlock(solidity.length, empty());
        solidity.push(0);
        behaviour[solidity.length - 1] = 0;
        current = solidity.length - 1;
        refresh();
    });
    $('.te-duplicate').addEventListener('click', () => {
        if (solidity.length >= MAX) return;
        remember([solidity.length]);
        putBlock(solidity.length, getBlock(current));
        solidity.push(solidity[current]);
        behaviour[solidity.length - 1] = behaviour[current] || 0;
        if (masks[current]) masks[solidity.length - 1] = structuredClone(masks[current]);
        current = solidity.length - 1;
        refresh();
    });
    $('.te-clear').addEventListener('click', () => { remember(); putBlock(current, empty()); refresh(); });
    $('.te-remove').addEventListener('click', () => {
        // only the last one: the levels use the others by their number
        if (solidity.length <= 1) return;
        remember([solidity.length - 1]);
        putBlock(solidity.length - 1, empty());
        solidity.pop();
        behaviour.length = Math.min(behaviour.length, solidity.length);
        delete masks[solidity.length];
        // the animations showing it lose that picture
        for (const [n, a] of Object.entries(animations)) {
            const f = a.f.filter((b) => b < solidity.length);
            if (n >= solidity.length || f.length < 2) delete animations[n]; else a.f = f;
        }
        current = Math.min(current, solidity.length - 1);
        refresh();
    });
    const transform = (fn) => {
        remember();
        const src = getBlock(current);
        const out = empty();
        for (let y = 0; y < SIZE; y++) for (let x = 0; x < SIZE; x++) {
            const [sx, sy] = fn(x, y);
            out.data.set(src.data.subarray((sy * SIZE + sx) * 4, (sy * SIZE + sx) * 4 + 4), (y * SIZE + x) * 4);
        }
        putBlock(current, out);
        if (masks[current]) {
            const px = masks[current].px;
            masks[current].px = Uint8Array.from({ length: SIZE * SIZE }, (_, i) => { const [sx, sy] = fn(i % SIZE, Math.floor(i / SIZE)); return px[sy * SIZE + sx]; });
        }
        refresh();
    };
    $('.te-flip-h').addEventListener('click', () => transform((x, y) => [SIZE - 1 - x, y]));
    $('.te-flip-v').addEventListener('click', () => transform((x, y) => [x, SIZE - 1 - y]));
    $('.te-rotate').addEventListener('click', () => transform((x, y) => [y, SIZE - 1 - x]));
    let clipboard = null;
    $('.te-copy').addEventListener('click', () => { clipboard = { data: getBlock(current), solidity: solidity[current], behaviour: behaviour[current] || 0, mask: structuredClone(masks[current]) }; status(T.copied); });
    $('.te-paste').addEventListener('click', () => {
        if (!clipboard) return;
        remember();
        putBlock(current, clipboard.data);
        solidity[current] = clipboard.solidity;
        behaviour[current] = clipboard.behaviour;
        if (clipboard.mask) masks[current] = structuredClone(clipboard.mask); else delete masks[current];
        refresh();
    });

    // ---- import: a PNG (cut in blocks), or blocks of another set

    $('[name="import"]').addEventListener('change', async (e) => {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;
        const img = await loadImage(URL.createObjectURL(file));
        const cols = Math.floor(img.width / SIZE);
        const rows = Math.floor(img.height / SIZE);
        const room = MAX - solidity.length;
        const n = Math.min(cols * rows, room);
        if (!n) { status(T.full, 'error'); return; }
        const added = Array.from({ length: n }, (_, i) => solidity.length + i);
        remember(added);
        const tmp = document.createElement('canvas');
        tmp.width = tmp.height = SIZE;
        const g = tmp.getContext('2d', { willReadFrequently: true });
        added.forEach((target, i) => {
            g.clearRect(0, 0, SIZE, SIZE);
            g.drawImage(img, (i % cols) * SIZE, Math.floor(i / cols) * SIZE, SIZE, SIZE, 0, 0, SIZE, SIZE);
            putBlock(target, g.getImageData(0, 0, SIZE, SIZE));
            solidity.push(0);
            behaviour[solidity.length - 1] = 0;
        });
        current = added[0];
        status(T.imported.replace('%n%', n), 'warn');
        refresh();
    });

    const other = $('.te-other');
    const otherCtx = other.getContext('2d');
    let otherSet = null;
    $('[name="other"]').addEventListener('change', async (e) => {
        otherSet = null;
        other.hidden = !e.target.value;
        if (!e.target.value) return;
        const data = await fetch(root.dataset.tilesetJson.replace('ID', e.target.value)).then((r) => r.json());
        const img = await loadImage(data.image);
        otherSet = { img, solidity: data.solidity, behaviour: data.behaviour || [], masks: data.masks || {} };
        other.width = img.width;
        other.height = img.height;
        otherCtx.fillStyle = '#0a1440';
        otherCtx.fillRect(0, 0, other.width, other.height);
        otherCtx.drawImage(img, 0, 0);
    });
    other.addEventListener('pointerdown', (e) => {
        if (!otherSet || solidity.length >= MAX) return;
        const rect = other.getBoundingClientRect();
        const scale = other.width / rect.width;
        const n = Math.floor((e.clientY - rect.top) * scale / SIZE) * COLUMNS + Math.floor((e.clientX - rect.left) * scale / SIZE);
        if (n >= otherSet.solidity.length) return;
        remember([solidity.length]);
        const tmp = document.createElement('canvas');
        tmp.width = tmp.height = SIZE;
        const g = tmp.getContext('2d', { willReadFrequently: true });
        g.drawImage(otherSet.img, ...at(n), SIZE, SIZE, 0, 0, SIZE, SIZE);
        putBlock(solidity.length, g.getImageData(0, 0, SIZE, SIZE));
        solidity.push(otherSet.solidity[n]);
        behaviour[solidity.length - 1] = otherSet.behaviour[n] || 0;
        const mask = otherSet.masks[n];
        if (mask) masks[solidity.length - 1] = { px: fromHex(mask.r), w: mask.w };
        current = solidity.length - 1;
        refresh();
    });

    function refresh() {
        drawList();
        drawBig();
        const mask = masks[current];
        shapeList.querySelectorAll('.te-shape').forEach((b, i) => b.classList.toggle('on', !mask && i === (solidity[current] & 0x3f)));
        $('.te-mask').hidden = !mask;
        const w = mask ? mask.w : shapeWays(solidity[current]);
        for (const [name, bit] of Object.entries(WAYS)) $(`[name="w-${name}"]`).checked = (w & bit) !== 0;
        front.checked = (solidity[current] & 0x80) !== 0;
        // a behaviour of the game's zones that the list does not offer (bridges...): shown as it is
        const value = String(behaviour[current] || 0);
        if (![...behaviourSelect.options].some((o) => o.value === value)) behaviourSelect.add(new Option(`${T.other} (${value})`, value));
        behaviourSelect.value = value;
        const animation = animations[current];
        animFrames.value = animation ? animation.f.join(', ') : '';
        animTicks.value = animation ? animation.t : animTicks.value || 8;
        $('.te-anim-stop').hidden = !animation;
    }

    // ---- save

    async function save() {
        if (saving) return;
        saving = true;
        status(T.saving);
        const out = document.createElement('canvas');
        out.width = COLUMNS * SIZE;
        out.height = Math.ceil(solidity.length / COLUMNS) * SIZE;
        out.getContext('2d').drawImage(sheet, 0, 0);
        const body = {
            name: $('[name="name"]').value,
            public: $('[name="public"]').checked,
            image: out.toDataURL('image/png').split(',')[1],
            solidity,
            behaviour: solidity.map((_, i) => behaviour[i] || 0),
            masks: Object.fromEntries(Object.entries(masks).filter(([n]) => n < solidity.length).map(([n, m]) => [n, { r: toHex(m.px), w: m.w }])),
            animations: Object.fromEntries(Object.entries(animations).filter(([n, a]) => n < solidity.length && a.f.every((b) => b < solidity.length))),
        };
        try {
            const r = await fetch(root.dataset.save, { method: 'POST', body: JSON.stringify(EditorLive.body(root, body)),
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.token } });
            if (!await EditorLive.answer(root, r, save)) { status(T.unsaved, 'warn'); return false; }
            dirty = false;
            status(T.saved, 'ok');
        } catch (e) {
            status(`${T.error} (${e.message})`, 'error');
        } finally {
            saving = false;
        }
    }
    $('.ed-save').addEventListener('click', save);
    $('[name="name"]').addEventListener('input', changed);
    $('[name="public"]').addEventListener('change', changed);
    $('.ed-undo').addEventListener('click', () => restore(undo, redo));
    $('.ed-redo').addEventListener('click', () => restore(redo, undo));
    addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    const KEYS = { b: 'pencil', f: 'fill', i: 'picker', e: 'eraser', k: 'clone' };
    addEventListener('keydown', (e) => {
        if (e.target.matches('input, select, textarea')) return;
        const key = e.key.toLowerCase();
        if ((e.ctrlKey || e.metaKey) && key === 's') { e.preventDefault(); save(); return; }
        if ((e.ctrlKey || e.metaKey) && key === 'z') { e.preventDefault(); restore(e.shiftKey ? redo : undo, e.shiftKey ? undo : redo); return; }
        if ((e.ctrlKey || e.metaKey) && key === 'y') { e.preventDefault(); restore(redo, undo); return; }
        if ((e.ctrlKey || e.metaKey) && key === 'c') { $('.te-copy').click(); return; }
        if ((e.ctrlKey || e.metaKey) && key === 'v') { $('.te-paste').click(); return; }
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (KEYS[key]) setTool(KEYS[key]);
        else if (e.key === 'ArrowRight' && current < solidity.length - 1) { current++; refresh(); }
        else if (e.key === 'ArrowLeft' && current > 0) { current--; refresh(); }
        else if (e.key === 'ArrowDown' && current + COLUMNS < solidity.length) { current += COLUMNS; refresh(); e.preventDefault(); }
        else if (e.key === 'ArrowUp' && current >= COLUMNS) { current -= COLUMNS; refresh(); e.preventDefault(); }
    });

    // ---- start

    Promise.all([fetch(root.dataset.json).then((r) => r.json()), loadShapes(root.dataset.shapes)]).then(async ([data, s]) => {
        shapes = s;
        solidity = [...data.solidity];
        behaviour = [...(data.behaviour || [])];
        masks = Object.fromEntries(Object.entries(data.masks || {}).filter(([, m]) => m).map(([n, m]) => [n, { px: fromHex(m.r), w: m.w }]));
        animations = Object.fromEntries(Object.entries(data.animations || {}).filter(([, a]) => a && Array.isArray(a.f)).map(([n, a]) => [n, { f: a.f.map(Number), t: Number(a.t) || 8 }]));
        sheetCtx.drawImage(await loadImage(data.image), 0, 0);
        drawShapes();
        setColour([255, 255, 255, 255]);
        setTool('pencil');
        refresh();
        status(T.saved, 'ok');
    });
})();
