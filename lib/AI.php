<?php
/* Computer opponent for online games. A PHP copy of shared/ai.js. Levels: easy, normal, hard. */
final class AI
{
    // Walls that would block a given step on a path.
    private static function blockersFor(array $from, array $to)
    {
        $dr = $to['r'] - $from['r'];
        $dc = $to['c'] - $from['c'];
        $r = $from['r'];
        $c = $from['c'];
        if ($dr === 1) return [['o' => 'h', 'r' => $r, 'c' => $c], ['o' => 'h', 'r' => $r, 'c' => $c - 1]];
        if ($dr === -1) return [['o' => 'h', 'r' => $r - 1, 'c' => $c], ['o' => 'h', 'r' => $r - 1, 'c' => $c - 1]];
        if ($dc === 1) return [['o' => 'v', 'r' => $r, 'c' => $c], ['o' => 'v', 'r' => $r - 1, 'c' => $c]];
        return [['o' => 'v', 'r' => $r, 'c' => $c - 1], ['o' => 'v', 'r' => $r - 1, 'c' => $c - 1]];
    }

    public static function chooseAction(array $st, $me, $level = 'normal')
    {
        $ws = Game::wallSet($st);
        $move = Game::bestMove($st, $me, $ws);
        $myDist = Game::distance($st, $me, $ws);
        $p = $st['players'][$me];

        if ($level === 'easy' && Game::rand01() < 0.25) {
            $all = Game::validMoves($st, $me, $ws);
            if ($all) { $m = $all[mt_rand(0, count($all) - 1)]; return ['type' => 'move', 'r' => $m['r'], 'c' => $m['c']]; }
        }
        if ($p['walls'] <= 0) return $move;

        // Find the opponent closest to winning.
        $leader = -1;
        $leaderDist = INF;
        for ($j = 0; $j < $st['numPlayers']; $j++) {
            if ($j === $me) continue;
            $d = Game::distance($st, $j, $ws);
            if ($d < $leaderDist) { $leaderDist = $d; $leader = $j; }
        }
        // Rough tempo: in 2 players, the opponent moves after us.
        $margin = $level === 'hard' ? 1 : 0;
        $threatened = $leaderDist < $myDist + $margin || $leaderDist <= ($level === 'easy' ? 1 : 2);
        if (!$threatened) return $move;
        if ($level === 'easy' && Game::rand01() < 0.5) return $move;

        $path = Game::shortestPath($st, $leader, $ws);
        if ($path === null) return $move;
        $steps = array_slice(array_merge([['r' => $st['players'][$leader]['r'], 'c' => $st['players'][$leader]['c']]], $path), 0, $level === 'hard' ? 8 : 5);
        $tried = [];
        $best = null;
        $bestScore = -INF;
        for ($s = 0; $s + 1 < count($steps); $s++) {
            foreach (self::blockersFor($steps[$s], $steps[$s + 1]) as $w) {
                $k = $w['o'] . $w['r'] . ',' . $w['c'];
                if (isset($tried[$k])) continue;
                $tried[$k] = true;
                if (!Game::canPlaceWall($st, $me, $w, $ws)['ok']) continue;
                $ws2 = $ws;
                $ws2[$k] = true;
                $score = 0;
                for ($j = 0; $j < $st['numPlayers']; $j++) {
                    $gain = Game::distance($st, $j, $ws2) - Game::distance($st, $j, $ws);
                    if ($j === $me) $score -= $gain * 1.3;
                    else $score += $j === $leader ? $gain * 1.5 : $gain * 0.4;
                }
                $score += Game::rand01() * 0.3 - $s * 0.05;
                if ($score > $bestScore) { $bestScore = $score; $best = $w; }
            }
        }
        $need = $level === 'hard' ? 0.9 : ($level === 'normal' ? 1.2 : 2);
        if ($best && $bestScore >= $need) return ['type' => 'wall', 'r' => $best['r'], 'c' => $best['c'], 'o' => $best['o']];
        return $move;
    }
}
