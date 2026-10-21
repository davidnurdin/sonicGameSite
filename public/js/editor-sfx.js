// The sound effect editor (/son/{id}/editer, a sound effect): the settings of a generator (SoundData::sfx), starting
// from a preset or at random; each change is heard and drawn. Plays with SoundEngine; saves with POST /son/{id}.
(() => {
    'use strict';

    const root = document.getElementById('sound-editor');
    if (!root || root.dataset.kind !== 'sfx') return;
    const E = window.SoundEngine;
    const T = JSON.parse(root.dataset.i18n);
    const $ = (sel) => root.querySelector(sel);
    const KEYS = ['start', 'end', 'attack', 'sustain', 'decay', 'volume', 'vibrato', 'speed', 'repeat', 'gap'];
    const PRESETS = {
        jump: { wave: 'square', start: 300, end: 900, attack: 0, sustain: 60, decay: 120, volume: 6, vibrato: 0, speed: 0, repeat: 1, gap: 0 },
        ring: { wave: 'pulse', start: 1800, end: 2600, attack: 0, sustain: 40, decay: 260, volume: 5, vibrato: 0, speed: 0, repeat: 2, gap: 0 },
        spring: { wave: 'triangle', start: 200, end: 1400, attack: 0, sustain: 30, decay: 260, volume: 8, vibrato: 60, speed: 22, repeat: 1, gap: 0 },
        hit: { wave: 'noise', start: 3000, end: 300, attack: 0, sustain: 20, decay: 180, volume: 8, vibrato: 0, speed: 0, repeat: 1, gap: 0 },
        explosion: { wave: 'noise', start: 1200, end: 60, attack: 0, sustain: 80, decay: 900, volume: 10, vibrato: 0, speed: 0, repeat: 1, gap: 0 },
        laser: { wave: 'sawtooth', start: 2400, end: 200, attack: 0, sustain: 20, decay: 200, volume: 5, vibrato: 0, speed: 0, repeat: 1, gap: 0 },
        powerup: { wave: 'square', start: 400, end: 1600, attack: 0, sustain: 40, decay: 60, volume: 5, vibrato: 30, speed: 30, repeat: 4, gap: 20 },
        blip: { wave: 'pulse', start: 880, end: 880, attack: 0, sustain: 40, decay: 40, volume: 5, vibrato: 0, speed: 0, repeat: 1, gap: 0 },
    };

    let sfx = JSON.parse(root.dataset.sound);
    let dirty = false;
    let saving = false;
    const undo = [];
    const redo = [];

    const status = (text, kind = '') => { const el = $('.ed-status'); el.textContent = text; el.dataset.kind = kind; };
    const changed = () => { dirty = true; status(T.unsaved, 'warn'); };
    const remember = () => {
        undo.push(JSON.stringify(sfx));
        if (undo.length > 100) undo.shift();
        redo.length = 0;
        changed();
    };
    const restore = (from, to) => {
        if (!from.length) return;
        to.push(JSON.stringify(sfx));
        sfx = JSON.parse(from.pop());
        changed();
        fields();
        hear();
    };

    // ---- the fields

    const wave = $('[name="wave"]');
    function fields() {
        wave.value = sfx.wave;
        for (const key of KEYS) {
            const input = $(`[name="${key}"]`);
            input.value = sfx[key];
            input.previousElementSibling.textContent = sfx[key];
        }
    }
    wave.addEventListener('change', () => { remember(); sfx.wave = wave.value; hear(); });
    for (const key of KEYS) {
        const input = $(`[name="${key}"]`);
        input.addEventListener('pointerdown', remember);
        input.addEventListener('keydown', remember);
        input.addEventListener('input', () => {
            sfx[key] = Number(input.value);
            input.previousElementSibling.textContent = sfx[key];
            changed();
            drawWave();
        });
        // heard once the slider is let go
        input.addEventListener('change', hear);
    }

    root.querySelectorAll('[data-preset]').forEach((b) => b.addEventListener('click', () => {
        remember();
        sfx = b.dataset.preset === 'random' ? random() : { ...PRESETS[b.dataset.preset] };
        fields();
        hear();
    }));
    function random() {
        const r = (min, max) => Math.round(min + Math.random() * (max - min));
        const waves = ['square', 'pulse', 'triangle', 'sawtooth', 'sine', 'noise'];
        return { wave: waves[r(0, waves.length - 1)], start: r(80, 3000), end: r(80, 3000), attack: r(0, 40), sustain: r(10, 200),
            decay: r(40, 500), volume: r(4, 8), vibrato: Math.random() < 0.3 ? r(10, 80) : 0, speed: r(4, 30),
            repeat: Math.random() < 0.25 ? r(2, 4) : 1, gap: r(0, 60) };
    }

    // ---- what it looks like: the rendered sound

    const canvas = $('.sfx-wave');
    const g = canvas.getContext('2d');
    let drawing = 0;
    async function drawWave() {
        const me = ++drawing;
        const buffer = await E.render('sfx', sfx);
        if (me !== drawing) return;
        const data = buffer.getChannelData(0);
        const { width, height } = canvas;
        g.fillStyle = '#0a1028';
        g.fillRect(0, 0, width, height);
        g.fillStyle = '#ffd400';
        const per = Math.max(1, Math.floor(data.length / width));
        for (let x = 0; x < width; x++) {
            let min = 0, max = 0;
            for (let i = x * per; i < Math.min(data.length, (x + 1) * per); i++) { min = Math.min(min, data[i]); max = Math.max(max, data[i]); }
            const scale = height / 2 / 0.5;
            g.fillRect(x, height / 2 - max * scale, 1, Math.max(1, (max - min) * scale));
        }
        g.fillStyle = '#cfd8ff';
        g.font = '600 12px system-ui, sans-serif';
        g.fillText(`${Math.round(E.sfxLength(sfx) * 1000)} ms`, 8, 16);
    }
    function hear() {
        E.play('sfx', sfx);
        drawWave();
    }
    $('.sd-play').addEventListener('click', hear);
    $('.sd-wav').addEventListener('click', () => E.download('sfx', sfx, $('[name="name"]').value));

    // ---- save

    async function save() {
        if (saving) return false;
        saving = true;
        status(T.saving);
        try {
            // with what the game plays
            const body = { name: $('[name="name"]').value, public: $('[name="public"]').checked, data: sfx,
                audio: await E.forGame('sfx', sfx) };
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

    addEventListener('keydown', (e) => {
        if (e.target.matches('input[type=text], select, textarea')) return;
        const key = e.key.toLowerCase();
        if ((e.ctrlKey || e.metaKey) && key === 's') { e.preventDefault(); save(); return; }
        if ((e.ctrlKey || e.metaKey) && key === 'z') { e.preventDefault(); restore(e.shiftKey ? redo : undo, e.shiftKey ? undo : redo); return; }
        if ((e.ctrlKey || e.metaKey) && key === 'y') { e.preventDefault(); restore(redo, undo); return; }
        if (key === ' ' && !e.ctrlKey && !e.metaKey) { e.preventDefault(); hear(); }
    });

    fields();
    drawWave();
    status(T.saved, 'ok');
})();
