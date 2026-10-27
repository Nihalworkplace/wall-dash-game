<?php
/* Rules engine for online games. A PHP copy of shared/game.js, which the browser still uses for offline play.
   Keep the two in step when the rules change. */
final class Game
{
    const DIRS = [[-1, 0], [1, 0], [0, -1], [0, 1]];

    // Seats: 2 players -> bottom, top. 3 and 4 players (clockwise) -> bottom, left, top, right.
    // Modes: 'edge' (race to the far side) or 'center' (race to the middle square).
    public static function createGame(array $opts)
    {
        $n = isset($opts['boardSize']) ? (int) $opts['boardSize'] : 0;
        if (!$n) $n = 9;
        if ($n % 2 === 0) $n += 1;
        $n = max(5, min(13, $n));
        $np = isset($opts['numPlayers']) && in_array((int) $opts['numPlayers'], [2, 3, 4], true) ? (int) $opts['numPlayers'] : 2;
        $m = $opts['mode'] ?? '';
        $mode = $m === 'center' || ($np > 2 && $m !== 'edge') ? 'center' : 'edge';
        $walls = $np === 4 ? self::num($opts['walls4p'] ?? null, 5) : ($np === 3 ? self::num($opts['walls3p'] ?? null, 7) : self::num($opts['walls2p'] ?? null, 10));
        $mid = intdiv($n, 2);
        $starts = $np === 2 ? [[$n - 1, $mid], [0, $mid]] : array_slice([[$n - 1, $mid], [$mid, 0], [0, $mid], [$mid, $n - 1]], 0, $np);
        $goals = $np === 2 ? ['top', 'bottom'] : array_slice(['top', 'right', 'bottom', 'left'], 0, $np);
        $players = [];
        foreach ($starts as $i => $s) $players[] = ['r' => $s[0], 'c' => $s[1], 'walls' => $walls, 'goal' => $goals[$i]];
        return [
            'n' => $n, 'numPlayers' => $np, 'mode' => $mode,
            'players' => $players,
            'walls' => [], 'turn' => 0, 'winner' => null, 'moves' => 0, 'lastAction' => null
        ];
    }

    private static function num($v, $d)
    {
        return is_numeric($v) ? max(0, min(40, (int) $v)) : $d;
    }

