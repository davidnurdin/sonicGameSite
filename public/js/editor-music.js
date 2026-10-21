// The music editor (/son/{id}/editer, a music): a piano roll a voice at a time, on the 4 voices of the Master
// System's chip (SoundData::VOICES). A voice plays one note at a time, as on the chip: a new note cuts the one
// under it. Plays with SoundEngine; saves with POST /son/{id} (JSON: {name, public, data}, see SoundData::music).
(() => {
    'use strict';

    const root = document.getElementById('sound-editor');
    if (!root || root.dataset.kind !== 'music') return;
    const E = window.SoundEngine;
    const T = JSON.parse(root.dataset.i18n);
    const DRUMS = JSON.parse(root.dataset.drums);
    const $ = (sel) => root.querySelector(sel);
    const LOW = 24;
    const HIGH = 96;
    const W = 16;            // a step
    const H = 12;            // a note (the drums: DH)
    const DH = 28;
    const KEYS = 46;         // the keys on the left
    const RULER = 20;        // the bars on top
    const COLOURS = ['#ffd400', '#3fd0ff', '#ff6b9a', '#9cff6b'];

    let music = JSON.parse(root.dataset.sound);
    let voice = 0;
    let dirty = false;
    let saving = false;
    const undo = [];
    const redo = [];

    const status = (text, kind = '') => { const el = $('.ed-status'); el.textContent = text; el.dataset.kind = kind; };
    const changed = () => { dirty = true; status(T.unsaved, 'warn'); };
    const remember = () => {
        undo.push(JSON.stringify(music));
        if (undo.length > 100) undo.shift();
        redo.length = 0;
        changed();
    };
    const restore = (from, to) => {
        if (!from.length) return;
        to.push(JSON.stringify(music));
        music = JSON.parse(from.pop());
        changed();
        fields();
        layout();
    };
    const steps = () => music.bars * 16;
    const drums = () => voice === 3;
    const rows = () => drums() ? DRUMS.length : HIGH - LOW + 1;
    const rowHeight = () => drums() ? DH : H;
    // row -> pitch (the high notes on top)
    const pitchOf = (row) => drums() ? row : HIGH - row;
    const rowOf = (pitch) => drums() ? pitch : HIGH - pitch;

    // ---- the view: a canvas the size of the box, kept in place (sticky), and a sizer for the scrollbars

    const box = $('.mu-box');
    const canvas = $('.mu-roll');
    const ctx = canvas.getContext('2d');
    const sizer = document.createElement('div');
    sizer.className = 'mu-sizer';
    box.append(sizer);
    let pending = false;

    function layout() {
        sizer.style.width = `${KEYS + steps() * W}px`;
        sizer.style.height = `${RULER + rows() * rowHeight()}px`;
        resize();
    }
    function resize() {
        const dpr = window.devicePixelRatio || 1;
        canvas.style.width = `${box.clientWidth}px`;
        canvas.style.height = `${box.clientHeight}px`;
        canvas.width = Math.round(box.clientWidth * dpr);
        canvas.height = Math.round(box.clientHeight * dpr);
        draw();
    }
    new ResizeObserver(resize).observe(box);
    box.addEventListener('scroll', () => draw(), { passive: true });

    function draw() {
        if (pending) return;
        pending = true;
        requestAnimationFrame(render);
    }
    function render() {
        pending = false;
        const dpr = window.devicePixelRatio || 1;
        const w = canvas.width / dpr;
        const h = canvas.height / dpr;
        const sx = box.scrollLeft;
        const sy = box.scrollTop;
        const rh = rowHeight();
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = '#0a1028';
        ctx.fillRect(0, 0, w, h);
        // the grid: a row a note (the black keys darker), a line a step, a beat, a bar
        const first = Math.max(0, Math.floor(sy / rh));
        const last = Math.min(rows() - 1, Math.ceil((sy + h) / rh));
        for (let row = first; row <= last; row++) {
            const y = RULER + row * rh - sy;
            const black = !drums() && [1, 3, 6, 8, 10].includes(pitchOf(row) % 12);
            ctx.fillStyle = black ? '#0d1533' : '#131d45';
            ctx.fillRect(KEYS, y, Math.min(w - KEYS, steps() * W - sx), rh - 1);
        }
        const s0 = Math.max(0, Math.floor(sx / W));
        const s1 = Math.min(steps(), Math.ceil((sx + w) / W));
        for (let s = s0; s <= s1; s++) {
            const x = KEYS + s * W - sx;
            ctx.fillStyle = s % 16 === 0 ? 'rgba(255, 255, 255, .35)' : s % 4 === 0 ? 'rgba(255, 255, 255, .14)' : 'rgba(255, 255, 255, .05)';
            ctx.fillRect(x, RULER, 1, h - RULER);
        }
        // the notes: the other voices pale (the tonal ones), then this one
        const noteRect = (s, pitch, length, v) => {
            const row = v === 3 ? pitch : HIGH - pitch;
            const height = v === 3 ? DH : H;
            return [KEYS + s * W - sx + 1, RULER + row * height - sy + 1, length * W - 2, height - 3];
        };
        if (!drums()) {
            music.voices.forEach((other, v) => {
                if (v === voice || v === 3) return;
                ctx.fillStyle = COLOURS[v] + '40';
                for (const [s, p, l] of other.notes) ctx.fillRect(...noteRect(s, p, l, v));
            });
        }
        ctx.fillStyle = COLOURS[voice];
        ctx.strokeStyle = '#0a1028';
        for (const [s, p, l] of music.voices[voice].notes) {
            const r = noteRect(s, p, drums() ? 1 : l, voice);
            ctx.beginPath();
            ctx.roundRect(...r, 3);
            ctx.fill();
        }
        // where it plays
        const at = E.position();
        if (at !== null) {
            ctx.fillStyle = '#fff';
            ctx.fillRect(KEYS + at * W - sx, RULER, 2, h - RULER);
            draw();
        }
        // the keys
        ctx.fillStyle = '#0a1440';
        ctx.fillRect(0, RULER, KEYS, h - RULER);
        ctx.font = '600 10px system-ui, sans-serif';
        ctx.textBaseline = 'middle';
        for (let row = first; row <= last; row++) {
            const y = RULER + row * rh - sy;
            const pitch = pitchOf(row);
            if (drums()) {
                ctx.fillStyle = '#cfd8ff';
                ctx.fillText(DRUMS[row].slice(0, 9), 4, y + rh / 2);
                continue;
            }
            const black = [1, 3, 6, 8, 10].includes(pitch % 12);
            ctx.fillStyle = black ? '#1b1b1b' : '#e9edf7';
            ctx.fillRect(black ? 0 : 0, y, black ? KEYS * 0.62 : KEYS - 2, rh - 1);
            if (pitch % 12 === 0) {
                ctx.fillStyle = '#10183a';
                ctx.fillText(E.noteName(pitch), KEYS - 26, y + rh / 2);
            }
        }
        // the bars
        ctx.fillStyle = '#0a1440';
        ctx.fillRect(0, 0, w, RULER);
        ctx.fillStyle = '#ffd400';
        ctx.font = '700 11px system-ui, sans-serif';
        for (let b = Math.floor(s0 / 16); b * 16 <= s1 && b < music.bars; b++) {
            const x = KEYS + b * 16 * W - sx;
            if (x >= KEYS - 1) ctx.fillText(String(b + 1), x + 3, RULER / 2);
        }
    }

    // ---- notes

    // a voice plays one note at a time: the note [s, length) cuts what is under it
    function place(v, s, pitch, length) {
        const notes = music.voices[v].notes;
        const end = s + length;
        music.voices[v].notes = notes.flatMap((n) => {
            const [ns, np, nl] = n;
            if (v === 3) return ns === s ? [] : [n];
            if (ns >= end || ns + nl <= s) return [n];
            if (ns < s) return [[ns, np, s - ns]];
            return [];
        });
        music.voices[v].notes.push([s, pitch, v === 3 ? 1 : length]);
        music.voices[v].notes.sort((a, b) => a[0] - b[0]);
    }
    const noteAt = (s, pitch) => music.voices[voice].notes.find(([ns, np, nl]) => np === pitch && s >= ns && s < ns + (drums() ? 1 : nl));
    const cellAt = (e) => {
        const r = box.getBoundingClientRect();
        const x = e.clientX - r.left + box.scrollLeft - KEYS;
        const y = e.clientY - r.top + box.scrollTop - RULER;
        return { s: Math.floor(x / W), row: Math.floor(y / rowHeight()), ruler: e.clientY - r.top < RULER, keys: e.clientX - r.left < KEYS };
    };

    let drag = null;
    box.addEventListener('contextmenu', (e) => e.preventDefault());
    box.addEventListener('pointerdown', (e) => {
        if (e.button === 1) return;
        const { s, row, ruler, keys } = cellAt(e);
        if (s < 0 || s >= steps()) { if (keys && row >= 0 && row < rows()) E.preview(voice, pitchOf(row)); return; }
        if (ruler) { play(s); return; }
        if (row < 0 || row >= rows()) return;
        const pitch = pitchOf(row);
        const hit = noteAt(s, pitch);
        if (hit || e.button === 2) {
            if (!hit) return;
            remember();
            music.voices[voice].notes = music.voices[voice].notes.filter((n) => n !== hit);
            draw();
            return;
        }
        remember();
        place(voice, s, pitch, 1);
        E.preview(voice, pitch);
        drag = { s, pitch };
        box.setPointerCapture(e.pointerId);
        draw();
    });
    box.addEventListener('pointermove', (e) => {
        const { s, row } = cellAt(e);
        const inside = s >= 0 && s < steps() && row >= 0 && row < rows();
        $('.sp-pos').textContent = inside ? `${Math.floor(s / 16) + 1}.${Math.floor((s % 16) / 4) + 1}.${(s % 4) + 1} · ${drums() ? DRUMS[row] : E.noteName(pitchOf(row))}` : '';
        if (!drag || drums()) return;
        const length = Math.max(1, Math.min(64, Math.min(steps() - 1, s) - drag.s + 1));
        place(voice, drag.s, drag.pitch, length);
        draw();
    });
    const endDrag = () => { drag = null; };
    box.addEventListener('pointerup', endDrag);
    box.addEventListener('pointercancel', endDrag);

    // ---- voices

    const voiceRows = root.querySelectorAll('.mu-voice');
    function setVoice(v) {
        const before = rowHeight();
        const middle = (box.scrollTop + box.clientHeight / 2 - RULER) / before;
        voice = v;
        voiceRows.forEach((r) => r.classList.toggle('on', Number(r.dataset.voice) === v));
        layout();
        // the tonal voices: around the same notes; the drums: from the top
        box.scrollTop = drums() ? 0 : Math.max(0, middle * rowHeight() + RULER - box.clientHeight / 2);
    }
    voiceRows.forEach((r) => {
        const v = Number(r.dataset.voice);
        r.querySelector('i').style.background = COLOURS[v];
        r.querySelector('button').addEventListener('click', () => setVoice(v));
        const range = r.querySelector('input');
        range.addEventListener('pointerdown', remember);
        range.addEventListener('keydown', remember);
        range.addEventListener('input', () => { music.voices[v].volume = Number(range.value); changed(); });
    });

    // ---- tempo, bars

    const tempo = $('[name="tempo"]');
    const bars = $('[name="bars"]');
    function fields() {
        tempo.value = music.tempo;
        bars.value = music.bars;
        voiceRows.forEach((r) => { r.querySelector('input').value = music.voices[Number(r.dataset.voice)].volume; });
    }
    tempo.addEventListener('change', () => {
        const v = Math.max(40, Math.min(300, Math.round(Number(tempo.value)) || 120));
        if (v === music.tempo) { tempo.value = v; return; }
        remember();
        music.tempo = v;
        tempo.value = v;
    });
    bars.addEventListener('change', () => {
        const v = Math.max(1, Math.min(32, Math.round(Number(bars.value)) || 4));
        if (v === music.bars) { bars.value = v; return; }
        remember();
        music.bars = v;
        bars.value = v;
        // the notes past the end go, the long ones are cut
        for (const vo of music.voices) {
            vo.notes = vo.notes.filter(([s]) => s < steps()).map(([s, p, l]) => [s, p, Math.min(l, steps() - s)]);
        }
        layout();
    });

    // ---- play

    const playButton = $('.sd-play');
    const loop = $('[name="loop"]');
    function play(from = 0) {
        E.play('music', music, { from, loop: loop.checked, onEnd: () => { playButton.textContent = '▶'; draw(); } });
        playButton.textContent = '■';
        draw();
    }
    function toggle() {
        if (E.isPlaying()) { E.stop(); playButton.textContent = '▶'; draw(); } else play(0);
    }
    playButton.addEventListener('click', toggle);
    $('.sd-wav').addEventListener('click', () => E.download('music', music, $('[name="name"]').value));

    // ---- save

    async function save() {
        if (saving) return false;
        saving = true;
        status(T.saving);
        const body = { name: $('[name="name"]').value, public: $('[name="public"]').checked, data: music };
        try {
            const r = await fetch(root.dataset.save, { method: 'POST', body: JSON.stringify(body),
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.token } });
            if (!r.ok) throw new Error(String(r.status));
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

    addEventListener('keydown', (e) => {
        if (e.target.matches('input[type=text], input[type=number], select, textarea')) return;
        const key = e.key.toLowerCase();
        if ((e.ctrlKey || e.metaKey) && key === 's') { e.preventDefault(); save(); return; }
        if ((e.ctrlKey || e.metaKey) && key === 'z') { e.preventDefault(); restore(e.shiftKey ? redo : undo, e.shiftKey ? undo : redo); return; }
        if ((e.ctrlKey || e.metaKey) && key === 'y') { e.preventDefault(); restore(redo, undo); return; }
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (key === ' ') { e.preventDefault(); toggle(); }
        else if (key >= '1' && key <= '4') setVoice(Number(key) - 1);
        else if (key === 'l') loop.checked = !loop.checked;
    });

    // ---- start: the melody, around C5

    fields();
    setVoice(0);
    box.scrollTop = Math.max(0, rowOf(76) * H + RULER - box.clientHeight / 2);
    status(T.saved, 'ok');
})();
