/* global LSHSAID */
/**
 * Reports admin page — list, upload, delete.
 */
(() => {
  const PER_PAGE = 25;
  const fmtInt = (n) => Number(n).toLocaleString('en-US');

  // Current page is the only piece of UI state we need.
  let currentPage = 1;

  // Build "<rest base><route>?<query>" correctly for both pretty permalinks
  // (/wp-json/...) and plain permalinks (/index.php?rest_route=...). The
  // latter already contains a "?", so any added query params must use "&".
  function api(path, opts = {}) {
    let url = LSHSAID.rest + path;
    if (opts.query) {
      const qs = new URLSearchParams(opts.query).toString();
      if (qs) url += (url.includes('?') ? '&' : '?') + qs;
    }
    const { query, ...rest } = opts;
    return fetch(url, {
      credentials: 'include',
      headers: {
        'X-WP-Nonce': LSHSAID.nonce,
        ...(rest.headers || {}),
      },
      ...rest,
    });
  }

  async function refresh() {
    const tbody = document.querySelector('#aiad-reports-table tbody');
    const pager = document.getElementById('aiad-reports-pager');
    tbody.innerHTML = '<tr><td colspan="7"><em>Loading…</em></td></tr>';
    pager.innerHTML = '';
    try {
      const res = await api('reports', { query: { page: currentPage, per_page: PER_PAGE } });
      const body = await res.json();
      const rows = body.rows || [];
      // Server clamps page to total_pages, so reflect that back.
      currentPage = body.page || 1;
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="7"><em>No reports uploaded yet.</em></td></tr>';
        return;
      }
      tbody.innerHTML = rows.map((r) => `
        <tr>
          <td><code>${escapeHtml(r.filename)}</code></td>
          <td>${escapeHtml(r.uploaded_at)}</td>
          <td>${fmtInt(r.row_count)}</td>
          <td>${fmtInt(r.dupes_skipped)}</td>
          <td>${escapeHtml(r.date_min || '—')} → ${escapeHtml(r.date_max || '—')}</td>
          <td>${(r.sites || []).map(escapeHtml).join(', ')}</td>
          <td><button class="button button-small aiad-delete" data-id="${r.id}">Delete</button></td>
        </tr>
      `).join('');
      renderPager(pager, body.total || 0, body.page || 1, body.total_pages || 1);
    } catch (err) {
      tbody.innerHTML = `<tr><td colspan="7" style="color:#b71c1c;">Failed to load: ${escapeHtml(err.message)}</td></tr>`;
    }
  }

  function renderPager(el, total, page, totalPages) {
    if (totalPages <= 1) {
      el.innerHTML = `<span class="displaying-num">${fmtInt(total)} item${total === 1 ? '' : 's'}</span>`;
      return;
    }
    const disabledFirst = page <= 1 ? 'disabled' : '';
    const disabledLast  = page >= totalPages ? 'disabled' : '';
    el.innerHTML = `
      <span class="displaying-num">${fmtInt(total)} items</span>
      <span class="pagination-links">
        <button type="button" class="button first-page" data-page="1" ${disabledFirst} aria-label="First page">«</button>
        <button type="button" class="button prev-page"  data-page="${page - 1}" ${disabledFirst} aria-label="Previous page">‹</button>
        <span class="paging-input">${page} of <span class="total-pages">${totalPages}</span></span>
        <button type="button" class="button next-page" data-page="${page + 1}" ${disabledLast} aria-label="Next page">›</button>
        <button type="button" class="button last-page" data-page="${totalPages}" ${disabledLast} aria-label="Last page">»</button>
      </span>
    `;
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.aiad-delete');
    if (!btn) return;
    if (!confirm('Delete this report and all its interactions?')) return;
    btn.disabled = true;
    try {
      const res = await api('reports/' + btn.dataset.id, { method: 'DELETE' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      await refresh();
    } catch (err) {
      alert('Delete failed: ' + err.message);
      btn.disabled = false;
    }
  });

  // Pager click handler — buttons carry data-page; ignore disabled.
  document.getElementById('aiad-reports-pager')?.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-page]');
    if (!btn || btn.disabled) return;
    const next = parseInt(btn.dataset.page, 10);
    if (Number.isFinite(next) && next >= 1) {
      currentPage = next;
      refresh();
    }
  });

  // Upload via XHR (so we can track progress) rather than fetch.
  function uploadXHR(file, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', LSHSAID.rest + 'reports', true);
      xhr.setRequestHeader('X-WP-Nonce', LSHSAID.nonce);
      xhr.withCredentials = true;
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) onProgress(e.loaded, e.total);
      };
      xhr.onload = () => {
        let body;
        try { body = JSON.parse(xhr.responseText); } catch { body = { error: 'Invalid response' }; }
        if (xhr.status >= 200 && xhr.status < 300) resolve(body);
        else reject(new Error(body.error || ('HTTP ' + xhr.status)));
      };
      xhr.onerror = () => reject(new Error('Network error'));
      const fd = new FormData();
      fd.append('file', file);
      xhr.send(fd);
    });
  }

  function fmtBytes(n) {
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(0) + ' KB';
    return (n / 1024 / 1024).toFixed(1) + ' MB';
  }

  document.getElementById('aiad-upload-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = document.getElementById('aiad-upload-input');
    const status = document.getElementById('aiad-upload-status');
    const submit = e.target.querySelector('button[type="submit"]');
    if (!input.files.length) return;
    const file = input.files[0];

    submit.disabled = true;
    status.className = 'aiad-status';
    status.textContent = `Uploading 0% (0 of ${fmtBytes(file.size)})…`;

    try {
      const body = await uploadXHR(file, (loaded, total) => {
        const pct = Math.min(100, Math.round((loaded / total) * 100));
        status.textContent = `Uploading ${pct}% (${fmtBytes(loaded)} of ${fmtBytes(total)})…`;
      });
      if (body.skipped_reason === 'duplicate-upload') {
        status.textContent = `Skipped — file already imported as report #${body.report_id} (matched by SHA-1).`;
      } else {
        status.textContent = `Imported ${fmtInt(body.rows)} rows (${fmtInt(body.dupes)} dupes skipped).`;
      }
      status.classList.add('ok');
      input.value = '';
      // New uploads land on page 1 (results sort by uploaded_at DESC).
      currentPage = 1;
      await refresh();
    } catch (err) {
      status.textContent = 'Failed: ' + err.message;
      status.classList.add('err');
    } finally {
      submit.disabled = false;
    }
  });

  refresh();
})();