    // Whole numbers only (JSON from the browser may hold strings or floats).
    private static function toInt($v)
    {
        if (is_int($v)) return $v;
        if (is_float($v) && floor($v) == $v) return (int) $v;
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) return (int) $v;
        return null;
    }

    private static function no($error)
    {
        return ['ok' => false, 'error' => $error];
    }

    public static function rand01()
    {
        return mt_rand() / (mt_getrandmax() + 1);
    }

    public static function wallSet(array $st)
    {
        $s = [];
        foreach ($st['walls'] as $w) $s[$w['o'] . $w['r'] . ',' . $w['c']] = true;
        return $s;
    }

    public static function isBlocked(array $ws, $r, $c, $dr, $dc)
    {
        if ($dr === 1) return isset($ws['h' . $r . ',' . $c]) || isset($ws['h' . $r . ',' . ($c - 1)]);
        if ($dr === -1) return isset($ws['h' . ($r - 1) . ',' . $c]) || isset($ws['h' . ($r - 1) . ',' . ($c - 1)]);
        if ($dc === 1) return isset($ws['v' . $r . ',' . $c]) || isset($ws['v' . ($r - 1) . ',' . $c]);
        if ($dc === -1) return isset($ws['v' . $r . ',' . ($c - 1)]) || isset($ws['v' . ($r - 1) . ',' . ($c - 1)]);
        return true;
    }

    private static function canStep(array $st, array $ws, $r, $c, $dr, $dc)
    {
        $r2 = $r + $dr;
        $c2 = $c + $dc;
        if ($r2 < 0 || $c2 < 0 || $r2 >= $st['n'] || $c2 >= $st['n']) return false;
        return !self::isBlocked($ws, $r, $c, $dr, $dc);
    }

    public static function isGoal(array $st, $i, $r, $c)
    {
        if ($st['mode'] === 'center') {
            $m = intdiv($st['n'], 2);
            return $r === $m && $c === $m;
        }
        switch ($st['players'][$i]['goal']) {
            case 'top': return $r === 0;
            case 'bottom': return $r === $st['n'] - 1;
            case 'left': return $c === 0;
            case 'right': return $c === $st['n'] - 1;
        }
        return false;
    }

    // Legal pawn moves for player i, including straight jumps and side-steps.
    public static function validMoves(array $st, $i, $ws = null)
    {
        if ($ws === null) $ws = self::wallSet($st);
        $p = $st['players'][$i];
        $occ = [];
        foreach ($st['players'] as $j => $q) if ($j !== $i) $occ[$q['r'] . ',' . $q['c']] = true;
        $out = [];
        foreach (self::DIRS as list($dr, $dc)) {
            if (!self::canStep($st, $ws, $p['r'], $p['c'], $dr, $dc)) continue;
            $r1 = $p['r'] + $dr;
            $c1 = $p['c'] + $dc;
            if (!isset($occ[$r1 . ',' . $c1])) { $out[$r1 . ',' . $c1] = ['r' => $r1, 'c' => $c1]; continue; }
            $r2 = $r1 + $dr;
            $c2 = $c1 + $dc;
            if (self::canStep($st, $ws, $r1, $c1, $dr, $dc) && !isset($occ[$r2 . ',' . $c2])) {
                $out[$r2 . ',' . $c2] = ['r' => $r2, 'c' => $c2, 'jump' => true];
                continue;
            }
            $sides = $dr !== 0 ? [[0, -1], [0, 1]] : [[-1, 0], [1, 0]];
            foreach ($sides as list($sr, $sc)) {
                $r3 = $r1 + $sr;
                $c3 = $c1 + $sc;
                if (self::canStep($st, $ws, $r1, $c1, $sr, $sc) && !isset($occ[$r3 . ',' . $c3])) {
                    $out[$r3 . ',' . $c3] = ['r' => $r3, 'c' => $c3, 'jump' => true];
                }
            }
        }
        return array_values($out);
    }

    // Shortest path (ignoring pawns) from (r,c) to player i's goal. Returns list of cells or null.
    public static function shortestPath(array $st, $i, $ws = null, $from = null)
    {
        if ($ws === null) $ws = self::wallSet($st);
        $p = $from ?: $st['players'][$i];
        if (self::isGoal($st, $i, $p['r'], $p['c'])) return [];
        $start = $p['r'] . ',' . $p['c'];
        $prev = [$start => [$p['r'], $p['c']]];
        $q = [[$p['r'], $p['c']]];
        for ($h = 0; $h < count($q); $h++) {
            list($r, $c) = $q[$h];
            foreach (self::DIRS as list($dr, $dc)) {
                if (!self::canStep($st, $ws, $r, $c, $dr, $dc)) continue;
                $k = ($r + $dr) . ',' . ($c + $dc);
                if (isset($prev[$k])) continue;
                $prev[$k] = [$r, $c];
                if (self::isGoal($st, $i, $r + $dr, $c + $dc)) {
                    $path = [];
                    $cur = [$r + $dr, $c + $dc];
                    while ($cur[0] . ',' . $cur[1] !== $start) {
                        $path[] = ['r' => $cur[0], 'c' => $cur[1]];
                        $cur = $prev[$cur[0] . ',' . $cur[1]];
                    }
                    return array_reverse($path);
                }
                $q[] = [$r + $dr, $c + $dc];
            }
        }
        return null;
    }

    public static function distance(array $st, $i, $ws = null, $from = null)
    {
        $p = self::shortestPath($st, $i, $ws, $from);
        return $p === null ? INF : count($p);
    }

    private static function wallConflicts(array $ws, array $w)
    {
        $r = $w['r'];
        $c = $w['c'];
        if (isset($ws['h' . $r . ',' . $c]) || isset($ws['v' . $r . ',' . $c])) return true; // same spot or crossing
        if ($w['o'] === 'h') return isset($ws['h' . $r . ',' . ($c - 1)]) || isset($ws['h' . $r . ',' . ($c + 1)]);
        return isset($ws['v' . ($r - 1) . ',' . $c]) || isset($ws['v' . ($r + 1) . ',' . $c]);
    }

    public static function canPlaceWall(array $st, $i, $w, $ws = null)
    {
        if (!is_array($w) || !isset($w['o']) || ($w['o'] !== 'h' && $w['o'] !== 'v')) return self::no('Bad wall.');
        $r = $w['r'] ?? null;
        $c = $w['c'] ?? null;
        if (!is_int($r) || !is_int($c) || $r < 0 || $c < 0 || $r > $st['n'] - 2 || $c > $st['n'] - 2) return self::no('That wall is off the board.');
        if ($st['players'][$i]['walls'] <= 0) return self::no('No walls left.');
        if ($ws === null) $ws = self::wallSet($st);
        if (self::wallConflicts($ws, $w)) return self::no('Walls can’t overlap or cross.');
        $ws2 = $ws;
        $ws2[$w['o'] . $r . ',' . $c] = true;
        for ($j = 0; $j < $st['numPlayers']; $j++) {
            if (self::shortestPath($st, $j, $ws2) === null) return self::no('A wall can’t shut a pawn in completely.');
        }
        return ['ok' => true];
    }

    // Changes $st. action: {type:'move', r, c} | {type:'wall', r, c, o} | {type:'pass'}
    public static function applyAction(array &$st, $i, $a)
    {
        if ($st['winner'] !== null) return self::no('The game is over.');
        if ($st['turn'] !== $i) return self::no('It’s not your turn.');
        if (!is_array($a)) return self::no('Bad action.');
        $ws = self::wallSet($st);
        $type = $a['type'] ?? null;
        if ($type === 'move') {
            $r = self::toInt($a['r'] ?? null);
            $c = self::toInt($a['c'] ?? null);
            $ok = false;
            foreach (self::validMoves($st, $i, $ws) as $m) if ($m['r'] === $r && $m['c'] === $c) { $ok = true; break; }
            if (!$ok) return self::no('You can’t move there.');
            $p = $st['players'][$i];
            $st['lastAction'] = ['player' => $i, 'type' => 'move', 'from' => ['r' => $p['r'], 'c' => $p['c']], 'r' => $r, 'c' => $c];
            $st['players'][$i]['r'] = $r;
            $st['players'][$i]['c'] = $c;
            if (self::isGoal($st, $i, $r, $c)) $st['winner'] = $i;
        } elseif ($type === 'wall') {
            $w = ['r' => self::toInt($a['r'] ?? null), 'c' => self::toInt($a['c'] ?? null), 'o' => $a['o'] ?? null];
            $chk = self::canPlaceWall($st, $i, $w, $ws);
            if (!$chk['ok']) return $chk;
            $st['walls'][] = ['r' => $w['r'], 'c' => $w['c'], 'o' => $w['o'], 'by' => $i];
            $st['players'][$i]['walls']--;
            $st['lastAction'] = ['player' => $i, 'type' => 'wall', 'r' => $w['r'], 'c' => $w['c'], 'o' => $w['o']];
        } elseif ($type === 'pass') {
            // Only allowed when the pawn is boxed in by other pawns and can't move.
            if (count(self::validMoves($st, $i, $ws))) return self::no('You can still move.');
            $st['lastAction'] = ['player' => $i, 'type' => 'pass'];
        } else {
            return self::no('Bad action.');
        }
        $st['moves']++;
        if ($st['winner'] === null) $st['turn'] = ($st['turn'] + 1) % $st['numPlayers'];
        return ['ok' => true];
    }

    // Safe default move (used when a turn timer runs out): step that gets closest to the goal.
    public static function bestMove(array $st, $i, $ws = null)
    {
        if ($ws === null) $ws = self::wallSet($st);
        $best = null;
        $bd = INF;
        foreach (self::validMoves($st, $i, $ws) as $m) {
            $d = self::distance($st, $i, $ws, $m);
            if ($d < $bd || ($d == $bd && self::rand01() < 0.5)) { $bd = $d; $best = $m; }
        }
        return $best ? ['type' => 'move', 'r' => $best['r'], 'c' => $best['c']] : ['type' => 'pass'];
    }
}
