// The visitor counter of the footer (VisitController): a browser that runs this script says it came, once (the
// site counts each address once). Not a browser driven by a program (navigator.webdriver).
(() => {
    const KEY = 'sonicVisitCounted';
    try {
        if (navigator.webdriver || localStorage.getItem(KEY)) return;
    } catch (e) { return; }
    const url = document.currentScript?.dataset.url;
    if (!url) return;
    fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-Sonic-Visit': '1', Accept: 'application/json' } })
        .then(response => response.ok ? response.json() : null)
        .then(data => {
            if (!data) return;
            try { localStorage.setItem(KEY, '1'); } catch (e) {}
            const counter = document.getElementById('visitors');
            if (counter && data.visitors) counter.textContent = data.visitors.toLocaleString(document.documentElement.lang || undefined);
        })
        .catch(() => {});
})();
