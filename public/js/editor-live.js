// What the editors share when several players change the same creation (the owner and those he added):
// every 5 seconds, ask which version is saved and who else is in the editor (/api/edition/...), tell when
// somebody else saved, and when a save would replace a version made since this editor opened (409), let the
// player choose: reload, or overwrite (the other version stays in the history).
// An editor wraps its save body with EditorLive.body(root, body) and passes its answer to EditorLive.answer().
(() => {
    'use strict';

    const EVERY = 5000;
    const states = new WeakMap();

    const time = (iso) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    function state(root) {
        let s = states.get(root);
        if (s) return s;
        const T = JSON.parse(root.dataset.liveI18n || '{}');
        const bar = document.createElement('div');
        bar.className = 'ed-live';
        bar.setAttribute('role', 'status');
        bar.hidden = true;
        const line = document.createElement('div');
        line.className = 'ed-live-line small muted';
        const others = document.createElement('span');
        const history = document.createElement('a');
        history.href = root.dataset.history;
        history.target = '_blank';
        history.textContent = T.history;
        line.append(others, history);
        const head = root.querySelector('.ed-bar');
        (head || root.firstElementChild).after(bar, line);
        s = {
            T, bar, others,
            version: Number(root.dataset.version || 0),
            restored: root.dataset.restored ? Number(root.dataset.restored) : null,
            force: false,
            told: 0,          // the version already told about
            retry: null,
        };
        states.set(root, s);
        if (s.restored) show(s, T.restored);

        return s;
    }

    /** The bar under the editor's tools: a text, and buttons [label, action]. */
    function show(s, text, buttons = []) {
        s.bar.replaceChildren(document.createTextNode(text + ' '));
        for (const [label, action] of buttons) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'pill-button small';
            b.textContent = label;
            b.addEventListener('click', action);
            s.bar.append(b, ' ');
        }
        s.bar.hidden = false;
    }

    function reload(root) {
        const url = new URL(location.href);
        url.searchParams.delete('version');
        // the editor's own warning about unsaved changes still asks first
        location.href = url.toString();
    }

    window.EditorLive = {
        /** What the editor sends to save: with the version it started from, and an old version it took again. */
        body(root, body) {
            const s = state(root);
            return { ...body, version: s.version, force: s.force || undefined, restored: s.restored ?? undefined };
        },

        /**
         * The answer to a save (a Response). True if it saved; false on a conflict, after showing the choice
         * (retry: the editor's save, called again to overwrite). Other errors throw, as before.
         */
        async answer(root, r, retry) {
            const s = state(root);
            if (r.status === 409) {
                const c = await r.json();
                s.told = c.version;
                show(s, s.T.conflict.replace('%name%', c.by || '?').replace('%time%', c.at ? time(c.at) : ''), [
                    [s.T.reload, () => reload(root)],
                    [s.T.overwrite, () => { s.force = true; s.bar.hidden = true; retry(); }],
                ]);
                return false;
            }
            if (!r.ok) throw new Error(String(r.status));
            const json = await r.json().catch(() => ({}));
            if (typeof json.version === 'number') s.version = s.told = json.version;
            s.force = false;
            s.restored = null;
            s.bar.hidden = true;
            return true;
        },

        start(root) {
            const s = state(root);
            if (!root.dataset.live) return;
            const ask = async () => {
                if (document.hidden) return;
                try {
                    const r = await fetch(root.dataset.live, { method: 'POST', headers: { 'X-CSRF-Token': root.dataset.token || '' } });
                    if (!r.ok) return;
                    const live = await r.json();
                    s.others.textContent = live.editors.length ? `${s.T.others} ${live.editors.join(', ')}` : '';
                    if (live.version !== s.version && !live.mine && live.version !== s.told) {
                        s.told = live.version;
                        show(s, s.T.saved.replace('%name%', live.by || '?').replace('%time%', time(live.at)), [
                            [s.T.reload, () => reload(root)],
                            [s.T.history, () => window.open(root.dataset.history, '_blank')],
                        ]);
                    }
                } catch {
                    // offline for a moment: next time
                }
            };
            ask();
            setInterval(ask, EVERY);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) ask(); });
        },
    };

    for (const root of document.querySelectorAll('[data-live]')) window.EditorLive.start(root);
})();
