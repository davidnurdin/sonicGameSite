// The lobby (home page, signed in): the public rooms of the game and the players online, asked again every
// few seconds (/salons.json: the relay's GET /health?rooms, see OnlineStats).
(() => {
    'use strict';

    const lobby = document.getElementById('lobby');
    if (!lobby || !window.fetch) return;
    const T = JSON.parse(lobby.dataset.i18n);
    const levels = JSON.parse(lobby.dataset.levels);
    const list = document.getElementById('rooms');
    const empty = document.getElementById('rooms-empty');
    const players = document.getElementById('players');

    const el = (tag, cls, text) => {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    };

    function row(room) {
        const li = el('li', 'room-row');
        const a = el('a', 'room-code', room.room);
        a.href = lobby.dataset.room.replace('CODE', room.room);
        const info = el('div', 'room-info');
        info.append(
            el('span', 'room-match', `${T.modes[room.mode] || room.mode} · ${levels[room.level] || ''}`),
            el('span', 'badge' + (room.state === 'match' ? ' busy' : ''), room.state === 'match' ? T.match : T.lobby),
        );
        const who = el('div', 'room-who');
        who.append(el('b', '', `${room.players}/${room.max}`), ' ' + room.names.join(', '));
        if (room.sites.length) who.append(el('small', '', ` · ${T.site} : ${room.sites.join(', ')}`));
        const go = el('a', 'pill-button', T.enter);
        go.href = a.href;
        li.append(a, info, who, go);
        return li;
    }

    async function refresh() {
        if (document.hidden) return;
        let data;
        try {
            const response = await fetch(lobby.dataset.url, { headers: { Accept: 'application/json' } });
            data = await response.json();
        } catch (e) {
            return;
        }
        list.replaceChildren(...(data.list || []).map(row));
        empty.hidden = (data.list || []).length > 0;
        empty.textContent = data.players === null ? T.offline : T.none;
        if (players && data.players !== null) {
            players.hidden = false;
            players.querySelector('b').textContent = data.players;
            const label = players.querySelector('.label');
            label.textContent = data.players > 1 ? label.dataset.many : label.dataset.one;
        }
    }

    refresh();
    setInterval(refresh, 5000);
    document.addEventListener('visibilitychange', refresh);
})();
