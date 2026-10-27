/* global WallGame, WallAI */
(function () {
  'use strict';
  const G = WallGame;
  const $ = s => document.querySelector(s);
  const COLORS = ['#D9432F', '#3E7BE6', '#E7B22E', '#2FA36B'];
  const COLOR_NAMES = ['Red', 'Blue', 'Yellow', 'Green'];
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- storage helpers (WebView may block storage) ---------- */
  const store = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* ignore */ } },
    del(k) { try { localStorage.removeItem(k); } catch (e) { /* ignore */ } }
  };

  const DEFAULT_SETTINGS = { gameName: 'Wall Dash', boardSize: 9, walls2p: 10, walls3p: 7, walls4p: 5, default4pMode: 'center', turnTimer: 0, allowBots: true, onlineEnabled: true, announcement: '' };
  let SETTINGS = Object.assign({}, DEFAULT_SETTINGS, store.get('settings', {}));
  let serverReachable = false;

  let playerId = store.get('pid', null);
  if (!playerId) { playerId = (crypto.randomUUID ? crypto.randomUUID() : String(Math.random()).slice(2) + Date.now()); store.set('pid', playerId); }

  const prefs = Object.assign({ name: '', players: '2', mode: SETTINGS.default4pMode, level: 'normal' }, store.get('prefs', {}));

  /* ---------- UI basics ---------- */
  function show(id) {
    for (const s of document.querySelectorAll('.screen')) s.hidden = s.id !== id;
    $('#overlay').hidden = true;
    window.scrollTo(0, 0);
  }
  let toastTimer;
  function toast(msg) {
    const t = $('#toast'); t.textContent = msg; t.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.hidden = true; }, 2600);
  }
  const myName = () => ($('#nameInput').value.trim() || 'Player').slice(0, 18);

  function applySettings() {
    document.title = SETTINGS.gameName;
    $('#gameName').textContent = SETTINGS.gameName;
    const a = $('#announcement'); a.textContent = SETTINGS.announcement || ''; a.hidden = !SETTINGS.announcement;
    $('#btnAI').hidden = !SETTINGS.allowBots;
    $('#levelRow').hidden = !SETTINGS.allowBots;
    updateOnlineUI();
  }
  function updateOnlineUI() {
    const note = $('#onlineNote');
    const can = serverReachable && SETTINGS.onlineEnabled && navigator.onLine;
    $('#btnCreate').disabled = !can; $('#btnJoin').disabled = !can; $('#codeInput').disabled = !can;
    note.hidden = can;
    if (!navigator.onLine || !serverReachable) note.textContent = 'You’re offline. You can still play the computer or on this device.';
    else if (!SETTINGS.onlineEnabled) note.textContent = 'Online play is switched off right now.';
  }

  async function fetchSettings() {
    try {
      const r = await fetch('api.php?action=settings', { cache: 'no-store' });
      if (!r.ok) throw new Error();
      SETTINGS = Object.assign({}, DEFAULT_SETTINGS, await r.json());
      store.set('settings', SETTINGS);
      serverReachable = true;
    } catch (e) { serverReachable = false; }
    applySettings();
  }
  window.addEventListener('online', fetchSettings);
  window.addEventListener('offline', updateOnlineUI);

  /* ---------- home setup controls ---------- */
  function syncSegs() {
    if (!['2', '3', '4'].includes(prefs.players)) prefs.players = '2';
    document.querySelectorAll('.seg[data-pref]').forEach(seg => {
      const v = String(prefs[seg.dataset.pref]);
      seg.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.v === v));
    });
  }
  document.querySelectorAll('.seg[data-pref]').forEach(seg => {
    seg.addEventListener('click', e => {
      const b = e.target.closest('button'); if (!b) return;
      prefs[seg.dataset.pref] = b.dataset.v; store.set('prefs', prefs); syncSegs();
    });
  });
  $('#nameInput').value = prefs.name;
  $('#nameInput').addEventListener('input', () => { prefs.name = $('#nameInput').value; store.set('prefs', prefs); });

  /* ================= Board renderer ================= */
  const canvas = $('#board');
  const ctx = canvas.getContext('2d');
  const view = { size: 0, pad: 0, s: 0, g: 0, rot: 0, n: 9 };
  let orient = 'h', preview = null, hoverCell = null;
  let anim = null; // {from:{i:{r,c}}, t0, wallKey}

  function rotCell(r, c, k, n) { for (let i = 0; i < k; i++) [r, c] = [n - 1 - c, r]; return [r, c]; }
  function unrotCell(r, c, k, n) { for (let i = 0; i < k; i++) [r, c] = [c, n - 1 - r]; return [r, c]; }
  function rotWall(w, k, n) { let { r, c, o } = w; for (let i = 0; i < k; i++) { [r, c] = [n - 2 - c, r]; o = o === 'h' ? 'v' : 'h'; } return { r, c, o }; }
  function unrotWall(w, k, n) { let { r, c, o } = w; for (let i = 0; i < k; i++) { [r, c] = [c, n - 2 - r]; o = o === 'h' ? 'v' : 'h'; } return { r, c, o }; }

  function layout() {
    const rect = canvas.parentElement.getBoundingClientRect();
    const size = Math.floor(Math.min(rect.width, rect.height || rect.width));
    const dpr = window.devicePixelRatio || 1;
    canvas.width = size * dpr; canvas.height = size * dpr;
    canvas.style.width = size + 'px'; canvas.style.height = size + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const n = view.n;
    view.size = size;
    view.pad = Math.round(size * 0.045);
    const inner = size - view.pad * 2;
    view.s = inner / (n + (n - 1) * 0.28);
    view.g = view.s * 0.28;
  }
  const cx = c => view.pad + c * (view.s + view.g);

  function roundRect(x, y, w, h, r) {
    ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }

  function shade(hex, f) {
    const n = parseInt(hex.slice(1), 16);
    const ch = s => Math.max(0, Math.min(255, Math.round(((n >> s) & 255) * f)));
    return `rgb(${ch(16)},${ch(8)},${ch(0)})`;
  }

  function drawWall(vw, color, alpha, scale) {
    const { s, g } = view;
    let x, y, w, h;
    const t = g * 1.3, over = (t - g) / 2; // a bit thicker than the gap, like a real wooden bar
    if (vw.o === 'h') { x = cx(vw.c); y = cx(vw.r) + s - over; w = s * 2 + g; h = t; }
    else { x = cx(vw.c) + s - over; y = cx(vw.r); w = t; h = s * 2 + g; }
    const inset = 0;
    ctx.save(); ctx.globalAlpha = alpha;
    if (scale !== 1) { ctx.translate(x + w / 2, y + h / 2); ctx.scale(scale, scale); ctx.translate(-(x + w / 2), -(y + h / 2)); }
    ctx.fillStyle = 'rgba(0,0,0,.35)'; roundRect(x, y + g * 0.22, w, h, t * 0.4); ctx.fill();
    ctx.fillStyle = color; roundRect(x, y, w, h, t * 0.4); ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,.25)';
    if (vw.o === 'h') roundRect(x + t * 0.4, y + t * 0.15, w - t * 0.8, t * 0.28, t * 0.14);
    else roundRect(x + t * 0.15, y + t * 0.4, t * 0.28, h - t * 0.8, t * 0.14);
    ctx.fill(); ctx.restore();
  }

  function drawPawn(x, y, color, active) {
    const { s } = view, rad = s * 0.34;
    ctx.save();
    ctx.fillStyle = 'rgba(0,0,0,.3)'; ctx.beginPath(); ctx.ellipse(x, y + rad * 0.35, rad * 1.02, rad * 0.8, 0, 0, Math.PI * 2); ctx.fill();
    const grad = ctx.createRadialGradient(x - rad * 0.35, y - rad * 0.45, rad * 0.1, x, y, rad);
    grad.addColorStop(0, shade(color, 1.35)); grad.addColorStop(0.6, color); grad.addColorStop(1, shade(color, 0.7));
    ctx.fillStyle = grad; ctx.beginPath(); ctx.arc(x, y, rad, 0, Math.PI * 2); ctx.fill();
    if (active) { ctx.strokeStyle = '#F4EAD8'; ctx.lineWidth = Math.max(2, s * 0.07); ctx.beginPath(); ctx.arc(x, y, rad + s * 0.09, 0, Math.PI * 2); ctx.stroke(); }
    ctx.restore();
  }

  function draw() {
    const C = controller; if (!C || !C.state) return;
    const st = C.state, n = st.n, k = view.rot, { s, g, pad, size } = view;
    ctx.clearRect(0, 0, size, size);
    // frame
    ctx.fillStyle = '#6E4127'; roundRect(0, 0, size, size, pad * 0.9); ctx.fill();
    ctx.fillStyle = '#141517'; roundRect(pad * 0.45, pad * 0.45, size - pad * 0.9, size - pad * 0.9, pad * 0.6); ctx.fill();

    // goal markers
    if (st.mode === 'center') {
      const m = Math.floor(n / 2);
      ctx.fillStyle = '#E7B22E'; roundRect(cx(m) - g * 0.45, cx(m) - g * 0.45, s + g * 0.9, s + g * 0.9, s * 0.22); ctx.fill();
    } else {
      st.players.forEach((p, i) => {
        // a goal edge in logical space -> view space via the cells it covers
        const cells = [];
        for (let t = 0; t < n; t++) cells.push(p.goal === 'top' ? [0, t] : p.goal === 'bottom' ? [n - 1, t] : p.goal === 'left' ? [t, 0] : [t, n - 1]);
        const v = cells.map(([r, c]) => rotCell(r, c, k, n));
        const rs = v.map(a => a[0]), cs = v.map(a => a[1]);
        const r0 = Math.min(...rs), r1 = Math.max(...rs), c0 = Math.min(...cs), c1 = Math.max(...cs);
        const th = pad * 0.28;
        ctx.fillStyle = COLORS[i];
        if (r0 === r1) { const y = r0 === 0 ? pad * 0.08 : size - pad * 0.08 - th; roundRect(cx(c0), y, cx(c1) + s - cx(c0), th, th / 2); }
        else { const x = c0 === 0 ? pad * 0.08 : size - pad * 0.08 - th; roundRect(x, cx(r0), th, cx(r1) + s - cx(r0), th / 2); }
        ctx.fill();
      });
    }

    // squares
    const ws = G.wallSet(st);
    const myTurn = C.canAct();
    const moves = myTurn ? G.validMoves(st, st.turn, ws) : [];
    const moveSet = new Set(moves.map(m => rotCell(m.r, m.c, k, n).join(',')));
    // destination squares: the centre square, or each player's far row tinted in their colour (strongest on their turn)
    const mid = Math.floor(n / 2), goalTint = new Map();
    if (st.mode === 'center') goalTint.set(mid + ',' + mid, [['#E7B22E', 0.85]]);
    else st.players.forEach((p, i) => {
      const a = st.winner === null && st.turn === i ? 0.5 : 0.2;
      for (let t = 0; t < n; t++) {
        const [r, c] = p.goal === 'top' ? [0, t] : p.goal === 'bottom' ? [n - 1, t] : p.goal === 'left' ? [t, 0] : [t, n - 1];
        const vk = rotCell(r, c, k, n).join(',');
        if (!goalTint.has(vk)) goalTint.set(vk, []);
        goalTint.get(vk).push([COLORS[i], a]);
      }
    });
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) {
      ctx.fillStyle = '#34363A';
      roundRect(cx(c), cx(r), s, s, s * 0.14); ctx.fill();
      for (const [color, a] of goalTint.get(r + ',' + c) || []) {
        ctx.fillStyle = color; ctx.globalAlpha = a; roundRect(cx(c), cx(r), s, s, s * 0.14); ctx.fill(); ctx.globalAlpha = 1;
      }
      ctx.fillStyle = 'rgba(0,0,0,.18)'; roundRect(cx(c), cx(r) + s * 0.82, s, s * 0.18, s * 0.1); ctx.fill();
      if (moveSet.has(r + ',' + c)) {
        const hov = hoverCell && hoverCell[0] === r && hoverCell[1] === c;
        ctx.fillStyle = COLORS[st.turn]; ctx.globalAlpha = hov ? 0.55 : 0.28;
        ctx.beginPath(); ctx.arc(cx(c) + s / 2, cx(r) + s / 2, s * (hov ? 0.36 : 0.18), 0, Math.PI * 2); ctx.fill(); ctx.globalAlpha = 1;
      }
    }
    if (st.mode === 'center') {
      ctx.fillStyle = '#6B4410'; ctx.font = `800 ${s * 0.6}px ${getComputedStyle(document.body).fontFamily}`;
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText('★', cx(mid) + s / 2, cx(mid) + s / 2 + s * 0.02);
    }

    // walls
    const now = performance.now();
    const at = anim ? Math.min(1, (now - anim.t0) / 200) : 1;
    for (const w of st.walls) {
      const isNew = anim && anim.wallKey === w.o + w.r + ',' + w.c;
      drawWall(rotWall(w, k, n), shade(COLORS[w.by], 0.92), 1, isNew ? 1.25 - 0.25 * easeOut(at) : 1);
    }
    if (preview && myTurn) {
      const ok = G.canPlaceWall(st, st.turn, preview).ok;
      drawWall(rotWall(preview, k, n), ok ? COLORS[st.turn] : '#FF7A66', ok ? 0.55 : 0.5, 1);
    }

    // pawns
    st.players.forEach((p, i) => {
      let [r, c] = rotCell(p.r, p.c, k, n);
      let x = cx(c) + s / 2, y = cx(r) + s / 2;
      if (anim && anim.from && anim.from.i === i && at < 1) {
        const [r0, c0] = rotCell(anim.from.r, anim.from.c, k, n);
        const e = easeOut(at), x0 = cx(c0) + s / 2, y0 = cx(r0) + s / 2;
        x = x0 + (x - x0) * e; y = y0 + (y - y0) * e - Math.sin(Math.PI * e) * s * 0.25;
      }
      drawPawn(x, y, COLORS[i], st.winner === null && st.turn === i);
    });
    if (anim && at < 1) requestAnimationFrame(draw); else anim = null;
  }
  const easeOut = t => 1 - Math.pow(1 - t, 3);

  function animateFrom(prev, next) {
    if (reduceMotion || !prev || !next.lastAction || prev.moves === next.moves) { anim = null; return; }
    const la = next.lastAction;
    if (la.type === 'move') anim = { t0: performance.now(), from: { i: la.player, r: la.from.r, c: la.from.c } };
    else if (la.type === 'wall') anim = { t0: performance.now(), wallKey: la.o + la.r + ',' + la.c };
  }

  /* ---------- input ---------- */
  function pointerPos(e) { const b = canvas.getBoundingClientRect(); return [e.clientX - b.left, e.clientY - b.top]; }
  const sameWall = (a, b) => a && b && a.r === b.r && a.c === b.c && a.o === b.o;
  const sameCell = (a, b) => a === b || (a && b && a[0] === b[0] && a[1] === b[1]);

  // There is no Move / Wall switch: what the pointer is over decides. A square the pawn can step to is a move;
  // a gap between squares, or the edge of any other square, is a wall lying along that gap.
  // Returns { move: [r, c] } in view space, { wall } in game space, or null.
  function targetAt(x, y) {
    const st = controller.state, { s, g, pad, n } = view, step = s + g;
    const col = Math.floor((x - pad) / step), row = Math.floor((y - pad) / step);
    if (row < 0 || col < 0 || row >= n || col >= n) return null;
    const ox = x - pad - col * step, oy = y - pad - row * step;
    const gapX = ox > s, gapY = oy > s;
    if ((gapX && col === n - 1) || (gapY && row === n - 1)) return null; // the frame past the last square
    const along = v => Math.max(0, Math.min(n - 2, Math.round((v - pad - s - g / 2) / step)));
    const wall = w => st.players[st.turn].walls > 0 ? { wall: unrotWall(w, view.rot, n) } : null;
    if (gapX && gapY) return wall({ r: row, c: col, o: orient }); // a corner: either way, R / ⟲ Turn picks
    if (gapY) return wall({ r: row, c: along(x), o: 'h' });
    if (gapX) return wall({ r: along(y), c: col, o: 'v' });
    const [r, c] = unrotCell(row, col, view.rot, n);
    if (G.validMoves(st, st.turn).some(m => m.r === r && m.c === c)) return { move: [row, col] };
    const edge = [[oy, 'h', row - 1], [s - oy, 'h', row], [ox, 'v', col - 1], [s - ox, 'v', col]]
      .filter(e => e[0] < s * 0.25 && e[2] >= 0 && e[2] <= n - 2).sort((a, b) => a[0] - b[0])[0];
    if (!edge) return null;
    return wall(edge[1] === 'h' ? { r: edge[2], c: along(x), o: 'h' } : { r: along(y), c: edge[2], o: 'v' });
  }

  // Mouse: hover previews, click acts.
  // Touch: tapping a dot moves. Tapping or dragging over a gap picks a wall spot and opens the wall panel;
  // only its "Place wall" button places it, so a stray tap never spends a wall.
  let usingMouse = !window.matchMedia('(pointer: coarse)').matches, dragging = false;
  function placeWall(w) {
    const chk = G.canPlaceWall(controller.state, controller.state.turn, w);
    if (!chk.ok) { toast(chk.error); preview = w; draw(); return; }
    preview = null; updatePlace(); act({ type: 'wall', r: w.r, c: w.c, o: w.o });
  }
  function updatePlace() {
    $('#wallPad').hidden = !(preview && !usingMouse && controller && controller.canAct());
  }
  // Arrow buttons move the preview one gap in screen directions, whichever way round the board is turned.
  function nudge(dr, dc) {
    if (!preview) return;
    const v = rotWall(preview, view.rot, view.n), m = view.n - 2;
    v.r = Math.max(0, Math.min(m, v.r + dr)); v.c = Math.max(0, Math.min(m, v.c + dc));
    preview = unrotWall(v, view.rot, view.n); draw();
  }

  canvas.addEventListener('pointermove', e => {
    if (!controller || !controller.canAct()) return;
    const t = targetAt(...pointerPos(e));
    if (e.pointerType === 'mouse') {
      const hc = t && t.move || null, w = t && t.wall || null;
      canvas.style.cursor = t ? 'pointer' : '';
      if (sameCell(hc, hoverCell) && (w ? sameWall(w, preview) : !preview)) return;
      hoverCell = hc; preview = w; draw();
    } else if (dragging && t && t.wall && !sameWall(t.wall, preview)) { preview = t.wall; draw(); }
  });
  // A finger lifting also fires pointerleave, so only a mouse leaving the board clears the preview.
  canvas.addEventListener('pointerleave', e => {
    if (e.pointerType !== 'mouse') return;
    hoverCell = null; preview = null; canvas.style.cursor = ''; draw();
  });
  canvas.addEventListener('pointerdown', e => {
    const wasMouse = usingMouse;
    usingMouse = e.pointerType === 'mouse';
    if (usingMouse !== wasMouse) updateHint();
    if (!controller || !controller.canAct()) return;
    if (usingMouse && e.button === 2) { rotate(); return; }
    const t = targetAt(...pointerPos(e));
    if (t && t.move) {
      const [r, c] = unrotCell(t.move[0], t.move[1], view.rot, view.n);
      preview = null; hoverCell = null; act({ type: 'move', r, c });
    } else if (t && t.wall) {
      if (usingMouse) placeWall(t.wall);
      else { dragging = true; preview = t.wall; draw(); }
    } else if (!usingMouse) { preview = null; draw(); }
    updatePlace();
  });
  canvas.addEventListener('pointerup', () => { dragging = false; });
  canvas.addEventListener('pointercancel', () => { dragging = false; });
  // Stop a long press from opening the browser's own menu on the board (a right-click turns the wall instead).
  canvas.addEventListener('contextmenu', e => e.preventDefault());

  function rotate() {
    if (!preview) { orient = orient === 'h' ? 'v' : 'h'; return; }
    const v = rotWall(preview, view.rot, view.n);
    v.o = orient = v.o === 'h' ? 'v' : 'h';
    preview = unrotWall(v, view.rot, view.n); draw();
  }
  $('#btnPlace').addEventListener('click', () => { if (preview && controller && controller.canAct()) placeWall(preview); });
  $('#btnCancelWall').addEventListener('click', () => { preview = null; updatePlace(); draw(); });
  const DIRS = { up: [-1, 0], down: [1, 0], left: [0, -1], right: [0, 1] };
  $('#wallPad').addEventListener('click', e => { const b = e.target.closest('[data-dir]'); if (b) nudge(...DIRS[b.dataset.dir]); });
  $('#btnRotate').addEventListener('click', rotate);
  document.addEventListener('keydown', e => {
    if ($('#game').hidden || e.target.tagName === 'INPUT') return;
    if (e.key === 'r' || e.key === 'R') rotate();
  });
  $('#btnPass').addEventListener('click', () => act({ type: 'pass' }));

  function updateHint() {
    $('#hint').textContent = (usingMouse
      ? 'Click a dot to move, or a gap between squares to place a wall. '
      : 'Tap a dot to move, or tap a gap between squares to choose a wall spot, then press Place wall. ')
      + (controller && controller.state && controller.state.mode === 'center'
        ? 'First to the gold ★ square in the middle wins.'
        : 'First to reach a square in your coloured row wins.');
  }

  async function act(a) {
    const res = await controller.submit(a);
    if (res && !res.ok && res.error) toast(res.error);
  }

  /* ================= Game screen ================= */
  let controller = null;
  let lastState = null;
  let timerTick = null;

  function enterGame() {
    show('game');
    view.n = controller.state.n;
    view.rot = controller.rotation();
    lastState = null;
    preview = null; hoverCell = null; orient = 'h'; updateHint();
    requestAnimationFrame(() => { layout(); render(); });
  }

  function render() {
    const C = controller; if (!C || !C.state) return;
    const st = C.state;
    animateFrom(lastState, st); lastState = G.clone(st);
    // player chips
    const box = $('#players'); box.innerHTML = '';
    st.players.forEach((p, i) => {
      const el = document.createElement('div');
      el.className = 'chip' + (st.winner === null && st.turn === i ? ' turn' : '');
      const dot = document.createElement('span'); dot.className = 'pawn-dot'; dot.style.background = COLORS[i];
      const name = document.createElement('span'); name.textContent = C.name(i) + (C.isMine(i) && !C.local ? ' (you)' : '');
      const walls = document.createElement('span'); walls.className = 'walls'; walls.textContent = '▮ ' + p.walls; walls.title = p.walls + ' walls left';
      el.append(dot, name, walls);
      if (C.offline && C.offline(i)) { const o = document.createElement('span'); o.className = 'off'; o.textContent = 'offline'; el.append(o); }
      box.append(el);
    });
    // status
    const canAct = C.canAct();
    if (st.winner !== null) $('#status').textContent = C.name(st.winner) + ' wins!';
    else if (canAct) $('#status').textContent = C.local && !C.vsAI ? C.name(st.turn) + ', your turn' : 'Your turn';
    else $('#status').textContent = 'Waiting for ' + C.name(st.turn) + '…';
    $('#status').style.color = st.winner === null ? '' : COLORS[st.winner];
    const me = st.players[st.turn];
    if (!canAct || me.walls <= 0) preview = null;
    updatePlace();
    $('#btnPass').hidden = !(canAct && G.validMoves(st, st.turn).length === 0);
    if (st.winner === null) $('#overlay').hidden = true;
    updateTimer();
    draw();
    if (st.winner !== null) setTimeout(showWin, reduceMotion ? 0 : 450);
  }

  function updateTimer() {
    clearInterval(timerTick);
    const d = controller.deadline && controller.deadline();
    if (!d || controller.state.winner !== null) return;
    const base = $('#status').textContent;
    const tick = () => {
      const left = Math.max(0, Math.ceil((d - Date.now()) / 1000));
      $('#status').textContent = base + ' · ' + left + 's';
      if (!left) clearInterval(timerTick);
    };
    tick(); timerTick = setInterval(tick, 500);
  }

  function showWin() {
    const C = controller; if (!C || C.state.winner === null) return;
    const w = C.state.winner;
    $('#winDot').style.background = COLORS[w];
    $('#winText').textContent = C.isMine(w) && !(C.local && !C.vsAI) ? 'You win!' : C.name(w) + ' wins!';
    const again = $('#btnAgain');
    again.hidden = !C.canRematch();
    again.textContent = C.local ? 'Play again' : 'Rematch';
    $('#overlay').hidden = false;
  }
  $('#btnAgain').addEventListener('click', () => { $('#overlay').hidden = true; controller.rematch(); });
  $('#btnHome').addEventListener('click', goHome);
  $('#btnMenu').addEventListener('click', () => {
    if (controller && controller.state && controller.state.winner === null && !confirm('Leave this game?')) return;
    goHome();
  });

  function goHome() {
    clearInterval(timerTick);
    if (controller && controller.local) controller.leave();
    if (room) leaveOnline();
    controller = null;
    history.replaceState(null, '', location.pathname);
    show('home');
    fetchSettings();
  }

  window.addEventListener('resize', () => { if (controller && !$('#game').hidden) { layout(); draw(); } });

  /* ================= Offline controller ================= */
  function LocalController(size, vsAI, level) {
    const cfg = { numPlayers: size, mode: prefs.mode, boardSize: SETTINGS.boardSize, walls2p: SETTINGS.walls2p, walls3p: SETTINGS.walls3p, walls4p: SETTINGS.walls4p };
    let botTimer;
    const C = {
      local: true, vsAI,
      state: G.createGame(cfg),
      name: i => vsAI && i === 0 ? myName() : COLOR_NAMES[i],
      isMine: i => !vsAI || i === 0,
      canAct: () => C.state.winner === null && C.isMine(C.state.turn),
      rotation: () => 0,
      canRematch: () => true,
      async submit(a) {
        const res = G.applyAction(C.state, C.state.turn, a);
        if (res.ok) { preview = null; render(); botTurn(); }
        return res;
      },
      rematch() { clearTimeout(botTimer); C.state = G.createGame(cfg); lastState = null; render(); },
      leave() { clearTimeout(botTimer); }
    };
    function botTurn() {
      clearTimeout(botTimer);
      if (C.state.winner !== null || C.isMine(C.state.turn)) return;
      botTimer = setTimeout(() => {
        const i = C.state.turn;
        G.applyAction(C.state, i, WallAI.chooseAction(C.state, i, level));
        render(); botTurn();
      }, 550);
    }
    return C;
  }

  $('#btnAI').addEventListener('click', () => { controller = LocalController(+prefs.players, true, prefs.level); enterGame(); });
  $('#btnLocal').addEventListener('click', () => { controller = LocalController(+prefs.players, false); enterGame(); });

  /* ================= Online controller ================= */
  // PHP hosting has no WebSockets, so while in a room the page asks the server for changes about once a second.
  let room = null, mySeat = -1, roomSig = '', pollTimer = null, pollFails = 0, netOk = true;

  async function api(action, data) {
    const r = await fetch('api.php?action=' + action, {
      method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ pid: playerId }, data))
    });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  }

  // Every reply to a room action carries the latest room, so the screen updates without waiting for the next poll.
  async function roomCall(action, data) {
    if (!room) return { error: 'You’re not in a room.' };
    let r;
    try { r = await api(action, Object.assign({ code: room.code }, data)); }
    catch (e) { return { error: 'Can’t reach the server. Check your connection.' }; }
    if (r.error === 'gone') { roomClosed(r.reason); return {}; }
    if (r.room) applyRoom(r);
    return r;
  }

  function applyRoom(r) {
    const nr = r.room;
    if (room && room.code === nr.code && nr.rev < room.rev) return; // an older reply that arrived late
    if (nr.deadline) nr.deadline = Date.now() + (nr.deadline - r.now); // server clock -> this device's clock
    if (r.seat >= 0) mySeat = r.seat;
    roomSig = r.sig;
    onRoomUpdate(nr);
    if (room && !pollTimer) schedulePoll();
  }

  function schedulePoll() {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, document.hidden ? 4000 : 1000);
  }

  async function poll() {
    pollTimer = null;
    if (!room) return;
    const code = room.code;
    try {
      const r = await api('poll', { code, sig: roomSig });
      if (!room || room.code !== code) return;
      pollFails = 0;
      if (!netOk) { netOk = true; toast('Reconnected'); }
      if (r.error === 'gone') { roomClosed(r.reason); return; }
      if (r.room) applyRoom(r);
    } catch (e) {
      if (!room || room.code !== code) return;
      if (++pollFails === 3) { netOk = false; toast('Connection lost. Reconnecting…'); }
    }
    if (room && room.code === code && !pollTimer) schedulePoll();
  }
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && room && pollTimer) { clearTimeout(pollTimer); poll(); }
  });

  function roomClosed(reason) {
    clearTimeout(pollTimer); pollTimer = null; clearInterval(timerTick);
    store.del('room'); room = null; mySeat = -1; roomSig = '';
    toast(reason || 'The room was closed.'); controller = null; show('home');
  }

  function OnlineController() {
    return {
      local: false,
      get state() { return room && room.state; },
      name: i => (room.seats[i] && room.seats[i].name) || COLOR_NAMES[i],
      isMine: i => i === mySeat,
      offline: i => room.seats[i] && !room.seats[i].connected,
      canAct: () => room && room.status === 'playing' && room.state.turn === mySeat && room.state.winner === null && netOk,
      rotation: () => room.size === 2 ? (mySeat === 1 ? 2 : 0) : Math.max(0, mySeat),
      deadline: () => room.deadline,
      canRematch: () => room.host === mySeat,
      async submit(a) { const r = await roomCall('action', { action: a }); if (r && r.ok) { preview = null; draw(); } return r; },
      rematch() { roomCall('rematch').then(r => { if (r && r.error) toast(r.error); }); },
      leave: leaveOnline
    };
  }

  let leftCode = null;
  function leaveOnline() {
    leftCode = room && room.code;
    clearTimeout(pollTimer); pollTimer = null;
    store.del('room');
    if (room) api('leave', { code: room.code }).catch(() => {});
    room = null; mySeat = -1; roomSig = '';
  }

  function onRoomUpdate(r) {
    if (!room && r.code === leftCode && mySeat < 0) return; // late update from a room we just left
    const wasPlaying = room && room.status !== 'lobby';
    room = r;
    store.set('room', r.code);
    if (r.status === 'lobby') { controller = null; renderLobby(); return; }
    if (!controller || controller.local || !wasPlaying || $('#game').hidden) { controller = OnlineController(); enterGame(); }
    else render();
  }

  function renderLobby() {
    if ($('#lobby').hidden) show('lobby');
    $('#roomCode').textContent = room.code;
    const ul = $('#seatList'); ul.innerHTML = '';
    room.seats.forEach((s, i) => {
      const li = document.createElement('li');
      const dot = document.createElement('span'); dot.className = 'pawn-dot'; dot.style.background = COLORS[i];
      li.append(dot);
      if (!s) { li.className = 'empty'; li.append('Open seat'); }
      else {
        li.append(s.name + (i === mySeat ? ' (you)' : ''));
        const tag = document.createElement('span'); tag.className = 'tag';
        tag.textContent = i === room.host ? 'host' : !s.connected ? 'offline' : '';
        li.append(tag);
      }
      ul.append(li);
    });
    const isHost = room.host === mySeat, empty = room.seats.some(s => !s);
    $('#btnStart').hidden = !isHost; $('#waitNote').hidden = isHost;
    $('#fillRow').hidden = !isHost || !empty || !room.cfg.allowBots;
  }

  async function goOnline(action, data) {
    const saved = store.get('room', null);
    if (saved && saved !== data.code) await api('leave', { code: saved }).catch(() => {});
    let r;
    try { r = await api(action, data); }
    catch (e) { toast('Can’t reach the server. Check your connection, or play offline.'); return; }
    if (r.error) { toast(r.error); return; }
    leftCode = null; netOk = true; pollFails = 0;
    applyRoom(r);
  }

  $('#btnCreate').addEventListener('click', () => goOnline('create', { name: myName(), size: +prefs.players, mode: prefs.mode }));
  $('#btnJoin').addEventListener('click', () => {
    const code = $('#codeInput').value.trim().toUpperCase();
    if (code.length !== 5) { toast('Room codes have 5 letters or numbers.'); return; }
    goOnline('join', { name: myName(), code });
  });
  $('#codeInput').addEventListener('keydown', e => { if (e.key === 'Enter') $('#btnJoin').click(); });
  $('#btnStart').addEventListener('click', async () => {
    const r = await roomCall('start', { fillBots: $('#fillBots').checked });
    if (r && r.error) toast(r.error);
  });
  $('#btnLeaveLobby').addEventListener('click', goHome);
  $('#btnShare').addEventListener('click', async () => {
    const url = location.origin + location.pathname + '?room=' + room.code;
    const text = `Join my ${SETTINGS.gameName} game. Room code: ${room.code}`;
    if (navigator.share) { try { await navigator.share({ title: SETTINGS.gameName, text, url }); return; } catch (e) { /* cancelled */ } }
    try { await navigator.clipboard.writeText(url); toast('Invite link copied'); }
    catch (e) { toast('Room code: ' + room.code); }
  });

  /* ================= Start ================= */
  syncSegs(); applySettings(); show('home');
  const qp = new URLSearchParams(location.search).get('room');
  if (qp) $('#codeInput').value = qp.toUpperCase().slice(0, 5);
  fetchSettings().then(async () => {
    const saved = store.get('room', null);
    if (!saved || !serverReachable) return;
    // Put this player back in the room they were in before the page reloaded.
    try {
      const r = await api('poll', { code: saved, sig: '' });
      if (r.room) applyRoom(r); else store.del('room');
    } catch (e) { /* offline: stay on the home screen */ }
  });

  if ('serviceWorker' in navigator && location.protocol !== 'file:') {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
})();
