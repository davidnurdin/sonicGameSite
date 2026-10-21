// The page of a room: it follows the room on the game's relay server as a "watcher" (php-wasm-network,
// Protocol.php): who is in the game, who is on this page, the chat (the same as in the game's menus), the match
// chosen. JOUER opens the game straight into the room; the game comes back here after the match.
//
// The messages are the game's (sonicGamePhp, Online.php): one byte of type, then the payload. A watcher only
// sends HELLO and CHAT to the game (it ignores anything else from the site), and SITE between pages.
(() => {
    'use strict';

    const root = document.getElementById('room');
    if (!root || !window.WebSocket) return;
    const cfg = JSON.parse(root.dataset.config);
    const T = JSON.parse(root.dataset.i18n);
    const $ = id => document.getElementById(id);

    const HELLO = 1, ROSTER = 2, START = 3, CHAT = 7, SITE = 8;
    const BROADCAST = 255, FIRST_WATCHER = 128;
    const CHAT_LENGTH = 30, CHAT_LINES = 60;

    let ws = null, code = cfg.room, me = -1, host = -1, isPublic = null, closedForGood = false, retry = 0;
    let mode = cfg.create ? cfg.create.mode : 'race';
    let level = cfg.create ? cfg.create.level : 1;
    // a level of the site's editor, chosen in the game (36): its name, sent by the host
    const SITE_LEVEL = 36;
    let siteName = '';
    let state = 'lobby';
    const players = new Map();   // peer -> name (in the game)
    const sites = new Map();     // watcher -> name ('' : a visitor without an account)
    let lastChat = 0;

    const encoder = new TextEncoder(), decoder = new TextDecoder();
    // the game's rules (Online::cleanName, cleanChat): its font has capitals, digits and a few signs
    const cleanName = (name, peer) => {
        const n = String(name || '').toUpperCase().replace(/[^A-Z0-9 ]/g, '').trim().slice(0, 8);
        return n || (peer === undefined ? '' : 'J' + (peer + 1));
    };
    const cleanChat = text => String(text || '').toUpperCase().replace(/[^A-Z0-9 .!?-]/g, '').replace(/ +/g, ' ').trim().slice(0, CHAT_LENGTH).trim();
    const fill = (text, values) => text.replace(/%(\w+)%/g, (m, k) => values[k] ?? m);
    const roomUrl = () => cfg.site + cfg.roomPath.replace('CODE', code);

    // --- the relay ---

    function control(message) {
        if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(message));
    }

    function send(type, payload, to = BROADCAST) {
        if (!ws || ws.readyState !== WebSocket.OPEN) return;
        const body = encoder.encode(typeof payload === 'string' ? payload : JSON.stringify(payload));
        const frame = new Uint8Array(2 + body.length);
        frame[0] = to; frame[1] = type; frame.set(body, 2);
        ws.send(frame);
    }

    function hello(to = BROADCAST, re = false) {
        send(HELLO, { name: cfg.name || '', site: true, ...(re ? { re: true } : {}) }, to);
    }

    /** What the list of rooms shows while nobody is in the game (then the game's host says it). */
    function publish() {
        if (host !== -1 || me < FIRST_WATCHER) return;
        control({ t: 'info', data: { names: [], sites: [...sites.values()].filter(Boolean), mode, level, state: 'lobby', v: cfg.version } });
    }

    function connect() {
        status(T.connecting);
        ws = new WebSocket(cfg.relay);
        ws.binaryType = 'arraybuffer';
        ws.onmessage = e => typeof e.data === 'string' ? onControl(JSON.parse(e.data)) : onBinary(new Uint8Array(e.data));
        ws.onclose = () => {
            ws = null;
            me = -1;
            if (closedForGood) return;
            status(T.lost);
            setTimeout(connect, Math.min(15000, 1000 * 2 ** retry++));
        };
    }

    function onControl(m) {
        switch (m.t) {
            case 'hello':
                if (code) control({ t: 'watch', room: code });
                else control({ t: 'create', max: 6, meta: { game: 'sonic', v: cfg.version }, public: !!cfg.create.public, watch: true });
                break;
            case 'room':
                if ((m.meta || {}).game !== 'sonic') return fail(T.notSonic);
                if ((m.meta || {}).v !== cfg.version) return fail(T.version);
                retry = 0;
                code = m.room; me = m.peer; host = m.host; isPublic = !!m.public;
                players.clear(); sites.clear();
                for (const p of m.peers || []) if (p < FIRST_WATCHER) players.set(p, 'J' + (p + 1));
                for (const w of m.watchers || []) if (w !== me) sites.set(w, '');
                if (cfg.name) sites.set(me, cfg.name);
                if (!cfg.room) {
                    // created here: the address of the room, to share or reload
                    cfg.room = code;
                    history.replaceState(null, '', cfg.roomPath.replace('CODE', code));
                }
                hello();
                publish();
                render();
                break;
            case 'peer-join':
                players.set(m.peer, 'J' + (m.peer + 1));
                hello(m.peer, true);
                render();
                break;
            case 'watcher-join':
                sites.set(m.peer, '');
                hello(m.peer, true);
                render();
                break;
            case 'peer-leave':
                if (m.peer >= FIRST_WATCHER) {
                    if (sites.get(m.peer)) line(null, fill(T.leftSite, { name: sites.get(m.peer) }));
                    sites.delete(m.peer);
                    publish();
                } else {
                    if (players.has(m.peer)) line(null, fill(T.left, { name: players.get(m.peer) }));
                    players.delete(m.peer);
                    if (!players.size) state = 'lobby';
                }
                render();
                break;
            case 'host':
                host = m.peer;
                if (host === -1) { state = 'lobby'; publish(); }
                render();
                break;
            case 'error':
                if (m.code === 'no-room') fail(T.noRoom);
                else if (m.code === 'room-full') fail(T.full);
                break;
        }
    }

    function onBinary(bytes) {
        if (bytes.length < 2) return;
        const from = bytes[0], type = bytes[1];
        const text = decoder.decode(bytes.subarray(2));
        const json = () => { try { return JSON.parse(text) || {}; } catch (e) { return {}; } };
        if (from >= FIRST_WATCHER) {
            // another page of the site
            if (type === HELLO) {
                const data = json(), name = cleanName(data.name);
                const known = !!sites.get(from);
                sites.set(from, name);
                if (name && !known && !data.re) line(null, fill(T.onSite, { name }));
                publish();
                render();
            } else if (type === CHAT) {
                const said = cleanChat(text);
                if (said && sites.get(from)) line(sites.get(from), said);
            } else if (type === SITE) {
                const data = json();
                if (data.go && sites.get(from)) line(null, fill(T.toGame, { name: sites.get(from) }));
                if (!players.size && cfg.modes[data.mode] && cfg.levels[data.level]) { mode = data.mode; level = Number(data.level); render(); }
            }
            return;
        }
        // the game
        switch (type) {
            case HELLO: {
                const name = cleanName(json().name, from);
                players.set(from, name);
                line(null, fill(T.arrived, { name }));
                break;
            }
            case ROSTER: {
                if (from !== host) break;
                const data = json();
                players.clear();
                for (const [peer, name] of Object.entries(data.names || {})) players.set(Number(peer), cleanName(name, Number(peer)));
                if (cfg.modes[data.mode]) mode = data.mode;
                if (cfg.levels[data.level] || Number(data.level) === SITE_LEVEL) level = Number(data.level);
                siteName = data.site && typeof data.site.name === 'string' ? data.site.name : '';
                state = data.state === 'match' ? 'match' : 'lobby';
                break;
            }
            case START:
                if (from === host) state = 'match';
                break;
            case CHAT: {
                const said = cleanChat(text);
                if (said) line(players.get(from) || cleanName('', from), said);
                break;
            }
        }
        render();
    }

    function fail(message) {
        closedForGood = true;
        $('room-error').textContent = message;
        $('room-error').hidden = false;
        status('');
        for (const id of ['settings', 'room-play', 'room-share']) $(id).hidden = true;
        if (ws) ws.close();
    }

    // --- the page ---

    function status(text) { $('room-status').textContent = text; }

    function line(name, text) {
        const li = document.createElement('li');
        if (name) {
            const b = document.createElement('b');
            b.textContent = name;
            li.append(b, ' ' + text);
        } else {
            li.className = 'note';
            li.textContent = text;
        }
        const log = $('chat');
        log.append(li);
        while (log.children.length > CHAT_LINES) log.firstElementChild.remove();
        log.scrollTop = log.scrollHeight;
    }

    function people(list, entries, hostId) {
        list.replaceChildren(...entries.map(([id, name]) => {
            const li = document.createElement('li');
            li.textContent = name;
            if (id === hostId) { const s = document.createElement('small'); s.textContent = ' ' + T.host; li.append(s); }
            if (id === me) { const s = document.createElement('small'); s.textContent = ' (' + T.you + ')'; li.append(s); }
            return li;
        }));
    }

    function playUrl() {
        const sep = cfg.play.includes('?') ? '&' : '?';
        return cfg.play + sep + new URLSearchParams({ room: code, mode, level: String(level), back: roomUrl() });
    }

    function render() {
        if (!code || closedForGood) return;
        document.title = fill(T.room, { code }) + ' · Sonic PHP';
        $('room-title').textContent = fill(T.room, { code });
        const kind = $('room-kind');
        kind.hidden = isPublic === null;
        kind.textContent = isPublic ? T.public : T.private;
        kind.classList.toggle('private', !isPublic);

        const levelName = level === SITE_LEVEL ? siteName || T.siteLevel : cfg.levels[level] || '';
        status(me >= 0 ? `${state === 'match' ? T.match : T.lobby} · ${T.modes[mode] || mode} · ${levelName}` : T.connecting);

        const hostId = host;
        people($('players'), [...players.entries()].sort((a, b) => (b[0] === hostId) - (a[0] === hostId) || a[0] - b[0]), hostId);
        $('no-players').hidden = players.size > 0;
        $('no-players').textContent = T.noPlayers;
        people($('sites'), [...sites.entries()].filter(([, name]) => name), -2);

        // the match of an empty room: chosen here, by whoever is signed in (then by the game's host)
        const settings = $('settings');
        settings.hidden = !cfg.name || players.size > 0 || me < 0;
        $('room-mode').value = mode;
        $('room-level').value = String(level);

        const play = $('room-play');
        if (!play.dataset.guest) {
            play.href = me >= 0 ? playUrl() : '#';
            play.setAttribute('aria-disabled', me >= 0 ? 'false' : 'true');
        }
        $('room-share').disabled = me < 0;

        // the Sonic of the background: the people of this room only
        // one Sonic per name: in the game and on this page (or in two pages), it is the same person
        const room = new Map();
        for (const name of players.values()) room.set(name, { id: 'n:' + name, name });
        for (const [id, name] of sites) if (name) room.set(name, { id: 'n:' + name, name, me: id === me || room.get(name)?.me });
        const names = [...room.values()];
        window.dispatchEvent(new CustomEvent('sonic-room-people', { detail: names }));
    }

    const changed = () => {
        mode = $('room-mode').value;
        level = Number($('room-level').value);
        send(SITE, { mode, level });
        publish();
        render();
    };
    $('room-mode').addEventListener('change', changed);
    $('room-level').addEventListener('change', changed);

    $('room-play').addEventListener('click', e => {
        if ($('room-play').dataset.guest) return;   // to the sign-up, then back here
        if (me < 0) { e.preventDefault(); return; }
        // the others see it; the room is kept by the relay while the game loads
        send(SITE, { go: true, mode, level });
    });

    $('room-share').addEventListener('click', async () => {
        // the preview of the link (title, picture) in the language of the one who shares it
        const url = roomUrl() + '?lang=' + document.documentElement.lang, text = fill(T.shareText, { code });
        if (navigator.share) {
            try { await navigator.share({ title: 'Sonic PHP', text, url }); return; }
            catch (e) { if (e && e.name === 'AbortError') return; }
        }
        try {
            await navigator.clipboard.writeText(url);
            line(null, T.copied + ' ' + url);
        } catch (e) {
            line(null, url);
        }
    });

    const say = $('say');
    if (say) {
        say.addEventListener('submit', e => {
            e.preventDefault();
            const input = $('say-text');
            const text = cleanChat(input.value);
            if (!text || me < 0 || Date.now() - lastChat < 1000) return;
            lastChat = Date.now();
            send(CHAT, text);
            line(cfg.name, text);
            input.value = '';
        });
    }

    // the relay closes a silent connection: a ping now and then
    setInterval(() => control({ t: 'ping', id: Date.now() }), 20000);
    // back from the game (bfcache): connected again
    addEventListener('pageshow', e => { if (e.persisted && !ws && !closedForGood) connect(); });
    connect();
})();
