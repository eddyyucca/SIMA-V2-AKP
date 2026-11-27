/*
 * Formulir request ITERA terhubung ke database IT Asset.
 *  1) Kotak cari karyawan: ketik nama (atau NIK), pilih dari saran -> NIK, Department, Jabatan terisi dari database dan dikunci.
 *     Nama WAJIB dipilih dari daftar (employee_id terkirim; server mengambil datanya langsung dari database).
 *  2) Saran nama barang pada ITR (Accessories / Consumable / Komponen beserta stok); boleh tetap diketik bebas.
 * Tanpa library; memakai event delegation sehingga formulir yang disembunyikan / baris yang ditambahkan dinamis ikut bekerja.
 */
(function () {
    'use strict';
    var CFG = window.SR_LOOKUP || {};
    var DEBOUNCE = 250;
    var EP = window.EmployeePicker = {};

    // ───────── util ─────────
    function el(tag, attrs, text) {
        var e = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) { if (k === 'style') e.style.cssText = attrs[k]; else if (k === 'class') e.className = attrs[k]; else e.setAttribute(k, attrs[k]); });
        if (text != null) e.textContent = text;
        return e;
    }
    function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }
    function fetchJson(url, signal) {
        return fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: signal }).then(function (res) {
            if (!res.ok) { var err = new Error('http'); err.status = res.status; throw err; }
            return res.json();
        });
    }
    function friendly(status) {
        if (status === 401 || status === 419) return 'Sesi berakhir. Muat ulang halaman lalu coba lagi.';
        if (status === 429) return 'Terlalu banyak pencarian. Tunggu sebentar.';
        return 'Pencarian gagal. Periksa koneksi lalu coba lagi.';
    }

    // ───────── kunci / buka field turunan ─────────
    function lock(f, on) {
        if (!f) return;
        if (f.tagName === 'SELECT') {
            f.dataset.epLock = on ? '1' : '';
            f.style.pointerEvents = on ? 'none' : '';
            f.tabIndex = on ? -1 : 0;
        } else {
            f.readOnly = !!on;
        }
        f.style.opacity = on ? '.9' : '';
        f.style.backgroundColor = on ? '#f1f5f9' : '';
        f.title = on ? 'Diambil dari database karyawan. Klik "Ganti" untuk memilih karyawan lain.' : '';
    }
    function fieldsOf(form) {
        return { nik: form.querySelector('[name="employee_nik"]'), dept: form.querySelector('[name="department"]'), pos: form.querySelector('[name="position"]') };
    }
    function parts(input) {
        var wrap = input.closest('[data-ep-wrap]');
        return { wrap: wrap, id: wrap.querySelector('[data-ep-id]'), list: wrap.querySelector('[data-ep-list]'), hint: wrap.querySelector('[data-ep-hint]') };
    }
    function setHint(input, kind, html) {
        var p = parts(input);
        p.hint.innerHTML = '';
        if (!kind) { p.hint.classList.add('hidden'); return; }
        p.hint.classList.remove('hidden');
        p.hint.style.color = kind === 'error' ? '#dc2626' : (kind === 'warn' ? '#b45309' : '#059669');
        (Array.isArray(html) ? html : [html]).forEach(function (line) { p.hint.appendChild(el('div', null, line)); });
        if (kind !== 'error' && p.id.value && !input.dataset.epFixed && !input.dataset.epLocked) {
            var change = el('button', { type: 'button', 'data-ep-clear': '1', style: 'text-decoration:underline;margin-top:2px;color:#1f6fb5;font-weight:600' }, 'Ganti karyawan');
            p.hint.appendChild(change);
        }
    }

    function closeList(input) {
        var p = parts(input);
        p.list.classList.add('hidden');
        p.list.innerHTML = '';
        input.setAttribute('aria-expanded', 'false');
        delete input.dataset.epActive;
    }

    // ───────── pilih / lepas karyawan ─────────
    function applySelection(input, emp, opts) {
        opts = opts || {};
        var form = input.closest('form'), f = fieldsOf(form), p = parts(input), notes = [];
        input.value = emp.name;
        p.id.value = emp.id;
        input.setCustomValidity('');

        if (!opts.keepValues) {
            if (f.nik) { f.nik.value = emp.nik || ''; }
            if (f.pos) { f.pos.value = emp.position || ''; }
            if (f.dept) {
                var has = emp.department && Array.prototype.some.call(f.dept.options, function (o) { return o.value === emp.department; });
                f.dept.value = has ? emp.department : '';
            }
        }
        var nikOk = f.nik ? !!f.nik.value : true, posOk = f.pos ? !!f.pos.value : true, deptOk = f.dept ? !!f.dept.value : true;
        lock(f.nik, nikOk); lock(f.pos, posOk); lock(f.dept, deptOk);
        if (f.nik && !nikOk) notes.push('NIK karyawan ini belum ada di database: isi NIK (angka saja).');
        if (f.pos && !posOk) notes.push('Jabatan karyawan ini belum ada di database: isi jabatan.');
        if (f.dept && !deptOk) notes.push('Department karyawan ini belum ada di database: pilih department.');
        var lines = ['✓ Terhubung ke database karyawan' + (notes.length ? '' : ': NIK, Department, dan Jabatan terisi otomatis.')];
        setHint(input, 'ok', lines);
        notes.forEach(function (n) { var d = el('div', { style: 'color:#b45309' }, n); p.hint.insertBefore(d, p.hint.querySelector('[data-ep-clear]')); });
        closeList(input);
    }

    function clearSelection(input, wipeFields) {
        var form = input.closest('form'), f = fieldsOf(form), p = parts(input);
        p.id.value = '';
        [f.nik, f.pos, f.dept].forEach(function (x) {
            if (!x) return;
            var wasLocked = x.tagName === 'SELECT' ? x.dataset.epLock === '1' : x.readOnly;
            lock(x, false);
            if (wipeFields && wasLocked) x.value = '';
        });
        setHint(input, null);
    }

    // ───────── pencarian karyawan ─────────
    var seq = 0, aborter = null;
    var search = debounce(function (input) {
        var q = input.value.trim(), p = parts(input);
        if (q.length < 2) { closeList(input); return; }
        if (aborter) aborter.abort();
        aborter = typeof AbortController === 'function' ? new AbortController() : null;
        var mine = ++seq;
        fetchJson(CFG.employees + '?q=' + encodeURIComponent(q), aborter && aborter.signal).then(function (res) {
            if (mine !== seq) return;
            renderEmployees(input, p, res.data || [], q);
        }).catch(function (e) {
            if (e && e.name === 'AbortError') return;
            showMessage(input, p, friendly(e && e.status));
        });
    }, DEBOUNCE);

    function showMessage(input, p, text) {
        p.list.innerHTML = '';
        p.list.appendChild(el('div', { style: 'padding:10px 12px;color:#64748b;font-size:12px' }, text));
        p.list.classList.remove('hidden');
    }

    function renderEmployees(input, p, rows, q) {
        p.list.innerHTML = '';
        if (!rows.length) {
            showMessage(input, p, 'Tidak ada karyawan aktif bernama "' + q + '". Pilih dari daftar; hubungi Tim IT bila nama Anda belum terdaftar.');
            return;
        }
        rows.forEach(function (emp, i) {
            var item = el('div', { role: 'option', 'data-ep-item': String(i), style: 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #eef2f7' });
            item._emp = emp;
            item.appendChild(el('div', { style: 'font-weight:700;color:#0f172a;font-size:13px' }, emp.name));
            item.appendChild(el('div', { style: 'color:#64748b;font-size:11px' }, [emp.position, emp.department, emp.nik ? 'NIK ' + emp.nik : 'NIK belum ada'].filter(Boolean).join(' · ')));
            p.list.appendChild(item);
        });
        p.list.classList.remove('hidden');
        input.setAttribute('aria-expanded', 'true');
        highlight(p.list, 0);
    }

    function highlight(list, idx) {
        var items = list.querySelectorAll('[data-ep-item]');
        items.forEach(function (it, i) { it.style.background = i === idx ? '#e3effc' : ''; it.dataset.on = i === idx ? '1' : ''; });
    }
    function activeIndex(list) {
        var items = list.querySelectorAll('[data-ep-item]');
        for (var i = 0; i < items.length; i++) if (items[i].dataset.on === '1') return i;
        return -1;
    }

    // ───────── event delegation: karyawan ─────────
    document.addEventListener('input', function (e) {
        var input = e.target.closest && e.target.closest('[data-employee-picker]');
        if (input) {
            var p = parts(input);
            if (p.id.value) clearSelection(input, true);          // mengetik ulang = batal pilihan, wajib pilih lagi
            input.setCustomValidity('');
            search(input);
            return;
        }
        var item = e.target.closest && e.target.closest('input[name^="items["][name$="[name]"]');
        if (item && item.closest('#formITR')) { delete item.dataset.ref; item.style.boxShadow = ''; searchItems(item); }
    });

    document.addEventListener('keydown', function (e) {
        var input = e.target.closest && e.target.closest('[data-employee-picker]');
        if (input) {
            var list = parts(input).list, open = !list.classList.contains('hidden'), items = list.querySelectorAll('[data-ep-item]');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!open || !items.length) return;
                e.preventDefault();
                var i = activeIndex(list); i = e.key === 'ArrowDown' ? (i + 1) % items.length : (i - 1 + items.length) % items.length;
                highlight(list, i); items[i].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                if (open && items.length) { e.preventDefault(); var a = activeIndex(list); if (a >= 0) applySelection(input, items[a]._emp); }
            } else if (e.key === 'Escape') {
                if (open) { e.preventDefault(); e.stopPropagation(); closeList(input); }
            }
            return;
        }
        var it = e.target.closest && e.target.closest('input[name^="items["][name$="[name]"]');
        if (it && it.closest('#formITR') && itemBox && itemBox.style.display === 'block') {
            var rows = itemBox.querySelectorAll('[data-ei-item]');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault(); var cur = -1; rows.forEach(function (r, k) { if (r.dataset.on === '1') cur = k; });
                var n = e.key === 'ArrowDown' ? (cur + 1) % rows.length : (cur - 1 + rows.length) % rows.length;
                rows.forEach(function (r, k) { r.dataset.on = k === n ? '1' : ''; r.style.background = k === n ? '#e3effc' : ''; });
            } else if (e.key === 'Enter') {
                var on = null; rows.forEach(function (r) { if (r.dataset.on === '1') on = r; });
                if (on) { e.preventDefault(); applyItem(it, on._row); }
            } else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); hideItemBox(); }
        }
    }, true);

    document.addEventListener('mousedown', function (e) {                // mousedown (bukan click) agar terjadi sebelum input kehilangan fokus
        var item = e.target.closest && e.target.closest('[data-ep-item]');
        if (item && item._emp) {
            e.preventDefault();
            var input = item.closest('[data-ep-wrap]').querySelector('[data-employee-picker]');
            applySelection(input, item._emp);
            return;
        }
        var ei = e.target.closest && e.target.closest('[data-ei-item]');
        if (ei && ei._row && itemTarget) { e.preventDefault(); applyItem(itemTarget, ei._row); }
    });

    document.addEventListener('click', function (e) {
        var clear = e.target.closest && e.target.closest('[data-ep-clear]');
        if (clear && clear.closest('[data-ep-wrap]').querySelector('[data-ep-fixed],[data-ep-locked]')) { clear.remove(); return; }
        if (clear) {
            var input = clear.closest('[data-ep-wrap]').querySelector('[data-employee-picker]');
            clearSelection(input, true);
            input.value = '';
            input.focus();
        }
    });

    document.addEventListener('focusout', function (e) {
        var input = e.target.closest && e.target.closest('[data-employee-picker]');
        if (input) setTimeout(function () { if (document.activeElement !== input) closeList(input); }, 150);
        var it = e.target.closest && e.target.closest('input[name^="items["][name$="[name]"]');
        if (it && it.closest('#formITR')) setTimeout(hideItemBox, 150);
    });

    // Wajib memilih karyawan dari daftar sebelum formulir dikirim (fase capture: berjalan sebelum penanganan kirim milik halaman)
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.querySelectorAll) return;
        var input = form.querySelector('[data-employee-picker][data-ep-required="1"]');
        if (input && !parts(input).id.value) {
            e.preventDefault(); e.stopImmediatePropagation();
            setHint(input, 'error', 'Pilih nama karyawan dari daftar: ketik nama, lalu klik salah satu saran.');
            input.focus();
            return;
        }
        hideItemBox();
        syncItemRefs(form);
    }, true);

    // ───────── akun User: pemohon terisi otomatis dirinya sendiri (data-ep-self), juga setelah formulir direset ─────────
    function prefillSelf(root) {
        (root || document).querySelectorAll('[data-employee-picker][data-ep-self]').forEach(function (input) {
            var emp; try { emp = JSON.parse(input.getAttribute('data-ep-self')); } catch (e) { return; }
            if (emp && !parts(input).id.value) applySelection(input, emp);
            else if (emp) applySelection(input, emp, { keepValues: false });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { prefillSelf(); }); else prefillSelf();
    document.addEventListener('reset', function (e) { setTimeout(function () { prefillSelf(e.target); }, 0); }, true);
    EP.prefillSelf = prefillSelf;

    // ───────── API untuk halaman ─────────
    /** Formulir edit: tampilkan keterhubungan request dengan database. Nilai field sudah diisi oleh halaman; tidak ditimpa. */
    EP.hydrate = function (form, item) {
        var input = form && form.querySelector('[data-employee-picker]');
        if (!input) return;
        var p = parts(input);
        input.value = item.requester_name || '';
        closeList(input);
        delete input.dataset.epLocked; if (!input.dataset.epFixed) input.readOnly = false;
        if (item.employee_id) {
            input.readOnly = true; input.dataset.epLocked = '1';           // pemohon request yang sudah tertaut tidak bisa diganti
            applySelection(input, { id: item.employee_id, name: item.requester_name || '' }, { keepValues: true });
        } else {
            clearSelection(input, false);
            setHint(input, 'warn', 'Request ini belum terhubung ke database karyawan. Ketik nama dan pilih dari daftar untuk menghubungkan (opsional).');
        }
    };
    // ───────── saran nama barang (ITR) ─────────
    var itemBox = null, itemTarget = null, itemSeq = 0, itemAbort = null;
    function ensureItemBox() {
        if (itemBox) return itemBox;
        itemBox = el('div', { style: 'position:fixed;display:none;z-index:99999;max-height:280px;overflow-y:auto;background:#fff;border:1px solid #d6deea;border-radius:10px;box-shadow:0 12px 30px rgba(15,23,42,.18)' });
        document.body.appendChild(itemBox);
        return itemBox;
    }
    function hideItemBox() { if (itemBox) { itemBox.style.display = 'none'; itemBox.innerHTML = ''; } }
    function placeItemBox(input) {
        var r = input.getBoundingClientRect();
        itemBox.style.left = r.left + 'px'; itemBox.style.top = (r.bottom + 4) + 'px'; itemBox.style.width = Math.max(r.width, 320) + 'px';
    }
    var searchItems = debounce(function (input) {
        var q = input.value.trim();
        if (!CFG.items || q.length < 2) { hideItemBox(); return; }
        itemTarget = input;
        if (itemAbort) itemAbort.abort();
        itemAbort = typeof AbortController === 'function' ? new AbortController() : null;
        var mine = ++itemSeq;
        fetchJson(CFG.items + '?q=' + encodeURIComponent(q), itemAbort && itemAbort.signal).then(function (res) {
            if (mine !== itemSeq) return;
            if (document.activeElement !== input) return;                // fokus sudah pindah: jangan munculkan kotak saran yang tertinggal
            var rows = res.data || [];
            ensureItemBox(); itemBox.innerHTML = '';
            if (!rows.length) { hideItemBox(); return; }                 // tidak ada saran: tetap boleh diketik bebas
            rows.forEach(function (row, i) {
                var d = el('div', { 'data-ei-item': String(i), style: 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #eef2f7' });
                d._row = row;
                var top = el('div', { style: 'display:flex;justify-content:space-between;gap:8px' });
                top.appendChild(el('span', { style: 'font-weight:700;color:#0f172a;font-size:13px' }, row.name));
                var stockText = row.stock > 0 ? 'stok ' + row.stock + (row.unit ? ' ' + row.unit : '') : 'stok habis';
                top.appendChild(el('span', { style: 'font-size:11px;font-weight:700;color:' + (row.stock > 0 ? '#059669' : '#dc2626') }, stockText));
                d.appendChild(top);
                d.appendChild(el('div', { style: 'color:#64748b;font-size:11px' }, [row.kind, row.detail].filter(Boolean).join(' · ')));
                itemBox.appendChild(d);
            });
            placeItemBox(input); itemBox.style.display = 'block';
            var first = itemBox.querySelector('[data-ei-item]'); if (first) { first.dataset.on = '1'; first.style.background = '#e3effc'; }
        }).catch(function () { hideItemBox(); });
    }, DEBOUNCE);

    function applyItem(input, row) {
        input.value = row.name;
        input.dataset.ref = row.ref;
        input.style.boxShadow = '0 0 0 2px #34d399';
        input.title = 'Tertaut ke database: ' + row.kind + ' (' + (row.stock > 0 ? 'stok ' + row.stock : 'stok habis') + ')';
        var specName = input.name.replace('[name]', '[spesifikasi]');
        var spec = input.form && input.form.querySelector('[name="' + specName + '"]');
        if (spec && !spec.value && row.detail) spec.value = row.detail;
        hideItemBox();
        updateItemNote(input.form);
    }
    function updateItemNote(form) {
        if (!form) return;
        var inputs = form.querySelectorAll('#itrItemsContainer input[name$="[name]"]');
        var linked = 0; inputs.forEach(function (i) { if (i.dataset.ref) linked++; });
        var note = form.querySelector('[data-ei-note]');
        if (!note) { note = el('div', { 'data-ei-note': '1', style: 'font-size:11px;margin-top:6px;color:#059669' }); var c = form.querySelector('#itrItemsContainer'); if (c) c.parentNode.appendChild(note); }
        note.textContent = linked ? '✓ ' + linked + ' dari ' + inputs.length + ' barang tertaut ke database (Tim IT langsung melihatnya terpilih).' : '';
    }
    /** Tulis items[i][ref] dari data-ref sesaat sebelum kirim (indeks baris bisa berubah saat baris dihapus). */
    function syncItemRefs(form) {
        if (!form.querySelectorAll) return;
        form.querySelectorAll('input[data-ei-ref]').forEach(function (h) { h.remove(); });
        form.querySelectorAll('#itrItemsContainer input[name$="[name]"]').forEach(function (i) {
            if (!i.dataset.ref) return;
            var h = el('input', { type: 'hidden', 'data-ei-ref': '1' });
            h.name = i.name.replace('[name]', '[ref]'); h.value = i.dataset.ref;
            form.appendChild(h);
        });
    }
    window.addEventListener('scroll', function () { if (itemBox && itemBox.style.display === 'block' && itemTarget) placeItemBox(itemTarget); }, true);
})();
