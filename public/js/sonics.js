// The Sonic of the background: one per visitor on the site right now (Mercure presence, see
// PresenceController), each with its own behaviour. The visitor's own Sonic is blue, named, and runs after the
// mouse (on a phone: to where the screen is touched). Behind them, the clouds of Green Hill drift by.
// On the page of a room (data-room): the people of the room instead (js/room.js, event "sonic-room-people").
(() => {
    'use strict';

    const canvas = document.querySelector('canvas.sonics');
    const counter = document.getElementById('presence');
    if (!canvas || !window.EventSource || !window.fetch) return;
    const i18n = JSON.parse(canvas.dataset.i18n || '{}');
    const roomMode = !!canvas.dataset.room;

    // img/sonics.png: 23x32 frames, one row per colour (blue, red, green, yellow, purple, orange)
    const FW = 23, FH = 32, COLORS = 6;
    const IDLE = 0, WALK = [1, 2, 3, 4, 5, 6], RUN = [7, 8, 9, 10], BALL = [11, 12, 13, 14], UP = 15, SKID = 16;
    const MAX = 30;                  // drawn at most (the counter gives the real number)
    const GRAVITY = 2000;            // px/s², CSS pixels
    const still = matchMedia('(prefers-reduced-motion: reduce)');

    const sheet = new Image();
    sheet.src = canvas.dataset.sheet;
    // img/clouds.png: the sky of Green Hill act 1 (8192 px wide, loops)
    const clouds = new Image();
    clouds.src = canvas.dataset.clouds;
    const ctx = canvas.getContext('2d');
    let W = 0, H = 0, S = 2, ground = 0;

    function resize() {
        const dpr = Math.min(window.devicePixelRatio || 1, 3);
        W = innerWidth; H = innerHeight;
        S = W >= 1400 ? 3 : 2;
        canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.imageSmoothingEnabled = false;
        floor();
    }

    // the feet on the grass of the footer when it is on screen, else on the bottom of the screen
    const grass = document.querySelector('footer.ground');
    function floor() {
        const top = grass ? grass.getBoundingClientRect().top + 6 : Infinity;
        ground = Math.min(H - 6, top) - FH * S;
    }
    addEventListener('resize', resize);
    resize();

    const rand = (a, b) => a + Math.random() * (b - a);
    const pick = list => list[Math.floor(Math.random() * list.length)];
    const hash = s => [...s].reduce((h, c) => (h * 31 + c.charCodeAt(0)) >>> 0, 7);

    /**
     * Behaviours: what a Sonic does once arrived.
     *   runner: back and forth at full speed, skids before turning;
     *   jumper: walks and jumps;
     *   spinner: spin dash (charges on the spot, rolls away, slows down);
     *   idler: waits, taps, looks up, walks a few steps elsewhere;
     *   follower: follows the visitor's own Sonic (or another one);
     *   me: the visitor's own Sonic, after the mouse / the finger, else like an idler.
     */
    const BEHAVIOURS = ['runner', 'jumper', 'spinner', 'idler', 'follower'];

    class Sonic {
        constructor(id, name, me) {
            this.id = id; this.name = name; this.me = me;
            this.color = me ? 0 : 1 + hash(id) % (COLORS - 1);
            this.behaviour = me ? 'me' : BEHAVIOURS[hash(id + 'b') % BEHAVIOURS.length];
            this.dir = Math.random() < .5 ? 1 : -1;
            this.x = this.dir > 0 ? -FW * S - rand(0, 80) : W + rand(0, 80);
            this.y = ground; this.vx = this.dir * rand(380, 560); this.vy = 0;
            this.state = 'enter';                                // enter, ground, skid, charge, roll, air, leave
            this.timer = rand(.4, 1.4) * (me ? .5 : 1);           // time left to the next decision
            this.target = rand(.15, .85) * W;
            this.anim = 0; this.pose = IDLE; this.gone = false;
            if (still.matches) { this.x = rand(.05, .9) * W; this.vx = 0; this.state = 'ground'; }
        }

        get w() { return FW * S; }
        get airborne() { return this.y < ground - .5 || this.vy < 0; }

        jump(power = 1) {
            if (this.airborne || this.state === 'leave') return;
            this.vy = -rand(700, 900) * power; this.state = 'air';
        }

        leave() {
            if (this.state === 'leave') return;
            this.state = 'leave';
            if (Math.random() < .5 || this.airborne) { this.vy = -1500; this.vx *= .3; this.up = true; }
            else { this.dir = this.x + this.w / 2 < W / 2 ? -1 : 1; this.vx = this.dir * 700; }
        }

        decide(others, pointer) {
            const center = this.x + this.w / 2;
            switch (this.behaviour) {
                case 'runner':
                    this.target = this.dir > 0 ? W + 50 : -50;      // edge to edge, the skid is in move()
                    this.timer = 9;
                    break;
                case 'jumper':
                    this.target = rand(.05, .95) * W;
                    if (Math.random() < .6) this.jump(rand(.8, 1.2));
                    this.timer = rand(.8, 2);
                    break;
                case 'spinner':
                    if (this.state === 'ground' && Math.abs(this.vx) < 20) {
                        this.dir = center < W / 2 ? (Math.random() < .8 ? 1 : -1) : (Math.random() < .8 ? -1 : 1);
                        this.state = 'charge'; this.timer = rand(.6, 1.2);
                    } else this.timer = rand(1, 2.5);
                    break;
                case 'me':
                    if (pointer !== null) { this.timer = .2; break; }      // move() follows the pointer
                    // fall through: no mouse, a quiet Sonic
                case 'idler':
                    if (Math.random() < .35) { this.target = rand(.1, .9) * W; this.timer = rand(2, 4); }
                    else { this.target = center; this.look = Math.random() < .4; this.timer = rand(2, 5); }
                    break;
                case 'follower': {
                    const lead = others.find(o => o.me && o.state !== 'leave')
                        || others.find(o => o !== this && o.state !== 'leave' && o.behaviour !== 'follower');
                    this.target = lead ? lead.x + lead.w / 2 - lead.dir * this.w * rand(1.2, 2.2) : rand(.2, .8) * W;
                    this.timer = rand(.2, .5);
                    if (Math.random() < .08) this.jump(.7);
                    break;
                }
            }
        }

        move(dt, others, pointer) {
            const center = this.x + this.w / 2;
            if (this.state === 'enter') {
                if (this.dir > 0 ? center > this.target : center < this.target) this.state = 'ground';
            } else if (this.state !== 'leave') {
                this.timer -= dt;
                if (this.timer <= 0 && !this.airborne && this.state !== 'charge' && this.state !== 'roll') this.decide(others, pointer);
            }

            // the visitor's own Sonic: straight after the mouse / the finger, without waiting for a decision
            if (this.me && pointer !== null && (this.state === 'ground' || this.state === 'air')) { this.target = pointer; this.look = false; }

            switch (this.state) {
                case 'charge':                                    // spin dash: revving on the spot
                    this.vx = 0; this.anim += dt * 30;
                    if ((this.timer -= dt) <= 0) { this.state = 'roll'; this.vx = this.dir * rand(900, 1300); }
                    break;
                case 'roll':
                    this.vx -= Math.sign(this.vx) * 420 * dt;
                    if (Math.abs(this.vx) < 60) { this.vx = 0; this.state = 'ground'; this.timer = rand(1, 2.5); }
                    for (const o of others) {                     // bowls the others over
                        if (o !== this && !o.airborne && o.state === 'ground' && Math.abs(o.x - this.x) < this.w * .6) o.jump(.6);
                    }
                    break;
                case 'skid':
                    this.vx -= Math.sign(this.vx) * 1800 * dt;
                    if (Math.abs(this.vx) < 40) { this.dir = -this.dir; this.vx = 0; this.state = 'ground'; this.timer = 0; }
                    break;
                case 'ground': case 'air': {
                    const runner = this.behaviour === 'runner';
                    const chasing = this.me && pointer !== null;
                    const top = runner || chasing ? 640 : this.behaviour === 'follower' ? 420 : this.behaviour === 'jumper' ? 260 : 140;
                    const gap = this.target - center;
                    let want = Math.abs(gap) < 8 ? 0 : Math.sign(gap) * Math.min(top, Math.abs(gap) * 3 + 40);
                    // turning back at full speed: a skid first, like in the game
                    if (chasing && this.state === 'ground' && Math.abs(this.vx) > 350 && want * this.vx < 0) { this.state = 'skid'; break; }
                    if (runner && this.state === 'ground' && Math.abs(this.vx) > 300 &&
                        (this.vx > 0 ? this.x + this.w > W - 90 : this.x < 90)) { this.state = 'skid'; break; }
                    this.vx += Math.max(-900 * dt, Math.min(900 * dt, want - this.vx));
                    if (Math.abs(this.vx) > 5) this.dir = Math.sign(this.vx);
                    if (this.look && Math.abs(this.vx) > 5) this.look = false;
                    break;
                }
            }

            this.x += this.vx * dt;
            if (this.state !== 'leave') this.x = Math.max(-this.w, Math.min(W, this.x));
            if (this.airborne || this.state === 'leave') {
                this.vy += GRAVITY * dt * (this.up ? 0 : 1);
                this.y += this.vy * dt;
                if (this.y >= ground && this.state !== 'leave') {
                    this.y = ground; this.vy = 0;
                    if (this.state === 'air') this.state = 'ground';
                }
            }
            if (this.state === 'leave' && (this.y < -FH * S * 2 || this.x < -this.w * 2 || this.x > W + this.w)) this.gone = true;

            // the frame, from the state and the speed (like the game)
            const speed = Math.abs(this.vx);
            if (this.state === 'charge' || this.state === 'roll' || this.airborne || this.up) {
                this.anim += dt * (this.state === 'charge' ? 0 : 8 + speed / 40);
                this.pose = BALL[Math.floor(this.anim) % BALL.length];
            } else if (this.state === 'skid') this.pose = SKID;
            else if (speed > 330) { this.anim += dt * (10 + speed / 50); this.pose = RUN[Math.floor(this.anim) % RUN.length]; }
            else if (speed > 15) { this.anim += dt * (5 + speed / 25); this.pose = WALK[Math.floor(this.anim) % WALK.length]; }
            else this.pose = this.look ? UP : IDLE;
        }

        draw() {
            const x = Math.round(this.x), y = Math.round(this.y), w = this.w, h = FH * S;
            ctx.save();
            if (this.dir < 0) { ctx.translate(x + w, y); ctx.scale(-1, 1); } else ctx.translate(x, y);
            ctx.drawImage(sheet, this.pose * FW, this.color * FH, FW, FH, 0, 0, w, h);
            ctx.restore();
            const label = this.me ? (this.name || i18n.me || 'TOI') : this.name;
            if (label) {
                ctx.font = '700 11px system-ui, sans-serif';
                ctx.textAlign = 'center';
                ctx.lineWidth = 3; ctx.strokeStyle = 'rgba(8, 20, 70, .85)'; ctx.fillStyle = this.me ? '#ffd400' : '#ffffff';
                ctx.strokeText(label, x + w / 2, y - 4); ctx.fillText(label, x + w / 2, y - 4);
            }
        }
    }

    // --- the visitors (Mercure presence) ---

    const people = new Map();          // subscriber -> name (null: not signed in)
    const sonics = new Map();          // subscriber -> Sonic
    let me = { key: null, id: null, name: null };
    let config = null, source = null, retry = 0;

    // one Sonic per person: a name (an account) open in several pages, or a page just reopened whose old
    // subscription the hub hasn't closed yet, is one Sonic; the visitors without an account, one per page
    const personOf = id => people.get(id) ? 'n:' + people.get(id) : id;

    function render() {
        const persons = new Map();       // person -> name
        for (const [id, name] of people) persons.set(personOf(id), name);
        const mine = me.id !== null && people.has(me.id) ? personOf(me.id) : null;
        if (counter) {
            const n = persons.size;
            counter.hidden = n === 0;
            counter.querySelector('b').textContent = n;
            counter.querySelector('.label').textContent = n > 1 ? i18n.many : i18n.one;
        }
        if (roomMode) return;
        me.person = mine;
        const ids = [...persons.keys()].sort((a, b) => (b === mine) - (a === mine)).slice(0, MAX);
        for (const id of ids) {
            if (!sonics.has(id)) sonics.set(id, new Sonic(id, persons.get(id), id === mine));
        }
        for (const [id, sonic] of sonics) if (!ids.includes(id)) sonic.leave();
    }

    // the page of a room: its players and the members of the site in it
    addEventListener('sonic-room-people', e => {
        const list = e.detail.slice(0, MAX);
        const ids = list.map(p => p.id);
        for (const p of list) {
            if (!sonics.has(p.id)) sonics.set(p.id, new Sonic(p.id, p.name, !!p.me));
            if (p.me) me.room = p.id;
        }
        for (const [id, sonic] of sonics) if (!ids.includes(id)) sonic.leave();
    });

    function apply(sub) {
        if (sub.match !== config.topic) return;
        const payload = sub.payload || {};
        if (payload.k && payload.k === me.key) me.id = sub.subscriber;
        if (sub.active) people.set(sub.subscriber, payload.name || null);
        else people.delete(sub.subscriber);
    }

    async function snapshot() {
        const response = await fetch(config.list, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const data = await response.json();
        people.clear();
        for (const sub of data.subscriptions || []) if (sub.active !== false) apply(sub);
        render();
    }

    async function connect() {
        try {
            const response = await fetch(canvas.dataset.presence, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(response.status);
            config = await response.json();
            me.key = config.key;
        } catch (e) {
            setTimeout(connect, Math.min(60000, 5000 * ++retry));
            return;
        }
        const url = new URL(config.hub, location.href);
        url.searchParams.append('match', config.topic);
        url.searchParams.append('match_urlpattern', config.events);
        source = new EventSource(url, { withCredentials: true });
        source.addEventListener('mercure', e => { apply(JSON.parse(e.data)); render(); });
        source.onopen = () => { retry = 0; snapshot().catch(() => {}); };
        source.onerror = () => {
            // closed for good (token expired, hub restarted): a new token, then again
            if (source.readyState === EventSource.CLOSED) { source = null; setTimeout(connect, Math.min(60000, 2000 * ++retry)); }
        };
    }
    connect();
    // a lost event (sleeping phone, network) must not leave a Sonic forever: the list again now and then
    setInterval(() => { if (source && source.readyState === EventSource.OPEN && !document.hidden) snapshot().catch(() => {}); }, 60000);

    // --- the animation ---

    // where the visitor's Sonic runs: the mouse while it is in the window, the last touch for a few seconds
    let pointer = null, touchTimer = 0;
    addEventListener('pointermove', e => { if (e.pointerType === 'mouse') pointer = e.clientX; }, { passive: true });
    document.addEventListener('mouseleave', () => { pointer = null; });
    // a tap on a Sonic makes him jump (the canvas lets the clicks through to the page)
    addEventListener('pointerdown', e => {
        if (e.pointerType !== 'mouse') {
            pointer = e.clientX;
            clearTimeout(touchTimer);
            touchTimer = setTimeout(() => { pointer = null; }, 4000);
        }
        for (const sonic of sonics.values()) {
            if (e.clientX >= sonic.x && e.clientX <= sonic.x + sonic.w && e.clientY >= sonic.y && e.clientY <= sonic.y + FH * S) sonic.jump(1.1);
        }
    }, { passive: true });

    // the clouds: drifting left slowly, and a little slower than the page when it scrolls (parallax)
    function drawClouds(now) {
        if (!clouds.complete || !clouds.naturalWidth) return;
        const cw = clouds.naturalWidth, ch = clouds.naturalHeight, c = S === 3 ? 3 : 2;
        const y = Math.round(40 - scrollY * .3);
        if (y + ch * c < 0) return;
        let sx = (still.matches ? 0 : now * .004) % cw;   // source pixels: 4 per second, 8 or 12 on screen
        ctx.globalAlpha = .85;
        for (let dx = 0; dx < W;) {
            const w = Math.min(cw - sx, Math.ceil((W - dx) / c));
            ctx.drawImage(clouds, Math.floor(sx), 0, w, ch, dx, y, w * c, ch * c);
            dx += w * c; sx = 0;
        }
        ctx.globalAlpha = 1;
    }

    let last = performance.now();
    function frame(now) {
        const dt = Math.min(.05, (now - last) / 1000);
        last = now;
        ctx.clearRect(0, 0, W, H);
        floor();
        drawClouds(now);
        if (sheet.complete && sheet.naturalWidth) {
            const list = [...sonics.values()];
            for (const sonic of list) {
                if (!still.matches) sonic.move(dt, list, pointer);
                else if (sonic.state === 'leave') sonic.gone = true;
                if (sonic.gone) { sonics.delete(sonic.id); continue; }
                if (sonic.state !== 'leave') sonic.y = Math.min(sonic.y, ground);
                sonic.draw();
            }
            // own Sonic in front
            const mine = sonics.get(roomMode ? me.room : me.person);
            if (mine) mine.draw();
        }
        requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
})();
