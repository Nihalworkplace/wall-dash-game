<?php
/* Online rooms. Each room is one file in data/rooms. There's no background process on PHP hosting, so
   computer turns and turn timers run whenever a player in the room checks in (about once a second). */

const PRESENCE_MS = 12000; // a player who hasn't checked in for this long shows as offline
const BOT_DELAY_MS = 700;
const BOT_NAMES = ['Bolt', 'Pebble', 'Maple', 'Juniper'];

function room_file($code)
{
    return ROOMS_DIR . '/' . $code . '.php';
}

function room_files()
{
    return glob(ROOMS_DIR . '/*.php') ?: [];
}

function new_code()
{
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < 5; $i++) $c .= $a[random_int(0, strlen($a) - 1)];
    return $c;
}

function clean_code($v)
{
    $c = is_scalar($v) ? strtoupper(trim((string) $v)) : '';
    return preg_match('/^[A-Z0-9]{5}$/', $c) ? $c : '';
}

function clean_name($n)
{
    $n = str_cut(trim(str_replace(['<', '>'], '', is_scalar($n) ? (string) $n : '')), 18);
    return $n === '' ? 'Player' : $n;
}

function seat_of(array $room, $pid)
{
    foreach ($room['seats'] as $i => $s) if ($s && empty($s['bot']) && $s['id'] === $pid) return $i;
    return -1;
}

function seat_live(array $s, $now)
{
    return !empty($s['bot']) || $now - ($s['seen'] ?? 0) < PRESENCE_MS;
}

function first_human(array $room)
{
    foreach ($room['seats'] as $i => $s) if ($s && empty($s['bot'])) return $i;
    return -1;
}

/* Runs $fn on a room under its lock. $fn may change the room or set it to null to delete it.
   Returns $fn's result, or null if there's no such room. */
function room_op($code, callable $fn)
{
    if ($code === '') return null;
    list(, $result) = with_data(room_file($code), 'r+', function (&$room) use ($fn) {
        if (!$room) return null;
        return $fn($room);
    });
    return $result;
}

// What players see: no player ids, and "connected" worked out from when each player last checked in.
// sig changes whenever anything visible changes, so a check-in can answer "nothing new" cheaply.
function room_reply(array $room, $pid, $now)
{
    $seats = [];
    $sig = '';
    foreach ($room['seats'] as $s) {
        if (!$s) { $seats[] = null; $sig .= '-'; continue; }
        $live = seat_live($s, $now);
        $seats[] = ['name' => $s['name'], 'bot' => !empty($s['bot']), 'connected' => $live];
        $sig .= $live ? '1' : '0';
    }
    return [
        'ok' => true, 'seat' => seat_of($room, $pid), 'now' => $now, 'sig' => $room['rev'] . '.' . $sig,
        'room' => [
            'code' => $room['code'], 'size' => $room['size'], 'mode' => $room['mode'], 'status' => $room['status'],
            'host' => $room['host'], 'seats' => $seats, 'state' => $room['state'],
            'deadline' => $room['deadline'] ?: null, 'cfg' => $room['cfg'], 'rev' => $room['rev']
        ]
    ];
}

function gone_reply(array $room)
{
    return ['error' => 'gone', 'reason' => $room['status'] === 'closed' ? $room['closedReason'] : 'You’re no longer in this room.'];
}

function start_game(array &$room, $now)
{
    $room['state'] = Game::createGame([
        'numPlayers' => $room['size'], 'mode' => $room['mode'], 'boardSize' => $room['cfg']['boardSize'],
        'walls2p' => $room['cfg']['walls2p'], 'walls3p' => $room['cfg']['walls3p'] ?? DEFAULTS['walls3p'], 'walls4p' => $room['cfg']['walls4p']
    ]);
    $room['status'] = 'playing';
    bump_stat('gamesStarted');
    after_change($room, $now);
}

function after_change(array &$room, $now)
{
    $room['deadline'] = null;
    $room['botAt'] = null;
    $st = $room['state'];
    if ($room['status'] === 'playing' && $st['winner'] !== null) { $room['status'] = 'finished'; bump_stat('gamesFinished'); }
    if ($room['status'] === 'playing') {
        $seat = $room['seats'][$st['turn']];
        if (!empty($seat['bot'])) $room['botAt'] = $now + BOT_DELAY_MS;
        elseif ($room['cfg']['turnTimer'] > 0) $room['deadline'] = $now + $room['cfg']['turnTimer'] * 1000;
    }
    $room['touched'] = $now;
    $room['rev']++;
}

// Plays any computer turn or timed-out turn that is due.
function run_due_turns(array &$room, $now)
{
    for ($guard = 0; $guard < 8 && $room['status'] === 'playing'; $guard++) {
        $turn = $room['state']['turn'];
        if ($room['botAt'] !== null && $now >= $room['botAt']) {
            $a = AI::chooseAction($room['state'], $turn, 'normal');
            if (!Game::applyAction($room['state'], $turn, $a)['ok']) Game::applyAction($room['state'], $turn, Game::bestMove($room['state'], $turn));
        } elseif ($room['deadline'] !== null && $now >= $room['deadline']) {
            Game::applyAction($room['state'], $turn, Game::bestMove($room['state'], $turn));
        } else {
            break;
        }
        after_change($room, $now);
    }
}

function leave_seat(array &$room, $pid, $now)
{
    $i = seat_of($room, $pid);
    if ($i < 0) return;
    if ($room['status'] === 'lobby') $room['seats'][$i] = null;
    else $room['seats'][$i] = ['bot' => true, 'name' => $room['seats'][$i]['name'] . ' (computer)']; // keep the game going
    $host = first_human($room);
    if ($host < 0) { $room = null; return; }
    if ($room['host'] === $i) $room['host'] = $host;
    if ($room['status'] === 'playing') after_change($room, $now);
    else $room['rev']++;
}

// Abandoned rooms: nobody has checked in for 10 minutes, or nothing has happened for 6 hours.
function room_expired(array $room, $now)
{
    if ($room['status'] === 'closed') return $now - $room['touched'] > 120000;
    $lastSeen = 0;
    foreach ($room['seats'] as $s) if ($s && empty($s['bot'])) $lastSeen = max($lastSeen, $s['seen'] ?? 0);
    return $now - $lastSeen > 600000 || $now - $room['touched'] > 6 * 3600000;
}

// Runs at most once a minute, on whichever request comes in.
function maybe_cleanup($now)
{
    $mark = DATA_DIR . '/last-cleanup';
    $t = @filemtime($mark);
    if ($t && time() - $t < 60) return;
    @touch($mark);
    foreach (room_files() as $f) {
        with_data($f, 'r+', function (&$room) use ($now) {
            if ($room && room_expired($room, $now)) $room = null;
        });
    }
    with_data(data_file('sessions'), 'c+', function (&$d) use ($now) {
        if (is_array($d)) foreach ($d as $k => $exp) if ($exp < $now) unset($d[$k]);
    });
    with_data(data_file('logins'), 'c+', function (&$d) use ($now) {
        if (is_array($d)) foreach ($d as $ip => $t) if ($t['until'] < $now && $t['at'] < $now - 900000) unset($d[$ip]);
    });
}
