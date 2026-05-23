/* global LSHSAID */
/**
 * Beacon -> site map editor.
 */
(() => {
  const tbody = () => document.querySelector('#aiad-beacon-table tbody');

  function api(path, opts = {}) {
    return fetch(LSHSAID.rest + path, {
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': LSHSAID.nonce,
        ...(opts.headers || {}),
      },
      ...opts,
    });
  }

  function rowHtml(beacon = '', site = '') {
    return `
      <tr>
        <td><input type="text" class="regular-text aiad-beacon" value="${escapeAttr(beacon)}" placeholder="UUID..." /></td>
        <td><input type="text" class="regular-text aiad-site" value="${escapeAttr(site)}" placeholder="Display name" /></td>
        <td><button type="button" class="button button-small aiad-row-remove">Remove</button></td>
      </tr>`;
  }

  function escapeAttr(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
  }

  async function load() {
    const res = await api('settings');
    const body = await res.json();
    const map = body.beacon_map || {};
    const rows = Object.entries(map);
    if (!rows.length) rows.push(['', '']);
    tbody().innerHTML = rows.map(([b, s]) => rowHtml(b, s)).join('');
  }

  function collect() {
    const out = {};
    for (const tr of tbody().querySelectorAll('tr')) {
      const b = tr.querySelector('.aiad-beacon')?.value.trim() || '';
      const s = tr.querySelector('.aiad-site')?.value.trim() || '';
      if (b && s) out[b] = s;
    }
    return out;
  }

  document.getElementById('aiad-add-row').addEventListener('click', () => {
    tbody().insertAdjacentHTML('beforeend', rowHtml());
  });

  document.addEventListener('click', (e) => {
    if (e.target.classList.contains('aiad-row-remove')) {
      e.target.closest('tr')?.remove();
    }
  });

  document.getElementById('aiad-save').addEventListener('click', async () => {
    const status = document.getElementById('aiad-save-status');
    status.textContent = 'Saving…';
    status.className = 'aiad-status';
    try {
      const res = await api('settings', {
        method: 'POST',
        body: JSON.stringify({ beacon_map: collect() }),
      });
      const body = await res.json();
      if (!res.ok) throw new Error(body.error || ('HTTP ' + res.status));
      status.textContent = 'Saved.';
      status.classList.add('ok');
    } catch (err) {
      status.textContent = 'Failed: ' + err.message;
      status.classList.add('err');
    }
  });

  // Clear cached dashboard data (IndexedDB). Mirrors the cache identity in
  // dashboard.js — keep the DB name in sync if it ever changes there.
  document.getElementById('aiad-clear-cache')?.addEventListener('click', () => {
    const status = document.getElementById('aiad-clear-cache-status');
    status.textContent = 'Clearing…';
    status.className = 'aiad-status';
    const req = indexedDB.deleteDatabase('aiad');
    const finish = (msg, ok) => {
      status.textContent = msg;
      status.classList.add(ok ? 'ok' : 'err');
    };
    req.onsuccess = () => finish('Cleared. Visit the Dashboard to load fresh data.', true);
    req.onerror   = () => finish('Failed to clear cache: ' + (req.error?.message || 'unknown'), false);
    req.onblocked = () => finish('Cache is locked by another open tab — close other Dashboard tabs and try again.', false);
  });

  load();
})();
