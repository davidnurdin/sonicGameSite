// The tile editor (/tiles/{id}/editer): the blocks of a set (32x32 pixels, 16 a row in one PNG, up to 256) and
// the collision of each (one of the game's 47 shapes, "in front of Sonic"). Draws pixel by pixel, imports a
// PNG or the blocks of another set. Saves with POST /tiles/{id} (JSON, see EditorData::blocks).
(() => {
    'use strict';

    const root = document.getElementById('tile-editor');
    if (!root) return;
    const { SIZE, COLUMNS, loadImage, loadShapes } = window.LevelView;
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
        undo.push({ solidity: [...solidity], behaviour: [...behaviour], current, blocks: blocks.map((n) => [n, getBlock(n)]) });
        if (undo.length > 100) undo.shift();
        redo.length = 0;
        changed();
    };
    const restore = (from, to) => {
        if (!from.length) return;
        const step = from.pop();
        to.push({ solidity: [...solidity], behaviour: [...behaviour], current, blocks: step.blocks.map(([n]) => [n, getBlock(n)]) });
        solidity = step.solidity;
        behaviour = step.behaviour;
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
        listCtx.strokeStyle = '#ffd400';
        listCtx.lineWidth = 2;
        listCtx.strokeRect(at(current)[0] + 1, at(current)[1] + 1, SIZE - 2, SIZE - 2);
        $('.te-count').textContent = `${solidity.length} / ${MAX}`;
    }
    list.addEventListener('pointerdown', (e) => {
        const rect = list.getBoundingClientRect();
        const scale = list.width / rect.width;
        const n = Math.floor((e.clientY - rect.top) * scale / SIZE) * COLUMNS + Math.floor((e.clientX - rect.left) * scale / SIZE);
        if (n < solidity.length) { current = n; refresh(); }
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
        if ($('[data-toggle="shape"]').checked && shapes[solidity[current] & 0x3f]) {
            bigCtx.globalAlpha = 0.9;
            bigCtx.drawImage(shapes[solidity[current] & 0x3f], 0, 0, SIZE * PIXEL, SIZE * PIXEL);
            bigCtx.globalAlpha = 1;
        }
        bigCtx.strokeStyle = 'rgba(255,255,255,.08)';
        bigCtx.beginPath();
        for (let i = 1; i < SIZE; i++) {
            bigCtx.moveTo(i * PIXEL + 0.5, 0); bigCtx.lineTo(i * PIXEL + 0.5, SIZE * PIXEL);
            bigCtx.moveTo(0, i * PIXEL + 0.5); bigCtx.lineTo(SIZE * PIXEL, i * PIXEL + 0.5);
        }
        bigCtx.stroke();
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
        if (e.button === 2 || tool === 'picker') { pick(p); return; }
        stroke = { remembered: false, last: null };
        paint(p);
    });
    big.addEventListener('pointermove', (e) => { if (stroke) { const p = pixelAt(e); if (p) paint(p); } });
    big.addEventListener('pointerup', () => { stroke = null; drawList(); });

    function pick(p) {
        const d = sheetCtx.getImageData(at(current)[0] + p.x, at(current)[1] + p.y, 1, 1).data;
        if (d[3] === 0) return;
        setColour([d[0], d[1], d[2], 255]);
    }

    function paint(p) {
        const from = stroke.last || p;
        stroke.last = p;
        if (!stroke.remembered) { stroke.remembered = true; remember(); }
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
    $('[data-toggle="shape"]').addEventListener('change', drawBig);

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
        refresh();
    };
    $('.te-flip-h').addEventListener('click', () => transform((x, y) => [SIZE - 1 - x, y]));
    $('.te-flip-v').addEventListener('click', () => transform((x, y) => [x, SIZE - 1 - y]));
    $('.te-rotate').addEventListener('click', () => transform((x, y) => [y, SIZE - 1 - x]));
    let clipboard = null;
    $('.te-copy').addEventListener('click', () => { clipboard = { data: getBlock(current), solidity: solidity[current], behaviour: behaviour[current] || 0 }; status(T.copied); });
    $('.te-paste').addEventListener('click', () => {
        if (!clipboard) return;
        remember();
        putBlock(current, clipboard.data);
        solidity[current] = clipboard.solidity;
        behaviour[current] = clipboard.behaviour;
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
        otherSet = { img, solidity: data.solidity, behaviour: data.behaviour || [] };
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
        current = solidity.length - 1;
        refresh();
    });

    function refresh() {
        drawList();
        drawBig();
        shapeList.querySelectorAll('.te-shape').forEach((b, i) => b.classList.toggle('on', i === (solidity[current] & 0x3f)));
        front.checked = (solidity[current] & 0x80) !== 0;
        // a behaviour of the game's zones that the list does not offer (bridges...): shown as it is
        const value = String(behaviour[current] || 0);
        if (![...behaviourSelect.options].some((o) => o.value === value)) behaviourSelect.add(new Option(`${T.other} (${value})`, value));
        behaviourSelect.value = value;
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
        };
        try {
            const r = await fetch(root.dataset.save, { method: 'POST', body: JSON.stringify(body),
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.token } });
            if (!r.ok) throw new Error(String(r.status));
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

    const KEYS = { b: 'pencil', f: 'fill', i: 'picker', e: 'eraser' };
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
        sheetCtx.drawImage(await loadImage(data.image), 0, 0);
        drawShapes();
        setColour([255, 255, 255, 255]);
        setTool('pencil');
        refresh();
        status(T.saved, 'ok');
    });
})();
