// dhexx panel — interaksi minimal, tanpa framework.
document.addEventListener('DOMContentLoaded', () => {
  // Dropzone highlight
  document.querySelectorAll('.drop').forEach((z) => {
    const input = z.querySelector('input[type=file]');
    ['dragenter', 'dragover'].forEach((ev) =>
      z.addEventListener(ev, (e) => { e.preventDefault(); z.classList.add('drag'); })
    );
    ['dragleave', 'drop'].forEach((ev) =>
      z.addEventListener(ev, (e) => { e.preventDefault(); z.classList.remove('drag'); })
    );
    z.addEventListener('drop', (e) => {
      if (input && e.dataTransfer && e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        input.closest('form').submit();
      }
    });
  });

  // Konfirmasi hapus
  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (e) => {
      if (!confirm(el.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // Galeri video: tombol play di tengah -> putar inline
  document.querySelectorAll('.vplay').forEach((btn) => {
    btn.addEventListener('click', () => {
      const thumb = btn.closest('.thumb');
      const v = thumb ? thumb.querySelector('video') : null;
      if (!v) return;
      btn.remove();
      v.setAttribute('controls', '');
      v.play().catch(() => {});
    });
  });

  // Menu +Baru ala Drive: toggle menu + panel form
  const newBtn = document.getElementById('newBtn');
  const newMenu = document.getElementById('newMenu');
  if (newBtn && newMenu) {
    const panes = ['paneFolder', 'paneUpload', 'paneZip'].map((id) => document.getElementById(id));
    const closeMenu = () => { newMenu.hidden = true; };
    newBtn.addEventListener('click', (e) => { e.stopPropagation(); newMenu.hidden = !newMenu.hidden; });
    newMenu.querySelectorAll('button[data-pane],button[data-modal]').forEach((b) => {
      b.addEventListener('click', () => {
        if (b.dataset.modal) {
          const m = document.getElementById(b.dataset.modal);
          if (m) m.hidden = false;
          closeMenu();
          return;
        }
        panes.forEach((p) => { if (p) p.hidden = (p.id !== b.dataset.pane); });
        closeMenu();
        const t = document.getElementById(b.dataset.pane);
        if (t) { const inp = t.querySelector('input'); if (inp) inp.focus(); }
      });
    });
    document.addEventListener('click', (e) => {
      if (!newMenu.hidden && !e.target.closest('.newmenu')) closeMenu();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });
  }
  // Modal upload ala Drive: drag-drop + antrean + progress per file
  const upModal = document.getElementById('upModal');
  if (upModal) {
    const upDrop = document.getElementById('upDrop');
    const upInput = document.getElementById('upInput');
    const upList = document.getElementById('upList');
    const upSummary = document.getElementById('upSummary');
    const pending = [];
    let busy = false, done = 0, fail = 0;
    const tick = () => {
      upSummary.textContent = (done || fail) ? (done + ' berhasil, ' + fail + ' gagal') : '';
    };
    const closeUp = () => {
      if (busy) return; // jangan tutup saat upload jalan
      upModal.hidden = true;
      if (done > 0) location.reload(); // tampilkan file baru di grid
    };
    const addRow = (file) => {
      const d = document.createElement('div');
      d.className = 'uprow';
      const kb = file.size > 1048576
        ? (file.size / 1048576).toFixed(1) + ' MB'
        : Math.max(1, Math.round(file.size / 1024)) + ' KB';
      const nm = document.createElement('span');
      nm.className = 'mono';
      nm.textContent = file.name + ' (' + kb + ')';
      const st = document.createElement('span');
      st.className = 'st muted';
      st.textContent = 'antre…';
      const bar = document.createElement('div');
      bar.className = 'bar';
      bar.innerHTML = '<i></i>';
      d.append(nm, st, bar);
      upList.appendChild(d);
      return d;
    };
    const upOne = (file, row) => new Promise((resolve) => {
      const fd = new FormData();
      fd.append('csrf', upModal.dataset.csrf);
      fd.append('act', 'upload');
      fd.append('cat', upModal.dataset.cat);
      fd.append('dir', upModal.dataset.dir);
      fd.append('file', file, file.name);
      const x = new XMLHttpRequest();
      x.open('POST', 'index.php?page=files');
      x.setRequestHeader('X-Upload-Mode', 'ajax');
      x.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          row.querySelector('.bar i').style.width = Math.round(e.loaded / e.total * 100) + '%';
        }
      };
      x.onload = () => {
        let r = null;
        try { r = JSON.parse(x.responseText); } catch (e) { /* abaikan */ }
        if (r && r.ok) {
          row.classList.add('ok');
          row.querySelector('.st').textContent = 'OK → ' + r.file;
          done++;
        } else {
          row.classList.add('err');
          row.querySelector('.st').textContent = (r && r.error) ? r.error : 'HTTP ' + x.status;
          fail++;
        }
        tick(); resolve();
      };
      x.onerror = () => {
        row.classList.add('err');
        row.querySelector('.st').textContent = 'Jaringan gagal';
        fail++; tick(); resolve();
      };
      x.send(fd);
    });
    const pump = async () => {
      if (busy) return;
      busy = true;
      while (pending.length) {
        const job = pending.shift();
        job.row.querySelector('.st').textContent = 'mengunggah…';
        job.row.querySelector('.st').classList.remove('muted');
        await upOne(job.file, job.row);
      }
      busy = false;
    };
    const queue = (files) => {
      for (const f of files) {
        if (f.size > 50 * 1024 * 1024) {
          const r = addRow(f);
          r.classList.add('err');
          r.querySelector('.st').textContent = 'Terlalu besar. Maks 50MB.';
          fail++; tick();
          continue;
        }
        pending.push({ file: f, row: addRow(f) });
      }
      pump();
    };
    document.getElementById('upBrowse').addEventListener('click', () => upInput.click());
    upInput.addEventListener('change', () => { queue(upInput.files); upInput.value = ''; });
    ['dragenter', 'dragover'].forEach((ev) =>
      upDrop.addEventListener(ev, (e) => { e.preventDefault(); upDrop.classList.add('drag'); })
    );
    ['dragleave', 'drop'].forEach((ev) =>
      upDrop.addEventListener(ev, (e) => { e.preventDefault(); upDrop.classList.remove('drag'); })
    );
    upDrop.addEventListener('drop', (e) => {
      if (e.dataTransfer && e.dataTransfer.files.length) queue(e.dataTransfer.files);
    });
    // Seret file ke mana pun di halaman Files -> buka popup (ala Drive)
    window.addEventListener('dragenter', (e) => {
      if (upModal.hidden && e.dataTransfer) {
        const types = Array.from(e.dataTransfer.types || []);
        if (types.includes('Files')) upModal.hidden = false;
      }
    });
    document.getElementById('upClose').addEventListener('click', closeUp);
    upModal.addEventListener('click', (e) => { if (e.target === upModal) closeUp(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !upModal.hidden) closeUp(); });
  }
  const w = document.getElementById('wipeIn');  if (w) {
    const out = () => w.classList.add('leave');
    requestAnimationFrame(() => requestAnimationFrame(out));
    w.addEventListener('transitionend', () => w.remove(), { once: true });
    setTimeout(() => { if (w.parentNode) w.remove(); }, 1500);
  }
});
