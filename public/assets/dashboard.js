(function () {
    const Z = window.ZE;
    const list = document.querySelector('.day-list');
    const csrf = list.dataset.csrf;
    const errEl = document.getElementById('clock-error');

    const clock = document.getElementById('clock');
    const timeEl = document.getElementById('clock-time');
    const statusEl = document.getElementById('clock-status');
    const btnIn = document.getElementById('btn-clock-in');
    const btnOut = document.getElementById('btn-clock-out');

    let stopTicker = null;

    function startTicking(startStr) {
        const startMs = Z.parseServerLocal(startStr);
        if (stopTicker) stopTicker();
        stopTicker = Z.startTicker(timeEl, startMs);
    }

    if (clock.dataset.active === '1') {
        startTicking(clock.dataset.start);
    }

    btnIn.addEventListener('click', async () => {
        Z.clearError(errEl);
        try {
            const res = await Z.postForm('/api/clock.php', { action: 'in', csrf });
            window.location.reload();
        } catch (e) { Z.showError(errEl, e.message); }
    });

    btnOut.addEventListener('click', async () => {
        Z.clearError(errEl);
        try {
            await Z.postForm('/api/clock.php', { action: 'out', csrf });
            window.location.reload();
        } catch (e) { Z.showError(errEl, e.message); }
    });

    list.addEventListener('click', async (ev) => {
        const row = ev.target.closest('.session-row[data-id]');
        if (!row) return;
        const id = row.dataset.id;

        if (ev.target.classList.contains('btn-save')) {
            const start = Z.localInputToServer(row.querySelector('.edit-start').value);
            const end = Z.localInputToServer(row.querySelector('.edit-end').value);
            ev.target.disabled = true;
            try {
                const res = await Z.postForm('/api/work_session.php', {
                    action: 'update', csrf, id, start_time: start, end_time: end,
                });
                row.querySelector('.dur').textContent = res.duration != null ? Z.formatShort(res.duration) : '–';
                ev.target.textContent = '✓';
                setTimeout(() => { ev.target.textContent = 'Speichern'; ev.target.disabled = false; }, 1200);
            } catch (e) {
                alert(e.message);
                ev.target.disabled = false;
            }
        } else if (ev.target.classList.contains('btn-delete')) {
            if (!confirm('Eintrag löschen?')) return;
            try {
                await Z.postForm('/api/work_session.php', { action: 'delete', csrf, id });
                row.remove();
            } catch (e) { alert(e.message); }
        }
    });
})();
