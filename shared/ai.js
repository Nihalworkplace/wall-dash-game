/* Computer opponent. Levels: easy, normal, hard. */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory(require('./game'));
  else root.WallAI = factory(root.WallGame);
})(typeof self !== 'undefined' ? self : this, function (G) {
  'use strict';

  // Walls that would block a given step on a path.
  function blockersFor(from, to) {
    const dr = to.r - from.r, dc = to.c - from.c, r = from.r, c = from.c;
    if (dr === 1) return [{ o: 'h', r, c }, { o: 'h', r, c: c - 1 }];
    if (dr === -1) return [{ o: 'h', r: r - 1, c }, { o: 'h', r: r - 1, c: c - 1 }];
    if (dc === 1) return [{ o: 'v', r, c }, { o: 'v', r: r - 1, c }];
    return [{ o: 'v', r, c: c - 1 }, { o: 'v', r: r - 1, c: c - 1 }];
  }

  function chooseAction(st, me, level) {
    level = level || 'normal';
    const ws = G.wallSet(st);
    const move = G.bestMove(st, me, ws);
    const myDist = G.distance(st, me, ws);
    const p = st.players[me];

    if (level === 'easy' && Math.random() < 0.25) {
      const all = G.validMoves(st, me, ws);
      if (all.length) { const m = all[Math.floor(Math.random() * all.length)]; return { type: 'move', r: m.r, c: m.c }; }
    }
    if (p.walls <= 0) return move;

    // Find the opponent closest to winning.
    let leader = -1, leaderDist = Infinity;
    for (let j = 0; j < st.numPlayers; j++) {
      if (j === me) continue;
      const d = G.distance(st, j, ws);
      if (d < leaderDist) { leaderDist = d; leader = j; }
    }
    // Rough tempo: in 2 players, the opponent moves after us.
    const margin = level === 'hard' ? 1 : 0;
    const threatened = leaderDist < myDist + margin || leaderDist <= (level === 'easy' ? 1 : 2);
    if (!threatened) return move;
    if (level === 'easy' && Math.random() < 0.5) return move;

    const path = G.shortestPath(st, leader, ws);
    if (!path) return move;
    const steps = [{ r: st.players[leader].r, c: st.players[leader].c }].concat(path).slice(0, level === 'hard' ? 8 : 5);
    const tried = new Set();
    let best = null, bestScore = -Infinity;
    for (let s = 0; s + 1 < steps.length; s++) {
      for (const w of blockersFor(steps[s], steps[s + 1])) {
        const k = w.o + w.r + ',' + w.c;
        if (tried.has(k)) continue;
        tried.add(k);
        if (!G.canPlaceWall(st, me, w, ws).ok) continue;
        const ws2 = new Set(ws); ws2.add(k);
        let score = 0;
        for (let j = 0; j < st.numPlayers; j++) {
          const gain = G.distance(st, j, ws2) - G.distance(st, j, ws);
          if (j === me) score -= gain * 1.3;
          else score += j === leader ? gain * 1.5 : gain * 0.4;
        }
        score += Math.random() * 0.3 - s * 0.05;
        if (score > bestScore) { bestScore = score; best = w; }
      }
    }
    const need = level === 'hard' ? 0.9 : level === 'normal' ? 1.2 : 2;
    if (best && bestScore >= need) return { type: 'wall', r: best.r, c: best.c, o: best.o };
    return move;
  }

  return { chooseAction };
});
