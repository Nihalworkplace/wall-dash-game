(function () {
  'use strict';
  const $ = s => document.querySelector(s);
  let token = sessionStorage.getItem('adminToken') || '';
  let pollTimer;

  async function api(action, body) {
    const r = await fetch('api.php?action=' + action, {
      method: 'POST', cache: 'no-store',
      // A custom header, because some PHP hosts drop the Authorization header.
      headers: { 'Content-Type': 'application/json', 'X-Admin-Token': token },
      body: JSON.stringify(body || {})
    });
    const data = await r.json().catch(() => ({}));
    if (r.status === 401 && action !== 'admin_login') { signOut(); throw new Error(data.error || 'Sign in again.'); }
    if (!r.ok) throw new Error(data.error || 'Something went wrong (' + r.status + ').');
    return data;
  }
  function msg(el, text, ok) { el.textContent = text; el.className = ok ? 'ok' : 'err'; el.hidden = false; }

  $('#loginForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      const d = await api('admin_login', { password: $('#pw').value });
      token = d.token; sessionStorage.setItem('adminToken', token); $('#pw').value = '';
      start();
    } catch (err) { msg($('#loginErr'), err.message); }
  });

  function signOut() {
    token = ''; sessionStorage.removeItem('adminToken'); clearInterval(pollTimer);
    $('#dash').hidden = true; $('#loginForm').hidden = false;
  }
  $('#logout').addEventListener('click', signOut);

  async function start() {
    try {
      const s = await api('admin_settings');
      $('#loginForm').hidden = true; $('#dash').hidden = false;
      fillForm(s); refresh();
      clearInterval(pollTimer); pollTimer = setInterval(refresh, 5000);
    } catch (e) { /* not signed in */ }
  }

  function fillForm(s) {
    const f = $('#settingsForm');
    for (const [k, v] of Object.entries(s)) {
      const el = f.elements[k]; if (!el) continue;
      if (el.type === 'checkbox') el.checked = !!v; else el.value = v;
    }
  }

  $('#settingsForm').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, body = {};
    for (const el of f.elements) {
      if (!el.name) continue;
      body[el.name] = el.type === 'checkbox' ? el.checked : el.type === 'number' ? Number(el.value) : el.value;
    }
    try { fillForm(await api('admin_save_settings', body)); msg($('#saveMsg'), 'Settings saved.', true); }
    catch (err) { msg($('#saveMsg'), err.message); }
  });

  $('#pwForm').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target;
    try { await api('admin_password', { current: f.current.value, next: f.next.value }); f.reset(); msg($('#pwMsg'), 'Password changed.', true); }
    catch (err) { msg($('#pwMsg'), err.message); }
  });

  async function refresh() {
    try {
      const d = await api('admin_stats');
      $('#sOnline').textContent = d.playersOnline; $('#sRooms').textContent = d.rooms.length;
      $('#sStarted').textContent = d.gamesStarted; $('#sFinished').textContent = d.gamesFinished;
      const tb = $('#roomRows'); tb.innerHTML = '';
      if (!d.rooms.length) { const tr = tb.insertRow(); const td = tr.insertCell(); td.colSpan = 5; td.textContent = 'No rooms open.'; td.style.color = 'var(--chalk-dim)'; }
      for (const r of d.rooms) {
        const tr = tb.insertRow();
        tr.insertCell().textContent = r.code;
        tr.insertCell().textContent = r.players.map(p => p.name + (p.bot ? '' : p.connected ? '' : ' (offline)')).join(', ') + ` · ${r.players.length}/${r.size}`;
        tr.insertCell().textContent = { lobby: 'Waiting', playing: 'Playing', finished: 'Finished' }[r.status];
        tr.insertCell().textContent = new Date(r.createdAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const b = document.createElement('button'); b.className = 'btn danger'; b.textContent = 'Close room';
        b.onclick = async () => { if (!confirm('Close room ' + r.code + '? Players in it will be sent back to the menu.')) return; await api('admin_close_room', { code: r.code }).catch(e => alert(e.message)); refresh(); };
        tr.insertCell().append(b);
      }
    } catch (e) { /* handled in api */ }
  }

  if (token) start();
})();
