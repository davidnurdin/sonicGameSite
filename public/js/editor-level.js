// The level editor (/niveau/{id}/editer): blocks of the level's set of tiles, the objects of the game, Sonic's
// start. Left button: the tool, right button: takes the block under the mouse, middle button or space: moves
// the view, wheel: scrolls (ctrl: zoom). Saves with POST /niveau/{id} (JSON, see EditorData::level).
(() => {
    'use strict';

    const root = document.getElementById('level-editor');
    if (!root) return;
    const { View, SIZE, COLUMNS, decodeBlocks, encodeBlocks, objectMark, loadIcons, FAMILY_COLOURS } = window.LevelView;
    const T = JSON.parse(root.dataset.i18n);
    const OBJECTS = JSON.parse(root.dataset.objects);
    const $ = (sel) => root.querySelector(sel);

    const box = $('.lv-box');
    const view = new View(box, { sonic: root.dataset.sonic, shapes: root.dataset.shapes, icons: root.dataset.icons,
        iconsImage: root.dataset.iconsImage, objects: OBJECTS });
    view.grid = true;

    let level = null;          // {width, height, blocks: Uint8Array, start, objects}
    let tileset = null;        // {id, solidity, image...}
    let tool = 'pencil';
    let block = 1;             // the block drawn
    let objectType = 1;
    let dirty = false;
    let saving = false;
    const undo = [];
    const redo = [];

    // ---- state, undo

    const snapshot = () => ({ width: level.width, height: level.height, blocks: level.blocks.slice(), start: [...level.start],
        objects: level.objects.map((o) => [...o]), tileset: tileset.id });
    const remember = () => {
        undo.push(snapshot());
        if (undo.length > 200) undo.shift();
        redo.length = 0;
        changed();
    };
    const restore = (from, to) => {
        if (!from.length) return;
        to.push(snapshot());
        const s = from.pop();
        Object.assign(level, { width: s.width, height: s.height, blocks: s.blocks, start: s.start, objects: s.objects });
        select([]);
        if (s.tileset !== tileset.id) useTileset(s.tileset, false);
        view.setLevel(level);
        sizeInputs();
        changed();
    };
    const changed = () => {
        dirty = true;
        status(T.unsaved, 'warn');
    };
    const status = (text, kind = '') => {
        const el = $('.ed-status');
        el.textContent = text;
        el.dataset.kind = kind;
    };

    // ---- the palette of blocks

    const palette = $('.ed-palette');
    const paletteCtx = palette.getContext('2d');
    const PALETTE_SCALE = 0.5625;     // 16 blocks of 18 px: 288 px wide

    function drawPalette() {
        const count = tileset.solidity.length;
        const rows = Math.ceil(count / COLUMNS);
        const cell = SIZE * PALETTE_SCALE;
        palette.width = COLUMNS * cell * 2;
        palette.height = rows * cell * 2;
        palette.style.width = `${COLUMNS * cell}px`;
        palette.style.height = `${rows * cell}px`;
        paletteCtx.setTransform(2, 0, 0, 2, 0, 0);
        paletteCtx.imageSmoothingEnabled = false;
        paletteCtx.fillStyle = '#0a1440';
        paletteCtx.fillRect(0, 0, palette.width, palette.height);
        if (view.sheet) paletteCtx.drawImage(view.sheet, 0, 0, view.sheet.width * PALETTE_SCALE, view.sheet.height * PALETTE_SCALE);
        paletteCtx.strokeStyle = '#ffd400';
        paletteCtx.lineWidth = 1.5;
        paletteCtx.strokeRect((block % COLUMNS) * cell + 0.75, Math.floor(block / COLUMNS) * cell + 0.75, cell - 1.5, cell - 1.5);
        $('.ed-block').textContent = `${T.block} ${block}`;
    }
    palette.addEventListener('pointerdown', (e) => {
        const rect = palette.getBoundingClientRect();
        const cell = SIZE * PALETTE_SCALE;
        const n = Math.floor((e.clientY - rect.top) / cell) * COLUMNS + Math.floor((e.clientX - rect.left) / cell);
        if (n >= 0 && n < tileset.solidity.length) {
            block = n;
            if (tool === 'eraser' || tool === 'object' || tool === 'start' || tool === 'picker') setTool('pencil');
            drawPalette();
        }
    });

    // ---- objects

    const objectList = $('.ed-objects');
    for (const [type, [name, family]] of Object.entries(OBJECTS)) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'ed-object';
        b.dataset.type = type;
        b.title = name;
        const mark = document.createElement('span');
        mark.textContent = objectMark(Number(type), OBJECTS);
        mark.style.background = FAMILY_COLOURS[family];
        mark.style.color = family === 'item' ? '#10183a' : '#fff';
        b.append(mark, name);
        b.addEventListener('click', () => { objectType = Number(type); setTool('object'); markObject(); });
        objectList.append(b);
    }
    // the objects' pictures instead of their letters
    loadIcons(root.dataset.icons, root.dataset.iconsImage).then(({ img, index }) => {
        for (const b of objectList.querySelectorAll('.ed-object')) {
            const icon = index[b.dataset.type];
            if (!icon) continue;
            const [sx, sy, w, h] = icon;
            const c = document.createElement('canvas');
            const scale = Math.min(1, 28 / w, 28 / h);
            c.width = 28;
            c.height = 28;
            c.className = 'ed-object-icon';
            const g = c.getContext('2d');
            g.imageSmoothingEnabled = false;
            g.drawImage(img, sx, sy, w, h, (28 - w * scale) / 2, (28 - h * scale) / 2, w * scale, h * scale);
            b.firstElementChild.replaceWith(c);
        }
    }, () => {});
    const markObject = () => objectList.querySelectorAll('.ed-object').forEach((b) => b.classList.toggle('on', Number(b.dataset.type) === objectType && tool === 'object'));

    // ---- tools

    function setTool(name) {
        tool = name;
        root.querySelectorAll('[data-tool]').forEach((b) => b.classList.toggle('on', b.dataset.tool === name));
        markObject();
        box.dataset.tool = name;
        $('.ed-clone').hidden = name !== 'clone';
        view.ghost = null;
        view.source = name === 'clone' && clone.source ? sourceFrame(clone.source) : null;
        if (name !== 'select' && name !== 'object') select([]);
        view.draw();
    }

    // ---- objects: selection

    const selectionBox = $('.ed-selection');
    function select(list) {
        view.selected = new Set(list);
        const n = view.selected.size;
        selectionBox.hidden = n === 0;
        if (n) {
            const [first] = view.selected;
            $('.ed-selection-text').textContent = n === 1 ? `${(OBJECTS[first[0]] || ['?'])[0]} · ${first[1]}, ${first[2]}` : T.selected.replace('%n%', n);
        }
        view.draw();
    }
    const removeObjects = (list) => {
        const gone = new Set(list);
        level.objects = level.objects.filter((o) => !gone.has(o));
        view.level.objects = level.objects;
        select([]);
    };
    // moves the objects by (dx, dy) blocks, kept in the level
    const moveObjects = (list, dx, dy) => {
        for (const o of list) {
            o[1] = Math.max(0, Math.min(level.width - 1, o[1] + dx));
            o[2] = Math.max(0, Math.min(level.height - 1, o[2] + dy));
        }
    };
    const copyObjects = (list) => {
        const copies = list.filter(() => level.objects.length < 1000).map((o) => [...o]);
        level.objects.push(...copies);
        return copies;
    };
    $('.ed-selection-delete').addEventListener('click', () => { if (view.selected.size) { remember(); removeObjects([...view.selected]); } });
    $('.ed-selection-replace').addEventListener('click', () => {
        if (!view.selected.size) return;
        remember();
        for (const o of view.selected) o[0] = objectType;
        select([...view.selected]);
    });

    // ---- the clone tool: blocks (and objects) copied from a source, at the same distance as the first block painted

    const clone = { source: null, offset: null };
    const cloneSize = () => Number($('[name="clone-size"]').value) || 1;
    const sourceFrame = (at) => {
        const n = cloneSize();
        const half = Math.floor((n - 1) / 2);
        return { x: at.x - half, y: at.y - half, w: n, h: n };
    };
    function setSource(at) {
        clone.source = at;
        clone.offset = null;
        view.source = sourceFrame(at);
        view.draw();
    }
    // the blocks of the brush at `at` take those at `at + offset` (as they were when the stroke began)
    function stamp(at) {
        const n = cloneSize();
        const half = Math.floor((n - 1) / 2);
        const objects = $('[name="clone-objects"]').checked;
        for (let y = at.y - half; y < at.y - half + n; y++) {
            for (let x = at.x - half; x < at.x - half + n; x++) {
                const sx = x + clone.offset.x;
                const sy = y + clone.offset.y;
                if (x < 0 || y < 0 || x >= level.width || y >= level.height || sx < 0 || sy < 0 || sx >= level.width || sy >= level.height) continue;
                if (stroke.done.has(y * level.width + x)) continue;
                stroke.done.add(y * level.width + x);
                const value = stroke.blocks[sy * level.width + sx];
                if (level.blocks[y * level.width + x] !== value) { once(); setBlock(x, y, value); }
                if (!objects) continue;
                for (const [type, ox, oy] of stroke.objects) {
                    if (ox !== sx || oy !== sy) continue;
                    if (level.objects.some((o) => o[0] === type && o[1] === x && o[2] === y) || level.objects.length >= 1000) continue;
                    once();
                    level.objects.push([type, x, y]);
                }
            }
        }
    }
    root.querySelectorAll('[data-tool]').forEach((b) => b.addEventListener('click', () => setTool(b.dataset.tool)));

    const setBlock = (x, y, value) => {
        const i = y * level.width + x;
        if (level.blocks[i] === value) return false;
        level.blocks[i] = value;
        return true;
    };
    const objectAt = (x, y) => level.objects.findIndex((o) => o[1] === x && o[2] === y);

    let stroke = null;   // the button held down: {start, last, remembered}
    let pan = null;
    let space = false;

    box.addEventListener('contextmenu', (e) => e.preventDefault());
    box.addEventListener('pointerdown', (e) => {
        if (!level) return;
        box.setPointerCapture(e.pointerId);
        if (e.button === 1 || space) {
            pan = { x: e.clientX, y: e.clientY, left: box.scrollLeft, top: box.scrollTop };
            e.preventDefault();
            return;
        }
        const at = view.blockAt(e.clientX, e.clientY);
        if (!at) return;
        const p = view.pixelAt(e.clientX, e.clientY);
        const hit = tool === 'select' || tool === 'object' ? view.objectAt(p.x, p.y) : null;
        // the clone tool: right click or Alt+click chooses the source
        if (tool === 'clone' && (e.button === 2 || (e.button === 0 && e.altKey))) { setSource(at); return; }
        // the objects: right click removes the one under the mouse
        if (e.button === 2 && hit) { remember(); removeObjects([hit]); return; }
        if (e.button === 2) {
            block = level.blocks[at.y * level.width + at.x];
            drawPalette();
            if (tool !== 'pencil' && tool !== 'rect') setTool('pencil');
            return;
        }
        if (e.button !== 0) return;
        stroke = { start: at, last: null, remembered: false };
        if (tool === 'select' || tool === 'object') { objectsDown(at, hit, e); return; }
        if (tool === 'clone') {
            if (!clone.source) { status(T.source, 'warn'); stroke = null; return; }
            // aligned: the distance taken at the first stroke stays; otherwise each stroke starts from the source
            if (!clone.offset || !$('[name="clone-aligned"]').checked) clone.offset = { x: clone.source.x - at.x, y: clone.source.y - at.y };
            // copied from the level as it is before the stroke (painting over the source does not copy the copy)
            Object.assign(stroke, { blocks: level.blocks.slice(), objects: level.objects.map((o) => [...o]), done: new Set() });
        }
        apply(at, e);
    });

    // the select and object tools: what a press does
    function objectsDown(at, hit, e) {
        if (hit) {
            if (e.shiftKey && tool === 'object') { remember(); removeObjects([hit]); stroke = null; return; }
            if (e.shiftKey && tool === 'select') {
                const list = new Set(view.selected);
                list.has(hit) ? list.delete(hit) : list.add(hit);
                select([...list]);
                stroke = null;
                return;
            }
            if (!view.selected.has(hit)) select([hit]);
            // dragged: moved (Ctrl: copied first)
            stroke.move = { from: at, list: [...view.selected], copy: e.ctrlKey || e.metaKey };
            return;
        }
        if (tool === 'object') {
            if (level.objects.length >= 1000) { stroke = null; return; }
            once();
            const o = [objectType, at.x, at.y];
            level.objects.push(o);
            select([o]);
            stroke.move = { from: at, list: [o], copy: false, remembered: true };
            view.ghost = null;
            return;
        }
        // the select tool on nothing: a rectangle of selection
        if (!e.shiftKey) select([]);
        stroke.marquee = { from: at, before: [...view.selected] };
    }
    box.addEventListener('pointermove', (e) => {
        if (pan) {
            box.scrollLeft = pan.left - (e.clientX - pan.x);
            box.scrollTop = pan.top - (e.clientY - pan.y);
            return;
        }
        const at = view.blockAt(e.clientX, e.clientY);
        $('.ed-pos').textContent = at ? `${at.x}, ${at.y} · ${T.block} ${level.blocks[at.y * level.width + at.x]}` : '';
        if (stroke && at) {
            if (stroke.move) moveDrag(at);
            else if (stroke.marquee) marquee(at);
            else apply(at, e);
        }
        if (!stroke) {
            const p = view.pixelAt(e.clientX, e.clientY);
            const over = at && (tool === 'select' || tool === 'object') ? view.objectAt(p.x, p.y) : null;
            box.dataset.over = over ? '1' : '';
            // the object tool: what would be put there; on an object: its frame (it would be taken)
            view.ghost = tool === 'object' && at && !over ? { type: objectType, ...at } : null;
            view.hover = at && tool !== 'select' && !(tool === 'object' && over) ? { ...brush(at), colour: tool === 'eraser' ? '#ff5a5a' : '#ffd400' } : null;
        }
        // the clone tool: the source follows the brush, framed
        if (tool === 'clone' && clone.source) {
            const follow = at && clone.offset ? { x: at.x + clone.offset.x, y: at.y + clone.offset.y } : clone.source;
            view.source = sourceFrame(follow);
        }
        view.draw();
    });
    // the brush of the tool at a block (the clone tool: its size)
    const brush = (at) => (tool === 'clone' ? sourceFrame(at) : at);

    function moveDrag(at) {
        const m = stroke.move;
        const dx = at.x - m.from.x;
        const dy = at.y - m.from.y;
        if (!dx && !dy) return;
        if (!m.remembered) { m.remembered = true; once(); }
        if (m.copy) {
            m.list = copyObjects(m.list);
            m.copy = false;
            select(m.list);
        }
        moveObjects(m.list, dx, dy);
        m.from = at;
        select(m.list);
    }
    function marquee(at) {
        const r = rect(stroke.marquee.from, at);
        view.hover = { ...r, colour: '#3fd0ff' };
        const inside = level.objects.filter(([, x, y]) => x >= r.x && x < r.x + r.w && y >= r.y && y < r.y + r.h);
        select([...new Set([...stroke.marquee.before, ...inside])]);
    }

    const endStroke = () => {
        if (stroke && tool === 'rect' && stroke.last) {
            const r = rect(stroke.start, stroke.last);
            once();
            for (let y = r.y; y < r.y + r.h; y++) for (let x = r.x; x < r.x + r.w; x++) setBlock(x, y, block);
        }
        stroke = null;
        pan = null;
        view.hover = null;
        view.draw();
    };
    box.addEventListener('pointerup', endStroke);
    box.addEventListener('pointercancel', endStroke);
    box.addEventListener('pointerleave', () => { if (!stroke) { view.hover = null; view.draw(); } });

    const rect = (a, b) => ({ x: Math.min(a.x, b.x), y: Math.min(a.y, b.y), w: Math.abs(a.x - b.x) + 1, h: Math.abs(a.y - b.y) + 1 });
    // one undo step per stroke, taken just before its first change
    const once = () => {
        if (stroke.remembered) return;
        stroke.remembered = true;
        remember();
    };

    function apply(at, e) {
        const same = stroke.last && stroke.last.x === at.x && stroke.last.y === at.y;
        const from = stroke.last || at;
        stroke.last = at;
        if (same) return;
        switch (tool) {
            case 'pencil':
            case 'eraser': {
                const value = tool === 'eraser' ? 0 : block;
                // a line from the last block: a fast mouse leaves no hole
                const steps = Math.max(Math.abs(at.x - from.x), Math.abs(at.y - from.y), 1);
                for (let i = 0; i <= steps; i++) {
                    const x = Math.round(from.x + (at.x - from.x) * i / steps);
                    const y = Math.round(from.y + (at.y - from.y) * i / steps);
                    if (level.blocks[y * level.width + x] !== value) { once(); setBlock(x, y, value); }
                }
                break;
            }
            case 'rect':
                view.hover = { ...rect(stroke.start, at) };
                break;
            case 'fill':
                if (level.blocks[at.y * level.width + at.x] !== block) { once(); fill(at.x, at.y, block); }
                break;
            case 'picker':
                block = level.blocks[at.y * level.width + at.x];
                drawPalette();
                break;
            case 'start':
                if (level.start[0] !== at.x || level.start[1] !== at.y) { once(); level.start = [at.x, at.y]; }
                break;
            case 'clone': {
                // a line from the last block: a fast mouse leaves no hole
                const steps = Math.max(Math.abs(at.x - from.x), Math.abs(at.y - from.y), 1);
                for (let i = 0; i <= steps; i++) stamp({ x: Math.round(from.x + (at.x - from.x) * i / steps), y: Math.round(from.y + (at.y - from.y) * i / steps) });
                break;
            }
        }
        view.draw();
    }

    function fill(x0, y0, value) {
        const { width, height, blocks } = level;
        const old = blocks[y0 * width + x0];
        const stack = [[x0, y0]];
        while (stack.length) {
            const [x, y] = stack.pop();
            if (x < 0 || y < 0 || x >= width || y >= height || blocks[y * width + x] !== old) continue;
            blocks[y * width + x] = value;
            stack.push([x + 1, y], [x - 1, y], [x, y + 1], [x, y - 1]);
        }
    }

    // ---- view: zoom, grid, collisions

    const ZOOMS = [0.25, 0.5, 1, 1.5, 2];
    const zoomBy = (step, x, y) => {
        const i = ZOOMS.indexOf(view.zoom);
        const next = ZOOMS[Math.max(0, Math.min(ZOOMS.length - 1, (i < 0 ? 2 : i) + step))];
        view.setZoom(next, x, y);
        $('.ed-zoom').textContent = `${Math.round(next * 100)} %`;
    };
    box.addEventListener('wheel', (e) => {
        if (!e.ctrlKey && !e.metaKey) return;
        e.preventDefault();
        zoomBy(e.deltaY < 0 ? 1 : -1, e.clientX, e.clientY);
    }, { passive: false });
    root.querySelectorAll('[data-zoom]').forEach((b) => b.addEventListener('click', () => zoomBy(Number(b.dataset.zoom))));
    $('[data-toggle="grid"]').addEventListener('change', (e) => { view.grid = e.target.checked; view.draw(); });
    $('[data-toggle="collisions"]').addEventListener('change', (e) => { view.collisions = e.target.checked; view.draw(); });

    // ---- size of the level

    const widthInput = $('[name="width"]');
    const heightInput = $('[name="height"]');
    const sizeInputs = () => { widthInput.value = level.width; heightInput.value = level.height; };
    $('.ed-resize').addEventListener('click', () => {
        const w = Math.max(8, Math.min(256, parseInt(widthInput.value, 10) || level.width));
        let h = Math.max(6, Math.min(256, parseInt(heightInput.value, 10) || level.height));
        if (w * h > 16384) h = Math.floor(16384 / w);
        if (w === level.width && h === level.height) { sizeInputs(); return; }
        remember();
        // anchored at the bottom left: the ground stays the ground
        const anchorBottom = $('[name="anchor"]').value === 'bottom';
        const dy = anchorBottom ? h - level.height : 0;
        const blocks = new Uint8Array(w * h);
        for (let y = 0; y < h; y++) {
            const sy = y - dy;
            if (sy < 0 || sy >= level.height) continue;
            for (let x = 0; x < Math.min(w, level.width); x++) blocks[y * w + x] = level.blocks[sy * level.width + x];
        }
        level.objects = level.objects.map(([t, x, y]) => [t, x, y + dy]).filter(([, x, y]) => x < w && y >= 0 && y < h);
        level.start = [Math.min(level.start[0], w - 1), Math.max(0, Math.min(level.start[1] + dy, h - 1))];
        Object.assign(level, { width: w, height: h, blocks });
        view.setLevel(level);
        sizeInputs();
    });

    // ---- the set of tiles

    const tilesetSelect = $('[name="tileset"]');
    async function useTileset(id, record = true) {
        const data = await fetch(root.dataset.tilesetJson.replace('ID', id)).then((r) => r.json());
        if (record) remember();
        tileset = data;
        tilesetSelect.value = String(id);
        await view.setTileset(data);
        if (block >= data.solidity.length) block = 0;
        drawPalette();
        $('.ed-tileset-link').href = root.dataset.tilesetPage.replace('ID', id);
    }
    tilesetSelect.addEventListener('change', () => useTileset(Number(tilesetSelect.value)));

    // ---- save

    async function save() {
        if (saving) return false;
        saving = true;
        status(T.saving);
        const body = {
            name: $('[name="name"]').value,
            public: $('[name="public"]').checked,
            tileset: tileset.id,
            width: level.width,
            height: level.height,
            blocks: encodeBlocks(level.blocks),
            start: level.start,
            objects: level.objects,
            // a song of the game, or a music of the sound editor ("s<id>": then music stays the game's song before)
            music: $('[name="music"]').value.startsWith('s') ? gameMusic : Number($('[name="music"]').value),
            musicSound: $('[name="music"]').value.startsWith('s') ? Number($('[name="music"]').value.slice(1)) : null,
            sfx: Object.fromEntries([...root.querySelectorAll('[data-sfx]')].filter((s) => s.value).map((s) => [s.dataset.sfx, Number(s.value)])),
            sprites: $('[name="sprites"]').value ? Number($('[name="sprites"]').value) : null,
            thumb: view.sheet ? view.thumbnail() : undefined,
        };
        try {
            const r = await fetch(root.dataset.save, { method: 'POST', body: JSON.stringify(EditorLive.body(root, body)),
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.token } });
            if (!await EditorLive.answer(root, r, save)) { status(T.unsaved, 'warn'); return false; }
            dirty = false;
            status(T.saved, 'ok');
            return true;
        } catch (e) {
            status(`${T.error} (${e.message})`, 'error');
            return false;
        } finally {
            saving = false;
        }
    }
    $('.ed-save').addEventListener('click', save);
    // the game in a new tab, on this level as it is now (opened at once: a tab opened after the save is blocked)
    $('.ed-test').addEventListener('click', async () => {
        const tab = window.open('about:blank', '_blank');
        if (await save()) {
            if (tab) tab.location.href = root.dataset.play;
            else location.href = root.dataset.play;
        } else {
            tab?.close();
        }
    });
    $('[name="music"]').addEventListener('change', changed);
    $('[name="sprites"]').addEventListener('change', changed);
    root.querySelectorAll('[data-sfx]').forEach((s) => s.addEventListener('change', changed));
    // the song of the game kept for a level with a music of the sound editor
    let gameMusic = 0;
    const keepGameMusic = () => { if (!$('[name="music"]').value.startsWith('s')) gameMusic = Number($('[name="music"]').value); };
    $('[name="music"]').addEventListener('change', keepGameMusic);
    $('[name="name"]').addEventListener('input', changed);
    $('[name="public"]').addEventListener('change', changed);
    $('.ed-undo').addEventListener('click', () => restore(undo, redo));
    $('.ed-redo').addEventListener('click', () => restore(redo, undo));
    addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    // ---- keyboard

    const KEYS = { b: 'pencil', r: 'rect', f: 'fill', i: 'picker', e: 'eraser', o: 'object', s: 'start', v: 'select', k: 'clone' };
    addEventListener('keydown', (e) => {
        if (e.target.matches('input, select, textarea')) return;
        const key = e.key.toLowerCase();
        if ((e.ctrlKey || e.metaKey) && key === 's') { e.preventDefault(); save(); return; }
        if ((e.ctrlKey || e.metaKey) && key === 'z') { e.preventDefault(); restore(e.shiftKey ? redo : undo, e.shiftKey ? undo : redo); return; }
        if ((e.ctrlKey || e.metaKey) && key === 'y') { e.preventDefault(); restore(redo, undo); return; }
        // the objects selected: duplicated (Ctrl+D), removed, moved a block, let go
        if ((e.ctrlKey || e.metaKey) && key === 'd' && view.selected.size) {
            e.preventDefault();
            remember();
            const copies = copyObjects([...view.selected]);
            moveObjects(copies, 1, 0);
            select(copies);
            return;
        }
        if (view.selected.size && (key === 'delete' || key === 'backspace')) { e.preventDefault(); remember(); removeObjects([...view.selected]); return; }
        const arrows = { arrowleft: [-1, 0], arrowright: [1, 0], arrowup: [0, -1], arrowdown: [0, 1] };
        if (view.selected.size && arrows[key]) { e.preventDefault(); remember(); moveObjects([...view.selected], ...arrows[key]); select([...view.selected]); return; }
        if (key === 'escape') { select([]); return; }
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (key === ' ') { space = true; box.dataset.pan = '1'; e.preventDefault(); return; }
        if (KEYS[key]) setTool(KEYS[key]);
        else if (key === 'g') { const c = $('[data-toggle="grid"]'); c.checked = !c.checked; c.dispatchEvent(new Event('change')); }
        else if (key === 'c') { const c = $('[data-toggle="collisions"]'); c.checked = !c.checked; c.dispatchEvent(new Event('change')); }
        else if (key === '+' || key === '=') zoomBy(1);
        else if (key === '-') zoomBy(-1);
    });
    addEventListener('keyup', (e) => { if (e.key === ' ') { space = false; delete box.dataset.pan; } });

    // ---- start

    fetch(root.dataset.json).then((r) => r.json()).then(async (data) => {
        level = { width: data.width, height: data.height, blocks: decodeBlocks(data.blocks), start: data.start, objects: data.objects };
        gameMusic = data.music;
        tileset = data.tileset;
        await view.setTileset(tileset);
        tilesetSelect.value = String(tileset.id);
        view.setLevel(level);
        sizeInputs();
        drawPalette();
        setTool('pencil');
        box.scrollTop = Math.max(0, data.start[1] * SIZE - box.clientHeight / 2);
        box.scrollLeft = Math.max(0, data.start[0] * SIZE - box.clientWidth / 3);
        status(T.saved, 'ok');
    });
})();
