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

    // "Heute"-Knopf neben Datumsfeldern mit data-today: setzt das heutige
    // Datum; bei Datum+Uhrzeit bleibt eine vorhandene Uhrzeit erhalten.
    function pad(n) { return String(n).padStart(2, '0'); }
    function addTodayButtons(root) {
        (root || document).querySelectorAll('input[data-today]').forEach((input) => {
            if (input.dataset.todayReady) return;
            input.dataset.todayReady = '1';
            const wrap = document.createElement('span');
            wrap.className = 'with-today';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-today';
            btn.textContent = 'Heute';
            btn.title = 'Heutiges Datum einsetzen';
            btn.addEventListener('click', () => {
                const d = new Date();
                const today = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
                if (input.type === 'datetime-local') {
                    const time = input.value.length >= 16 ? input.value.slice(11, 16) : `${pad(d.getHours())}:${pad(d.getMinutes())}`;
                    input.value = `${today}T${time}`;
                } else {
                    input.value = today;
                }
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.focus();
            });
            wrap.appendChild(btn);
        });
    }
    // Eingabefelder mit Vorschlagsliste (<datalist>): Die Liste klappt schon
    // beim Anklicken auf und filtert beim Tippen. Freie Eingabe bleibt möglich.
    let comboSeq = 0;
    function addComboboxes(root) {
        (root || document).querySelectorAll('input[list]').forEach((input) => {
            const dl = document.getElementById(input.getAttribute('list'));
            if (!dl || input.dataset.comboReady) return;
            input.dataset.comboReady = '1';
            input.dataset.combo = dl.id;
            input.removeAttribute('list');
            input.setAttribute('autocomplete', 'off');
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-expanded', 'false');

            const wrap = document.createElement('span');
            wrap.className = 'combo';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
            const menu = document.createElement('ul');
            menu.className = 'combo-menu';
            menu.id = 'combo-menu-' + (++comboSeq);
            menu.setAttribute('role', 'listbox');
            menu.hidden = true;
            wrap.appendChild(menu);
            input.setAttribute('aria-controls', menu.id);

            let active = -1;
            function items() { return Array.from(menu.children); }
            function setActive(i) {
                const list = items();
                list.forEach((li, n) => li.classList.toggle('active', n === i));
                active = i;
                if (list[i]) list[i].scrollIntoView({ block: 'nearest' });
            }
            function close() {
                menu.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                active = -1;
            }
            function open() {
                const q = input.value.trim().toLowerCase();
                const opts = Array.from(dl.options).map(o => ({ value: o.value, label: o.textContent.trim() }));
                // Steht schon ein vollständiger Name drin, alle zeigen (zum Wechseln).
                const exact = opts.some(o => o.value.toLowerCase() === q);
                const shown = (q === '' || exact) ? opts : opts.filter(o =>
                    o.value.toLowerCase().includes(q) || o.label.toLowerCase().includes(q));
                menu.innerHTML = '';
                shown.forEach((o) => {
                    const li = document.createElement('li');
                    li.setAttribute('role', 'option');
                    li.dataset.value = o.value;
                    li.textContent = o.value;
                    if (o.label && o.label !== o.value) {
                        const s = document.createElement('span');
                        s.className = 'muted';
                        s.textContent = ' ' + o.label;
                        li.appendChild(s);
                    }
                    if (o.value.toLowerCase() === q) li.classList.add('current');
                    menu.appendChild(li);
                });
                menu.hidden = shown.length === 0;
                input.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
                active = -1;
            }
            function choose(li) {
                input.value = li.dataset.value;
                close();
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }

            input.addEventListener('focus', open);
            input.addEventListener('click', () => { if (menu.hidden) open(); });
            input.addEventListener('input', (ev) => { if (ev.isTrusted) open(); });
            input.addEventListener('blur', close);
            input.addEventListener('keydown', (ev) => {
                const list = items();
                if (ev.key === 'ArrowDown') {
                    ev.preventDefault();
                    if (menu.hidden) { open(); return; }
                    setActive(Math.min(active + 1, list.length - 1));
                } else if (ev.key === 'ArrowUp') {
                    ev.preventDefault();
                    setActive(Math.max(active - 1, 0));
                } else if (ev.key === 'Enter' && !menu.hidden && list[active]) {
                    ev.preventDefault();
                    choose(list[active]);
                } else if (ev.key === 'Escape' && !menu.hidden) {
                    ev.preventDefault();
                    close();
                }
            });
            // mousedown statt click, damit das Feld den Fokus behält.
            menu.addEventListener('mousedown', (ev) => {
                const li = ev.target.closest('li');
                ev.preventDefault();
                if (li) choose(li);
            });
        });
    }

    function init() { addTodayButtons(); addComboboxes(); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return { addComboboxes, addTodayButtons, formatDuration, formatShort, parseServerLocal, postForm, startTicker, showError, clearError, localInputToServer };
})();
