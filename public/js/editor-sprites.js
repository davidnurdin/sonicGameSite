// The sprite editor (/sprites/{id}/editer): the game's sheets of sprites (SpriteSet::SHEETS), drawn again pixel by
// pixel, or imported (a PNG the size of the game's sheet). The sheets drawn again replace the game's ones in the
// levels that use the set. Saves with POST /sprites/{id} (JSON: {name, public, sheets: {sheet: PNG in base64}}).
(() => {
    'use strict';

    const root = document.getElementById('sprite-editor');
    if (!root) return;
    const T = JSON.parse(root.dataset.i18n);
    const $ = (sel) => root.querySelector(sel);
    const ZOOMS = [1, 2, 3, 4, 6, 8, 12, 16];
    const MAX_CANVAS = 16000;

    const sheets = {};       // name => {width, height, canvas, ctx, own (drawn again), game (url of the game's), colours}
    let current = null;
    let zoom = 4;
    let grid = true;
    let colour = [255, 255, 255, 255];
    let tool = 'pencil';
    let dirty = false;
    let saving = false;
    const undo = [];
    const redo = [];

    const status = (text, kind = '') => { const el = $('.ed-status'); el.textContent = text; el.dataset.kind = kind; };
    const changed = () => { dirty = true; status(T.unsaved, 'warn'); };
    const hex = (c) => '#' + c.slice(0, 3).map((v) => v.toString(16).padStart(2, '0')).join('');
    const loadImage = (src) => new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = src;
    });

    // ---- undo: the whole sheet before an action

    const snapshot = (name) => ({ name, own: sheets[name].own, data: sheets[name].ctx.getImageData(0, 0, sheets[name].width, sheets[name].height) });
    const remember = () => {
        undo.push(snapshot(current));
        if (undo.length > 40) undo.shift();
        redo.length = 0;
        changed();
    };
    const restore = (from, to) => {
        if (!from.length) return;
        const step = from.pop();
        to.push(snapshot(step.name));
        const sheet = sheets[step.name];
        sheet.ctx.putImageData(step.data, 0, 0);
        sheet.own = step.own;
        changed();
        if (step.name !== current) select(step.name); else refresh();
    };

    // ---- the view: the sheet, zoomed, on a checkerboard (CSS) for the transparent pixels

    const box = $('.sp-box');
    const view = $('.sp-canvas');
    const viewCtx = view.getContext('2d');
    let pending = false;
    function draw() {
        if (pending) return;
        pending = true;
        requestAnimationFrame(() => {
            pending = false;
            const sheet = sheets[current];
            if (!sheet) return;
            viewCtx.imageSmoothingEnabled = false;
            viewCtx.clearRect(0, 0, view.width, view.height);
            viewCtx.drawImage(sheet.canvas, 0, 0, view.width, view.height);
            if (grid && zoom >= 6) {
                viewCtx.strokeStyle = 'rgba(255, 255, 255, .12)';
                viewCtx.lineWidth = 1;
                viewCtx.beginPath();
                for (let x = 1; x < sheet.width; x++) { viewCtx.moveTo(x * zoom + 0.5, 0); viewCtx.lineTo(x * zoom + 0.5, view.height); }
                for (let y = 1; y < sheet.height; y++) { viewCtx.moveTo(0, y * zoom + 0.5); viewCtx.lineTo(view.width, y * zoom + 0.5); }
                viewCtx.stroke();
            }
            drawClone();
        });
    }
    function setZoom(z, keep = null) {
        const sheet = sheets[current];
        const max = ZOOMS.filter((v) => v * sheet.width <= MAX_CANVAS).pop() || 1;
        const before = zoom;
        zoom = Math.max(1, Math.min(max, z));
        view.width = sheet.width * zoom;
        view.height = sheet.height * zoom;
        $('.ed-zoom').textContent = `×${zoom}`;
        // the point under the mouse stays where it is
        if (keep) {
            const r = box.getBoundingClientRect();
            const px = (box.scrollLeft + keep.clientX - r.left) / before;
            const py = (box.scrollTop + keep.clientY - r.top) / before;
            box.scrollLeft = px * zoom - (keep.clientX - r.left);
            box.scrollTop = py * zoom - (keep.clientY - r.top);
        }
        draw();
    }
    const stepZoom = (dir, keep) => {
        const i = ZOOMS.indexOf(zoom);
        setZoom(ZOOMS[Math.max(0, Math.min(ZOOMS.length - 1, (i < 0 ? 3 : i) + dir))], keep);
    };
    root.querySelectorAll('[data-zoom]').forEach((b) => b.addEventListener('click', () => stepZoom(Number(b.dataset.zoom))));
    box.addEventListener('wheel', (e) => {
        if (!e.ctrlKey) return;
        e.preventDefault();
        stepZoom(e.deltaY < 0 ? 1 : -1, e);
    }, { passive: false });
    $('[data-toggle="grid"]').addEventListener('change', (e) => { grid = e.target.checked; draw(); });

    // ---- the sheets

    const tabs = root.querySelectorAll('.sp-tabs [data-sheet]');
    function refresh() {
        tabs.forEach((t) => {
            t.classList.toggle('on', t.dataset.sheet === current);
            t.classList.toggle('own', !!sheets[t.dataset.sheet]?.own);
            t.title = sheets[t.dataset.sheet]?.own ? T.own : '';
        });
        draw();
    }
    function select(name) {
        current = name;
        // the source of the clone was on the other sheet
        clone.source = clone.offset = null;
        const sheet = sheets[name];
        $('.sp-size').textContent = `${sheet.width}×${sheet.height}`;
        drawSwatches(sheet.colours);
        setZoom(zoom);
        box.scrollTop = box.scrollLeft = 0;
        refresh();
    }
    tabs.forEach((t) => t.addEventListener('click', () => { if (sheets[t.dataset.sheet]) select(t.dataset.sheet); }));

    // the colours of the game's sheet, the most used first (64 at most)
    function colours(img) {
        const c = document.createElement('canvas');
        c.width = img.width;
        c.height = img.height;
        const g = c.getContext('2d');
        g.drawImage(img, 0, 0);
        const data = g.getImageData(0, 0, c.width, c.height).data;
        const count = new Map();
        for (let i = 0; i < data.length; i += 4) {
            if (data[i + 3] < 128) continue;
            const key = (data[i] << 16) | (data[i + 1] << 8) | data[i + 2];
            count.set(key, (count.get(key) || 0) + 1);
        }
        return [...count.entries()].sort((a, b) => b[1] - a[1]).slice(0, 64).map(([k]) => [k >> 16, (k >> 8) & 255, k & 255, 255]);
    }

    // ---- colour

    const picker = $('[name="colour"]');
    const swatches = $('.sp-swatches');
    function setColour(c) {
        colour = c;
        picker.value = hex(c);
        swatches.querySelectorAll('.te-swatch').forEach((s) => s.classList.toggle('on', s.dataset.hex === hex(c)));
        if (tool === 'eraser' || tool === 'picker') setTool('pencil');
    }
    picker.addEventListener('input', () => {
        const v = picker.value;
        setColour([parseInt(v.slice(1, 3), 16), parseInt(v.slice(3, 5), 16), parseInt(v.slice(5, 7), 16), 255]);
    });
    function drawSwatches(list) {
        swatches.replaceChildren(...list.map((c) => {
            const s = document.createElement('button');
            s.type = 'button';
            s.className = 'te-swatch';
            s.dataset.hex = hex(c);
            s.style.background = s.dataset.hex;
            s.title = s.dataset.hex;
            s.addEventListener('click', () => setColour(c));
            return s;
        }));
    }

    // ---- tools

    function setTool(name) {
        tool = name;
        root.querySelectorAll('[data-tool]').forEach((b) => b.classList.toggle('on', b.dataset.tool === name));
        $('.ed-clone').hidden = name !== 'clone';
        draw();
    }
    root.querySelectorAll('[data-tool]').forEach((b) => b.addEventListener('click', () => setTool(b.dataset.tool)));

    const pixelAt = (e) => {
        const r = view.getBoundingClientRect();
        return [Math.floor((e.clientX - r.left) / zoom), Math.floor((e.clientY - r.top) / zoom)];
    };
    const inside = ([x, y]) => x >= 0 && y >= 0 && x < sheets[current].width && y < sheets[current].height;
    function plot(x, y, c) {
        const { ctx } = sheets[current];
        if (c[3] === 0) {
            ctx.clearRect(x, y, 1, 1);
        } else {
            ctx.fillStyle = `rgb(${c[0]}, ${c[1]}, ${c[2]})`;
            ctx.fillRect(x, y, 1, 1);
        }
    }
    function line([x0, y0], [x1, y1], c) {
        const dx = Math.abs(x1 - x0), dy = -Math.abs(y1 - y0);
        const sx = x0 < x1 ? 1 : -1, sy = y0 < y1 ? 1 : -1;
        let err = dx + dy;
        for (;;) {
            if (inside([x0, y0])) plot(x0, y0, c);
            if (x0 === x1 && y0 === y1) break;
            const e2 = 2 * err;
            if (e2 >= dy) { err += dy; x0 += sx; }
            if (e2 <= dx) { err += dx; y0 += sy; }
        }
    }
    function fill([x, y], c) {
        const { ctx, width, height } = sheets[current];
        const image = ctx.getImageData(0, 0, width, height);
        const d = image.data;
        const i0 = (y * width + x) * 4;
        const from = [d[i0], d[i0 + 1], d[i0 + 2], d[i0 + 3]];
        const same = (i) => from[3] === 0 ? d[i + 3] === 0 : d[i] === from[0] && d[i + 1] === from[1] && d[i + 2] === from[2] && d[i + 3] === from[3];
        if (c[3] === 0 ? from[3] === 0 : from[3] === 255 && from[0] === c[0] && from[1] === c[1] && from[2] === c[2]) return;
        const stack = [[x, y]];
        while (stack.length) {
            const [px, py] = stack.pop();
            if (px < 0 || py < 0 || px >= width || py >= height) continue;
            const i = (py * width + px) * 4;
            if (!same(i)) continue;
            d[i] = c[0]; d[i + 1] = c[1]; d[i + 2] = c[2]; d[i + 3] = c[3];
            stack.push([px + 1, py], [px - 1, py], [px, py + 1], [px, py - 1]);
        }
        ctx.putImageData(image, 0, 0);
    }
    // ---- clone (K): Alt+click the source, then paint elsewhere: the pixels at the same distance are copied, from
    // the sheet as it was when the stroke began. Aligned: the distance stays from one stroke to the next.

    const clone = { source: null, offset: null, from: null, hover: null };
    const cloneSize = () => Number($('[name="clone-size"]').value) || 4;
    const brushAt = ([x, y]) => { const n = cloneSize(); return [x - Math.floor(n / 2), y - Math.floor(n / 2), n]; };
    function stamp(p) {
        const { ctx, width, height } = sheets[current];
        const [x0, y0, n] = brushAt(p);
        const out = ctx.getImageData(x0, y0, n, n);
        const from = clone.from.data;
        for (let dy = 0; dy < n; dy++) for (let dx = 0; dx < n; dx++) {
            const sx = x0 + dx + clone.offset[0], sy = y0 + dy + clone.offset[1];
            if (sx < 0 || sy < 0 || sx >= width || sy >= height) continue;
            const i = (sy * width + sx) * 4;
            out.data.set(from.subarray(i, i + 4), (dy * n + dx) * 4);
        }
        ctx.putImageData(out, x0, y0);
    }
    // every pixel from a to b
    function walk([x0, y0], [x1, y1], fn) {
        const steps = Math.max(Math.abs(x1 - x0), Math.abs(y1 - y0), 1);
        for (let i = 0; i <= steps; i++) fn([Math.round(x0 + (x1 - x0) * i / steps), Math.round(y0 + (y1 - y0) * i / steps)]);
    }
    // the brush (white) and the source it copies (blue, dashed, with a cross)
    function drawClone() {
        if (tool !== 'clone' || (!clone.hover && !clone.source)) return;
        const frame = ([x, y, n], colour, dash) => {
            viewCtx.setLineDash(dash);
            viewCtx.strokeStyle = colour;
            viewCtx.lineWidth = 2;
            viewCtx.strokeRect(x * zoom + 1, y * zoom + 1, n * zoom - 2, n * zoom - 2);
            viewCtx.setLineDash([]);
        };
        let source = null;
        if (clone.hover && clone.offset) source = brushAt([clone.hover[0] + clone.offset[0], clone.hover[1] + clone.offset[1]]);
        else if (clone.source) source = brushAt(clone.source);
        if (source) {
            frame(source, '#3fd0ff', [6, 4]);
            const cx = (source[0] + source[2] / 2) * zoom, cy = (source[1] + source[2] / 2) * zoom;
            viewCtx.strokeStyle = '#3fd0ff';
            viewCtx.beginPath();
            viewCtx.moveTo(cx - 8, cy); viewCtx.lineTo(cx + 8, cy); viewCtx.moveTo(cx, cy - 8); viewCtx.lineTo(cx, cy + 8);
            viewCtx.stroke();
        }
        if (clone.hover) frame(brushAt(clone.hover), 'rgba(255, 255, 255, .9)', []);
    }

    function pick([x, y]) {
        const d = sheets[current].ctx.getImageData(x, y, 1, 1).data;
        if (d[3] < 128) setTool('eraser'); else setColour([d[0], d[1], d[2], 255]);
    }

    let stroke = null;
    view.addEventListener('contextmenu', (e) => e.preventDefault());
    view.addEventListener('pointerdown', (e) => {
        if (!current || e.button === 1) return;
        const p = pixelAt(e);
        if (!inside(p)) return;
        if (tool === 'clone') {
            if (e.altKey || e.button === 2) { clone.source = p; clone.offset = null; draw(); return; }
            if (!clone.source) { status(T.cloneFirst, 'warn'); return; }
            remember();
            sheets[current].own = true;
            if (!clone.offset || !$('[name="clone-aligned"]').checked) clone.offset = [clone.source[0] - p[0], clone.source[1] - p[1]];
            const { ctx, width, height } = sheets[current];
            clone.from = ctx.getImageData(0, 0, width, height);
            view.setPointerCapture(e.pointerId);
            stroke = { last: p, clone: true };
            stamp(p);
            refresh();
            return;
        }
        if (e.button === 2 || tool === 'picker') { pick(p); return; }
        remember();
        sheets[current].own = true;
        const c = tool === 'eraser' ? [0, 0, 0, 0] : colour;
        if (tool === 'fill') { fill(p, c); refresh(); return; }
        view.setPointerCapture(e.pointerId);
        stroke = { last: p, c };
        line(p, p, c);
        refresh();
    });
    view.addEventListener('pointermove', (e) => {
        const p = pixelAt(e);
        $('.sp-pos').textContent = current && inside(p) ? `x ${p[0]} · y ${p[1]}` : '';
        if (tool === 'clone') { clone.hover = current && inside(p) ? p : null; draw(); }
        if (!stroke) return;
        if (stroke.clone) {
            walk(stroke.last, p, stamp);
            stroke.last = p;
            draw();
            return;
        }
        line(stroke.last, p, stroke.c);
        stroke.last = p;
        draw();
    });
    const endStroke = () => { stroke = null; };
    view.addEventListener('pointerup', endStroke);
    view.addEventListener('pointercancel', endStroke);
    view.addEventListener('pointerleave', () => { clone.hover = null; draw(); });

    // ---- the sheet: download, back to the game's, import

    $('.sp-download').addEventListener('click', (e) => {
        e.currentTarget.href = sheets[current].canvas.toDataURL('image/png');
        e.currentTarget.download = `${current}.png`;
    });
    $('.sp-reset').addEventListener('click', async () => {
        const sheet = sheets[current];
        if (!sheet.own) return;
        remember();
        const img = await loadImage(sheet.game);
        sheet.ctx.clearRect(0, 0, sheet.width, sheet.height);
        sheet.ctx.drawImage(img, 0, 0);
        sheet.own = false;
        refresh();
    });
    $('[name="import"]').addEventListener('change', async (e) => {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;
        const sheet = sheets[current];
        const url = URL.createObjectURL(file);
        try {
            const img = await loadImage(url);
            if (img.width !== sheet.width || img.height !== sheet.height) {
                status(T.size.replace('%w%', sheet.width).replace('%h%', sheet.height), 'error');
                return;
            }
            remember();
            sheet.ctx.clearRect(0, 0, sheet.width, sheet.height);
            sheet.ctx.drawImage(img, 0, 0);
            sheet.own = true;
            refresh();
        } catch (err) {
            status(T.error, 'error');
        } finally {
            URL.revokeObjectURL(url);
        }
    });

    // ---- save

    async function save() {
        if (saving) return false;
        saving = true;
        status(T.saving);
        const own = {};
        for (const [name, sheet] of Object.entries(sheets)) {
            if (sheet.own) own[name] = sheet.canvas.toDataURL('image/png').split(',')[1];
        }
        const body = { name: $('[name="name"]').value, public: $('[name="public"]').checked, sheets: own };
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
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (KEYS[key]) setTool(KEYS[key]);
        else if (key === 'g') $('[data-toggle="grid"]').click();
        else if (key === '+' || key === '=') stepZoom(1);
        else if (key === '-') stepZoom(-1);
    });

    // ---- start

    fetch(root.dataset.json).then((r) => r.json()).then(async (data) => {
        await Promise.all(Object.entries(data.sheets).map(async ([name, info]) => {
            const [img, game] = await Promise.all([loadImage(info.image), info.own ? loadImage(info.game) : null]);
            const canvas = document.createElement('canvas');
            canvas.width = info.width;
            canvas.height = info.height;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(img, 0, 0);
            sheets[name] = { width: info.width, height: info.height, canvas, ctx, own: info.own, game: info.game, colours: colours(game || img) };
        }));
        setTool('pencil');
        select(tabs[0].dataset.sheet);
        status(T.saved, 'ok');
    });
})();
