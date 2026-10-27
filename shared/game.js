/* Shared rules engine: runs in the browser (offline play) and on the server (online play). */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.WallGame = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  const DIRS = [[-1, 0], [1, 0], [0, -1], [0, 1]];
  const key = (r, c) => r + ',' + c;

  // Seats: 2 players -> bottom, top. 3 and 4 players (clockwise) -> bottom, left, top, right.
  // Modes: 'edge' (race to the far side) or 'center' (race to the middle square).
  function createGame(opts) {
    opts = opts || {};
    let n = parseInt(opts.boardSize, 10) || 9;
    if (n % 2 === 0) n += 1;
    n = Math.max(5, Math.min(13, n));
    const np = [2, 3, 4].includes(+opts.numPlayers) ? +opts.numPlayers : 2;
    const mode = opts.mode === 'center' || (np > 2 && opts.mode !== 'edge') ? 'center' : 'edge';
    const walls = np === 4 ? num(opts.walls4p, 5) : np === 3 ? num(opts.walls3p, 7) : num(opts.walls2p, 10);
    const mid = Math.floor(n / 2);
    const starts = np === 2 ? [[n - 1, mid], [0, mid]] : [[n - 1, mid], [mid, 0], [0, mid], [mid, n - 1]].slice(0, np);
    const goals = np === 2 ? ['top', 'bottom'] : ['top', 'right', 'bottom', 'left'].slice(0, np);
    return {
      n, numPlayers: np, mode,
      players: starts.map((s, i) => ({ r: s[0], c: s[1], walls, goal: goals[i] })),
      walls: [], turn: 0, winner: null, moves: 0, lastAction: null
    };
  }
  function num(v, d) { v = parseInt(v, 10); return isNaN(v) ? d : Math.max(0, Math.min(40, v)); }

  function wallSet(st) {
    const s = new Set();
    for (const w of st.walls) s.add(w.o + w.r + ',' + w.c);
    return s;
  }

  function isBlocked(ws, r, c, dr, dc) {
    if (dr === 1) return ws.has('h' + r + ',' + c) || ws.has('h' + r + ',' + (c - 1));
    if (dr === -1) return ws.has('h' + (r - 1) + ',' + c) || ws.has('h' + (r - 1) + ',' + (c - 1));
    if (dc === 1) return ws.has('v' + r + ',' + c) || ws.has('v' + (r - 1) + ',' + c);
    if (dc === -1) return ws.has('v' + r + ',' + (c - 1)) || ws.has('v' + (r - 1) + ',' + (c - 1));
    return true;
  }

  function canStep(st, ws, r, c, dr, dc) {
    const r2 = r + dr, c2 = c + dc;
    if (r2 < 0 || c2 < 0 || r2 >= st.n || c2 >= st.n) return false;
    return !isBlocked(ws, r, c, dr, dc);
  }

  function isGoal(st, i, r, c) {
    if (st.mode === 'center') { const m = Math.floor(st.n / 2); return r === m && c === m; }
    switch (st.players[i].goal) {
      case 'top': return r === 0;
      case 'bottom': return r === st.n - 1;
      case 'left': return c === 0;
      case 'right': return c === st.n - 1;
    }
    return false;
  }

  // Legal pawn moves for player i, including straight jumps and side-steps.
  function validMoves(st, i, ws) {
    ws = ws || wallSet(st);
    const p = st.players[i];
    const occ = new Set();
    st.players.forEach((q, j) => { if (j !== i) occ.add(key(q.r, q.c)); });
    const out = new Map();
    for (const [dr, dc] of DIRS) {
      if (!canStep(st, ws, p.r, p.c, dr, dc)) continue;
      const r1 = p.r + dr, c1 = p.c + dc;
      if (!occ.has(key(r1, c1))) { out.set(key(r1, c1), { r: r1, c: c1 }); continue; }
      if (canStep(st, ws, r1, c1, dr, dc) && !occ.has(key(r1 + dr, c1 + dc))) {
        out.set(key(r1 + dr, c1 + dc), { r: r1 + dr, c: c1 + dc, jump: true });
        continue;
      }
      const sides = dr !== 0 ? [[0, -1], [0, 1]] : [[-1, 0], [1, 0]];
      for (const [sr, sc] of sides) {
        if (canStep(st, ws, r1, c1, sr, sc) && !occ.has(key(r1 + sr, c1 + sc))) {
          out.set(key(r1 + sr, c1 + sc), { r: r1 + sr, c: c1 + sc, jump: true });
        }
      }
    }
    return [...out.values()];
  }

  // Shortest path (ignoring pawns) from (r,c) to player i's goal. Returns list of cells or null.
  function shortestPath(st, i, ws, from) {
    ws = ws || wallSet(st);
    const p = from || st.players[i];
    if (isGoal(st, i, p.r, p.c)) return [];
    const prev = new Map();
    const start = key(p.r, p.c);
    prev.set(start, null);
    const q = [[p.r, p.c]];
    for (let h = 0; h < q.length; h++) {
      const [r, c] = q[h];
      for (const [dr, dc] of DIRS) {
        if (!canStep(st, ws, r, c, dr, dc)) continue;
        const k = key(r + dr, c + dc);
        if (prev.has(k)) continue;
        prev.set(k, key(r, c));
        if (isGoal(st, i, r + dr, c + dc)) {
          const path = [];
          let cur = k;
          while (cur !== start) { const [a, b] = cur.split(',').map(Number); path.unshift({ r: a, c: b }); cur = prev.get(cur); }
          return path;
        }
        q.push([r + dr, c + dc]);
      }
    }
    return null;
  }

  function distance(st, i, ws, from) {
    const p = shortestPath(st, i, ws, from);
    return p ? p.length : Infinity;
  }

  function wallConflicts(ws, w) {
    const { r, c, o } = w;
    if (ws.has('h' + r + ',' + c) || ws.has('v' + r + ',' + c)) return true; // same spot or crossing
    if (o === 'h') return ws.has('h' + r + ',' + (c - 1)) || ws.has('h' + r + ',' + (c + 1));
    return ws.has('v' + (r - 1) + ',' + c) || ws.has('v' + (r + 1) + ',' + c);
  }

  function canPlaceWall(st, i, w, ws) {
    if (!w || (w.o !== 'h' && w.o !== 'v')) return { ok: false, error: 'Bad wall.' };
    if (!(w.r >= 0 && w.c >= 0 && w.r <= st.n - 2 && w.c <= st.n - 2)) return { ok: false, error: 'That wall is off the board.' };
    if (st.players[i].walls <= 0) return { ok: false, error: 'No walls left.' };
    ws = ws || wallSet(st);
    if (wallConflicts(ws, w)) return { ok: false, error: 'Walls can’t overlap or cross.' };
    const ws2 = new Set(ws); ws2.add(w.o + w.r + ',' + w.c);
    for (let j = 0; j < st.numPlayers; j++) {
      if (!shortestPath(st, j, ws2)) return { ok: false, error: 'A wall can’t shut a pawn in completely.' };
    }
    return { ok: true };
  }

  // Mutates st. action: {type:'move', r, c} | {type:'wall', r, c, o}
  function applyAction(st, i, a) {
    if (st.winner !== null) return { ok: false, error: 'The game is over.' };
    if (st.turn !== i) return { ok: false, error: 'It’s not your turn.' };
    if (!a || typeof a !== 'object') return { ok: false, error: 'Bad action.' };
    const ws = wallSet(st);
    if (a.type === 'move') {
      const r = +a.r, c = +a.c;
      if (!validMoves(st, i, ws).some(m => m.r === r && m.c === c)) return { ok: false, error: 'You can’t move there.' };
      const p = st.players[i];
      st.lastAction = { player: i, type: 'move', from: { r: p.r, c: p.c }, r, c };
      p.r = r; p.c = c;
      if (isGoal(st, i, r, c)) st.winner = i;
    } else if (a.type === 'wall') {
      const w = { r: +a.r, c: +a.c, o: a.o };
      const chk = canPlaceWall(st, i, w, ws);
      if (!chk.ok) return chk;
      st.walls.push({ r: w.r, c: w.c, o: w.o, by: i });
      st.players[i].walls--;
      st.lastAction = { player: i, type: 'wall', r: w.r, c: w.c, o: w.o };
    } else if (a.type === 'pass') {
      // Only allowed when the pawn is boxed in by other pawns and can't move.
      if (validMoves(st, i, ws).length) return { ok: false, error: 'You can still move.' };
      st.lastAction = { player: i, type: 'pass' };
    } else return { ok: false, error: 'Bad action.' };
    st.moves++;
    if (st.winner === null) st.turn = (st.turn + 1) % st.numPlayers;
    return { ok: true };
  }

  // Safe default move (used when a turn timer runs out): step that gets closest to the goal.
  function bestMove(st, i, ws) {
    ws = ws || wallSet(st);
    let best = null, bd = Infinity;
    for (const m of validMoves(st, i, ws)) {
      const d = distance(st, i, ws, m);
      if (d < bd || (d === bd && Math.random() < 0.5)) { bd = d; best = m; }
    }
    return best ? { type: 'move', r: best.r, c: best.c } : { type: 'pass' };
  }

  const clone = st => JSON.parse(JSON.stringify(st));

  return { createGame, wallSet, validMoves, shortestPath, distance, canPlaceWall, applyAction, isGoal, bestMove, clone, isBlocked };
});
