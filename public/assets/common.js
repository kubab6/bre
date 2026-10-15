// Shared helpers for the time-tracking app.

window.ZE = (function () {
    function formatDuration(seconds) {
        if (seconds < 0) seconds = 0;
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        return [h, m, s].map(n => String(n).padStart(2, '0')).join(':');
    }

    function formatShort(seconds) {
        if (seconds < 0) seconds = 0;
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        return `${h}h ${String(m).padStart(2, '0')}m`;
    }

    // SQLite stores naive local time strings (server tz Europe/Berlin).
    // Parse them as local time on the client, ignoring the browser's tz
    // so the stopwatch matches the visible clock.
    function parseServerLocal(s) {
        // "YYYY-MM-DD HH:MM:SS"
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
        if (!m) return null;
        return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]).getTime();
    }

    async function postForm(url, data) {
        const body = new URLSearchParams();
        Object.entries(data).forEach(([k, v]) => body.append(k, v == null ? '' : v));
        const res = await fetch(url, {
            method: 'POST',
            body,
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
        });
        let json = null;
        try { json = await res.json(); } catch (_) {}
        if (!res.ok || !json || json.ok === false) {
            const msg = (json && json.error) || `Fehler ${res.status}`;
            throw new Error(msg);
        }
        return json;
    }

    function startTicker(el, startMs, onTick) {
        function tick() {
            const seconds = Math.max(0, Math.floor((Date.now() - startMs) / 1000));
            el.textContent = formatDuration(seconds);
            if (onTick) onTick(seconds);
        }
        tick();
        const id = setInterval(tick, 1000);
        return () => clearInterval(id);
    }

    function showError(el, msg) {
        if (!el) { alert(msg); return; }
        el.textContent = msg;
        el.hidden = false;
    }

    function clearError(el) {
        if (!el) return;
        el.textContent = '';
        el.hidden = true;
    }

    function localInputToServer(value) {
        // datetime-local: "YYYY-MM-DDTHH:MM" → "YYYY-MM-DD HH:MM:00"
        if (!value) return '';
        const v = value.length === 16 ? value + ':00' : value;
        return v.replace('T', ' ');
    }

    return { formatDuration, formatShort, parseServerLocal, postForm, startTicker, showError, clearError, localInputToServer };
})();
