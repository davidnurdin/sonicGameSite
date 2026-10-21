// Plays the musics and the sound effects of the sound editor (SoundData) with the Web Audio API, and makes WAV files
// of them. A music: 4 voices like the Master System's chip (2 squares, a bass, the noise for the drums), 16 steps a
// bar. A sound effect: an oscillator (or the noise) sliding from one pitch to another, with an envelope.
window.SoundEngine = (() => {
    'use strict';

    const RATE = 44100;
    const midiHz = (n) => 440 * 2 ** ((n - 69) / 12);
    const NAMES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
    const noteName = (n) => NAMES[n % 12] + (Math.floor(n / 12) - 1);

    // per context: the white noise and the 25 % pulse wave
    const kits = new WeakMap();
    function kit(ctx) {
        if (kits.has(ctx)) return kits.get(ctx);
        const noise = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
        const d = noise.getChannelData(0);
        for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
        // a pulse of duty 1/4: its Fourier series
        const real = new Float32Array(64);
        const imag = new Float32Array(64);
        for (let n = 1; n < 64; n++) {
            real[n] = (2 / (n * Math.PI)) * Math.sin(2 * Math.PI * n * 0.25);
            imag[n] = (2 / (n * Math.PI)) * (1 - Math.cos(2 * Math.PI * n * 0.25));
        }
        const k = { noise, pulse: ctx.createPeriodicWave(real, imag) };
        kits.set(ctx, k);
        return k;
    }

    function source(ctx, wave, hz) {
        if (wave === 'noise') {
            const s = ctx.createBufferSource();
            s.buffer = kit(ctx).noise;
            s.loop = true;
            return s;
        }
        const o = ctx.createOscillator();
        if (wave === 'pulse') o.setPeriodicWave(kit(ctx).pulse); else o.type = wave;
        o.frequency.value = hz;
        return o;
    }

    // ---- sound effects

    const sfxLength = (p) => (p.repeat * (p.attack + p.sustain + p.decay) + (p.repeat - 1) * p.gap) / 1000;

    // the sources it starts go in `started` (to stop them)
    function scheduleSfx(ctx, out, p, t0, started = []) {
        const one = (p.attack + p.sustain + p.decay) / 1000;
        const peak = (p.volume / 10) * (p.wave === 'noise' ? 0.5 : 0.3);
        for (let r = 0; r < p.repeat; r++) {
            const t = t0 + r * (one + p.gap / 1000);
            const end = t + one;
            const s = source(ctx, p.wave, p.start);
            const gain = ctx.createGain();
            gain.gain.setValueAtTime(0, t);
            gain.gain.linearRampToValueAtTime(peak, t + Math.max(0.002, p.attack / 1000));
            gain.gain.setValueAtTime(peak, t + (p.attack + p.sustain) / 1000);
            gain.gain.linearRampToValueAtTime(0, end);
            let node = s;
            if (p.wave === 'noise') {
                // the noise's pitch: a filter sliding like the oscillator would
                const filter = ctx.createBiquadFilter();
                filter.type = 'lowpass';
                filter.frequency.setValueAtTime(p.start, t);
                filter.frequency.exponentialRampToValueAtTime(p.end, end);
                s.connect(filter);
                node = filter;
            } else {
                s.frequency.setValueAtTime(p.start, t);
                s.frequency.exponentialRampToValueAtTime(p.end, end);
                if (p.vibrato > 0 && p.speed > 0) {
                    const lfo = ctx.createOscillator();
                    const depth = ctx.createGain();
                    lfo.frequency.value = p.speed;
                    // depth: up to a semitone and a half, in cents
                    depth.gain.value = p.vibrato * 1.5;
                    lfo.connect(depth).connect(s.detune);
                    lfo.start(t);
                    lfo.stop(end);
                    started.push(lfo);
                }
            }
            node.connect(gain).connect(out);
            s.start(t);
            s.stop(end + 0.01);
            started.push(s);
        }
        return started;
    }

    // ---- musics

    const VOICES = ['square', 'pulse', 'triangle', 'noise'];
    // the drums: the noise through a filter, its length (s)
    const DRUMS = [{ type: 'lowpass', hz: 300, len: 0.18, gain: 1 }, { type: 'bandpass', hz: 1800, len: 0.14, gain: 0.8 },
        { type: 'highpass', hz: 7000, len: 0.05, gain: 0.5 }, { type: 'highpass', hz: 4000, len: 0.6, gain: 0.45 }];
    const stepLength = (m) => 60 / m.tempo / 4;
    const musicLength = (m) => m.bars * 16 * stepLength(m);

    // the notes from the step `from` on, started at t0 (step `from` is played at t0)
    function scheduleMusic(ctx, out, m, t0, from = 0, started = []) {
        const step = stepLength(m);
        m.voices.forEach((voice, v) => {
            const wave = VOICES[v];
            const level = (voice.volume / 10) * (wave === 'noise' ? 0.45 : wave === 'triangle' ? 0.32 : 0.14);
            if (level <= 0) return;
            for (const [s, pitch, length] of voice.notes) {
                if (s < from) continue;
                const t = t0 + (s - from) * step;
                const gain = ctx.createGain();
                if (wave === 'noise') {
                    const drum = DRUMS[pitch] || DRUMS[0];
                    const src = source(ctx, 'noise');
                    const filter = ctx.createBiquadFilter();
                    filter.type = drum.type;
                    filter.frequency.value = drum.hz;
                    gain.gain.setValueAtTime(level * drum.gain, t);
                    gain.gain.exponentialRampToValueAtTime(0.001, t + drum.len);
                    src.connect(filter).connect(gain).connect(out);
                    src.start(t);
                    src.stop(t + drum.len + 0.01);
                    started.push(src);
                    continue;
                }
                const end = t + length * step;
                const osc = source(ctx, wave, midiHz(pitch));
                gain.gain.setValueAtTime(0, t);
                gain.gain.linearRampToValueAtTime(level, t + 0.004);
                gain.gain.setValueAtTime(level, Math.max(t + 0.004, end - 0.02));
                gain.gain.linearRampToValueAtTime(0, end);
                osc.connect(gain).connect(out);
                osc.start(t);
                osc.stop(end + 0.01);
                started.push(osc);
            }
        });
        return started;
    }

    // ---- playing: one thing at a time

    let ctx = null;
    let playing = null;   // {master, started: the sources, timer, kind, data, passes: [{start, from}] of a music}

    const context = () => {
        ctx ??= new (window.AudioContext || window.webkitAudioContext)();
        if (ctx.state === 'suspended') ctx.resume();
        return ctx;
    };

    function stop() {
        if (!playing) return;
        clearTimeout(playing.timer);
        for (const s of playing.started) { try { s.stop(); } catch (e) { /* not started yet */ } }
        playing.master.disconnect();
        playing = null;
    }

    // a sound effect, or a music from a step (on and on if loop); onEnd when it is over
    function play(kind, data, { from = 0, loop = false, onEnd = null } = {}) {
        stop();
        const c = context();
        const master = c.createGain();
        master.connect(c.destination);
        const me = { master, started: new Set(), timer: 0, kind, data, passes: [] };
        playing = me;
        const track = (list) => list.forEach((s) => { me.started.add(s); s.addEventListener('ended', () => me.started.delete(s)); });
        const finish = (at) => {
            me.timer = setTimeout(() => { if (playing === me) { stop(); onEnd?.(); } }, Math.max(10, (at - c.currentTime) * 1000 + 100));
        };
        if (kind !== 'music') {
            const t0 = c.currentTime + 0.05;
            track(scheduleSfx(c, master, data, t0));
            finish(t0 + sfxLength(data));
            return me;
        }
        const step = stepLength(data);
        // a pass: the music from the step `first` to its end, played from `start`
        const pass = (start, first) => {
            me.passes.push({ start, from: first });
            if (me.passes.length > 2) me.passes.shift();
            track(scheduleMusic(c, master, data, start, first));
            const end = start + (data.bars * 16 - first) * step;
            if (loop) me.timer = setTimeout(() => { if (playing === me) pass(end, 0); }, Math.max(10, (end - c.currentTime - 0.25) * 1000));
            else finish(end);
        };
        pass(c.currentTime + 0.05, from);
        return me;
    }

    // the step played now (a music), or null
    function position() {
        if (!playing || playing.kind !== 'music') return null;
        const now = ctx.currentTime;
        const pass = [...playing.passes].reverse().find((p) => p.start <= now) || playing.passes[0];
        return Math.max(pass.from, Math.floor(pass.from + (now - pass.start) / stepLength(playing.data))) % (playing.data.bars * 16);
    }

    // one note of a voice (the editor, when a note is put)
    function preview(voice, pitch) {
        const c = context();
        const m = { tempo: 120, bars: 1, voices: VOICES.map((_, v) => ({ volume: v === voice ? 7 : 0, notes: v === voice ? [[0, pitch, 2]] : [] })) };
        const g = c.createGain();
        g.connect(c.destination);
        scheduleMusic(c, g, m, c.currentTime + 0.01);
        setTimeout(() => g.disconnect(), 1000);
    }

    // ---- WAV

    // the sound in an AudioBuffer; loop: a music exactly once round (the game loops it), without the end of its last notes
    async function render(kind, data, { rate = RATE, loop = false } = {}) {
        const length = kind === 'music' ? musicLength(data) + (loop ? 0 : 0.7) : sfxLength(data) + 0.05;
        const off = new OfflineAudioContext(1, Math.ceil(length * rate), rate);
        if (kind === 'music') scheduleMusic(off, off.destination, data, 0); else scheduleSfx(off, off.destination, data, 0);
        return off.startRendering();
    }

    function wav(buffer) {
        const samples = buffer.getChannelData(0);
        const view = new DataView(new ArrayBuffer(44 + samples.length * 2));
        const text = (o, s) => [...s].forEach((ch, i) => view.setUint8(o + i, ch.charCodeAt(0)));
        text(0, 'RIFF');
        view.setUint32(4, 36 + samples.length * 2, true);
        text(8, 'WAVE');
        text(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, buffer.sampleRate, true);
        view.setUint32(28, buffer.sampleRate * 2, true);
        view.setUint16(32, 2, true);
        view.setUint16(34, 16, true);
        text(36, 'data');
        view.setUint32(40, samples.length * 2, true);
        for (let i = 0; i < samples.length; i++) view.setInt16(44 + i * 2, Math.max(-1, Math.min(1, samples[i])) * 0x7FFF, true);
        return new Blob([view], { type: 'audio/wav' });
    }

    // downloads the sound as name.wav
    async function download(kind, data, name) {
        const url = URL.createObjectURL(wav(await render(kind, data)));
        const a = document.createElement('a');
        a.href = url;
        a.download = (name.replace(/[^\w\- ]+/g, '').trim() || 'son') + '.wav';
        document.body.append(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 5000);
    }

    // the page of a sound (not the editor): play / stop
    for (const root of document.querySelectorAll('[data-sound-player]')) {
        const data = JSON.parse(root.dataset.sound);
        const kind = root.dataset.kind;
        const button = root.querySelector('.sd-play');
        const label = button.textContent;
        button.addEventListener('click', () => {
            if (playing) { stop(); button.textContent = label; return; }
            button.textContent = '■';
            play(kind, data, { onEnd: () => { button.textContent = label; } });
        });
        root.querySelector('.sd-wav')?.addEventListener('click', () => download(kind, data, root.dataset.name));
    }

    // what the game plays (SoundData::wav): a WAV at 22050 Hz, in base64
    async function forGame(kind, data) {
        const bytes = new Uint8Array(await wav(await render(kind, data, { rate: 22050, loop: true })).arrayBuffer());
        let s = '';
        for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
        return btoa(s);
    }

    return { play, stop, position, preview, render, download, forGame, midiHz, noteName, stepLength, musicLength, sfxLength, isPlaying: () => !!playing };
})();
