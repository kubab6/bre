(function () {
    const Z = window.ZE;
    const list = document.querySelector('.job-list');
    const csrf = list.dataset.csrf;
    const errEl = document.getElementById('job-error');

    const clock = document.getElementById('job-clock');
    if (clock && clock.dataset.active === '1') {
        Z.startTicker(document.getElementById('job-time'), Z.parseServerLocal(clock.dataset.start));
    }

    const startForm = document.getElementById('job-start-form');
    if (startForm) {
        startForm.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            Z.clearError(errEl);
            const fd = new FormData(startForm);
            try {
                await Z.postForm('/api/job.php', {
                    action: 'start',
                    csrf: fd.get('csrf'),
                    job_name: fd.get('job_name'),
                    note: fd.get('note'),
                });
                window.location.reload();
            } catch (e) { Z.showError(errEl, e.message); }
        });
    }

    const createForm = document.getElementById('job-create-form');
    const createErr = document.getElementById('job-create-error');
    if (createForm) {
        createForm.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            Z.clearError(createErr);
            const fd = new FormData(createForm);
            try {
                await Z.postForm('/api/job.php', {
                    action: 'create',
                    csrf: fd.get('csrf'),
                    user_id: fd.get('user_id'),
                    job_name: fd.get('job_name'),
                    note: fd.get('note'),
                    start_time: Z.localInputToServer(fd.get('start_time')),
                    end_time: Z.localInputToServer(fd.get('end_time')),
                });
                window.location.reload();
            } catch (e) { Z.showError(createErr, e.message); }
        });
    }

    const stopBtn = document.getElementById('btn-job-stop');
    if (stopBtn) {
        stopBtn.addEventListener('click', async () => {
            Z.clearError(errEl);
            try {
                await Z.postForm('/api/job.php', { action: 'stop', csrf });
                window.location.reload();
            } catch (e) { Z.showError(errEl, e.message); }
        });
    }

    list.addEventListener('change', async (ev) => {
        if (!ev.target.classList.contains('edit-recorded')) return;
        const row = ev.target.closest('.job-row[data-id]');
        if (!row) return;
        const cb = ev.target;
        cb.disabled = true;
        try {
            const res = await Z.postForm('/api/job.php', {
                action: 'set_recorded', csrf, id: row.dataset.id, value: cb.checked ? 1 : 0,
            });
            row.classList.toggle('is-recorded', !!res.is_recorded);
        } catch (e) {
            alert(e.message);
            cb.checked = !cb.checked;
        } finally {
            cb.disabled = false;
        }
    });

    list.addEventListener('click', async (ev) => {
        const row = ev.target.closest('.job-row[data-id]');
        if (!row) return;
        const id = row.dataset.id;

        if (ev.target.classList.contains('btn-save')) {
            const data = {
                action: 'update', csrf, id,
                job_name: row.querySelector('.edit-job').value,
                note: row.querySelector('.edit-note').value,
                start_time: Z.localInputToServer(row.querySelector('.edit-start').value),
                end_time: Z.localInputToServer(row.querySelector('.edit-end').value),
            };
            ev.target.disabled = true;
            try {
                const res = await Z.postForm('/api/job.php', data);
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
                await Z.postForm('/api/job.php', { action: 'delete', csrf, id });
                row.remove();
            } catch (e) { alert(e.message); }
        }
    });
})();
