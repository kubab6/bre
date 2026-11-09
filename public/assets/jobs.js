(function () {
    const Z = window.ZE;
    const list = document.querySelector('.job-list');
    const csrf = list.dataset.csrf;
    const errEl = document.getElementById('job-error');

    // Eine Stoppuhr je laufendem Auftrag.
    document.querySelectorAll('.running-job[data-start]').forEach((el) => {
        Z.startTicker(el.querySelector('.job-time'), Z.parseServerLocal(el.dataset.start));
    });

    // Anfahrt: nur bei Art "Vor-Ort" sichtbar; Vorschlag ist die Adresse des Kunden.
    const customerList = document.getElementById('customer-list');
    function customerAddress(name) {
        if (!customerList) return '';
        const n = (name || '').trim().toLowerCase();
        const opt = Array.from(customerList.options).find(o => o.value.toLowerCase() === n);
        return opt ? (opt.dataset.address || '') : '';
    }
    function travelParts(box) {
        return {
            customer: box.querySelector('input[name="customer_name"], .edit-customer'),
            type: box.querySelector('select[name="job_type"], .edit-type'),
            field: box.querySelector('.travel-field'),
            input: box.querySelector('input[name="travel"]'),
        };
    }
    function updateTravel(box, customerChanged) {
        const p = travelParts(box);
        if (!p.field || !p.type) return;
        const onsite = p.type.value === 'onsite';
        p.field.hidden = !onsite;
        if (!onsite) return;
        const addr = customerAddress(p.customer ? p.customer.value : '');
        // Nur überschreiben, wenn leer oder noch der zuletzt eingesetzte Vorschlag drinsteht.
        const untouched = p.input.value === '' || p.input.value === (p.input.dataset.auto || '');
        if (untouched && (customerChanged || p.input.value === '')) {
            p.input.value = addr;
            p.input.dataset.auto = addr;
        }
    }
    document.querySelectorAll('#job-start-form, #job-create-form, .job-row[data-id]').forEach((box) => {
        const p = travelParts(box);
        if (!p.input) return;
        // Bereits gespeicherte Anfahrt, die der Kundenadresse entspricht, gilt als Vorschlag.
        if (p.customer && p.input.value !== '' && p.input.value === customerAddress(p.customer.value)) {
            p.input.dataset.auto = p.input.value;
        }
        if (p.type) p.type.addEventListener('change', () => updateTravel(box, false));
        if (p.customer) {
            p.customer.addEventListener('input', () => updateTravel(box, true));
            p.customer.addEventListener('change', () => updateTravel(box, true));
        }
        updateTravel(box, false);
    });

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
                    customer_name: fd.get('customer_name'),
                    job_type: fd.get('job_type'),
                    travel: fd.get('travel'),
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
                    customer_name: fd.get('customer_name'),
                    job_type: fd.get('job_type'),
                    travel: fd.get('travel'),
                    start_time: Z.localInputToServer(fd.get('start_time')),
                    end_time: Z.localInputToServer(fd.get('end_time')),
                });
                window.location.reload();
            } catch (e) { Z.showError(createErr, e.message); }
        });
    }

    document.querySelectorAll('.btn-job-stop').forEach((btn) => {
        btn.addEventListener('click', async () => {
            Z.clearError(errEl);
            btn.disabled = true;
            try {
                await Z.postForm('/api/job.php', { action: 'stop', csrf, id: btn.dataset.id });
                window.location.reload();
            } catch (e) { Z.showError(errEl, e.message); btn.disabled = false; }
        });
    });

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
                customer_name: row.querySelector('.edit-customer').value,
                job_type: row.querySelector('.edit-type').value,
                travel: row.querySelector('.edit-travel').value,
                start_time: Z.localInputToServer(row.querySelector('.edit-start').value),
                end_time: Z.localInputToServer(row.querySelector('.edit-end').value),
            };
            ev.target.disabled = true;
            try {
                const res = await Z.postForm('/api/job.php', data);
                row.querySelector('.dur').textContent = res.duration != null ? Z.formatShort(res.duration) : '–';
                const travelInput = row.querySelector('.edit-travel');
                travelInput.value = res.travel || '';
                travelInput.dataset.auto = res.travel || '';
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
