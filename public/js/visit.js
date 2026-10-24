// The visitor counter of the footer (VisitController): a browser that runs this script says it came, once (the
// site counts each address once). Not a browser driven by a program (navigator.webdriver).
(() => {
    // 2: the visits before the address of the visitor reached the site (all counted as the proxy's) count again
    const KEY = 'sonicVisitCounted2';
    try {
        if (navigator.webdriver || localStorage.getItem(KEY)) return;
    } catch (e) { return; }
    const url = document.currentScript?.dataset.url;
    if (!url) return;
    fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-Sonic-Visit': '1', Accept: 'application/json' } })
        .then(response => response.ok ? response.json() : null)
        .then(data => {
            // no count (the site did not see the visitor's address): asked again on the next page
            if (!data || !data.visitors) return;
            try { localStorage.setItem(KEY, '1'); } catch (e) {}
            const counter = document.getElementById('visitors');
            if (counter && data.visitors) counter.textContent = data.visitors.toLocaleString(document.documentElement.lang || undefined);
        })
        .catch(() => {});
})();
