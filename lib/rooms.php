<?php
/* Online rooms. Each room is one file in data/rooms. There's no background process on PHP hosting, so
   computer turns and turn timers run whenever a player in the room checks in (about once a second). */

const PRESENCE_MS = 12000; // a player who hasn't checked in for this long shows as offline
const WALL_BREAKS = 3;     // with wall breaking on, each player may knock down this many of their own walls
const OFFLINE_STRIKES = 3; // turns in a row timed out while offline, then the computer takes the seat for good
const BOT_DELAY_MS = 700;
const BOT_DELAY_MANY_MS = 350; // big games: a full round of computer turns would drag at 700
const BOT_NAMES = ['Bolt', 'Pebble', 'Maple', 'Juniper', 'Cedar', 'Flint', 'Hazel', 'Rowan', 'Slate', 'Willow', 'Birch', 'Clover', 'Moss'];
const CHAT_KEEP = 30;     // messages kept per room
const CHAT_MAX = 200;     // characters per message
const CHAT_GAP_MS = 600;  // shortest time between two messages from one player

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

// One line of plain text; the page shows it as text, never as HTML.
function clean_chat($t)
{
    $t = preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', is_scalar($t) ? (string) $t : '');
    return str_cut(trim((string) $t), CHAT_MAX);
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
            'deadline' => $room['deadline'] ?: null, 'cfg' => $room['cfg'], 'rev' => $room['rev'], 'chat' => $room['chat'] ?? [], 'walls' => room_walls($room), 'breaks' => !empty($room['breaks'])
        ]
    ];
}

function gone_reply(array $room, $pid = null)
{
    if ($room['status'] === 'closed') $why = $room['closedReason'];
    elseif ($pid !== null && was_kicked($room, $pid)) $why = 'The host removed you from the room.';
    else $why = 'You’re no longer in this room.';
    return ['error' => 'gone', 'reason' => $why];
}

// Players the host removed (until they join again), so their next check-in can say why they're out.
function was_kicked(array $room, $pid)
{
    return in_array($pid, $room['kicked'] ?? [], true);
}

// Walls each player gets: what the host picked in the lobby, else the admin default for this many players.
function room_walls(array $room)
{
    if (isset($room['walls'])) return $room['walls'];
    $cfg = $room['cfg'];
    $size = $room['size'];
    if ($size > 4) return $cfg['wallsMany'] ?? DEFAULTS['wallsMany'];
    if ($size === 4) return $cfg['walls4p'];
    if ($size === 3) return $cfg['walls3p'] ?? DEFAULTS['walls3p'];
    return $cfg['walls2p'];
}

function start_game(array &$room, $now)
{
    $w = room_walls($room);
    $room['state'] = Game::createGame([
        'numPlayers' => $room['size'], 'mode' => $room['mode'], 'boardSize' => $room['cfg']['boardSize'],
        'walls2p' => $w, 'walls3p' => $w, 'walls4p' => $w, 'wallsMany' => $w,
        'breaks' => !empty($room['breaks']) ? WALL_BREAKS : 0
    ]);
    $room['status'] = 'playing';
    bump_stat('gamesStarted');
    after_change($room, $now);
}

// Seconds per turn in online games: the admin setting, or 20 for rooms made before the timer was always on.
function turn_ms(array $room)
{
    $t = (int) ($room['cfg']['turnTimer'] ?? 0);
    return ($t >= 5 ? $t : 20) * 1000;
}

function after_change(array &$room, $now)
{
    $room['deadline'] = null;
    $room['botAt'] = null;
    $st = $room['state'];
    if ($room['status'] === 'playing' && $st['winner'] !== null) { $room['status'] = 'finished'; bump_stat('gamesFinished'); }
    if ($room['status'] === 'playing') {
        $seat = $room['seats'][$st['turn']];
        if (!empty($seat['bot'])) $room['botAt'] = $now + ($room['size'] > 4 ? BOT_DELAY_MANY_MS : BOT_DELAY_MS);
        else $room['deadline'] = $now + turn_ms($room); // every player's turn is timed
    }
    $room['touched'] = $now;
    $room['rev']++;
}

// Plays one turn for $turn with the computer player.
function computer_turn(array &$room, $turn)
{
    $a = AI::chooseAction($room['state'], $turn, 'normal');
    if (!Game::applyAction($room['state'], $turn, $a)['ok']) Game::applyAction($room['state'], $turn, Game::bestMove($room['state'], $turn));
}

// Plays any computer turn or timed-out turn that is due.
function run_due_turns(array &$room, $now)
{
    for ($guard = 0; $guard < 8 && $room['status'] === 'playing'; $guard++) {
        $turn = $room['state']['turn'];
        if ($room['botAt'] !== null && $now >= $room['botAt']) {
            computer_turn($room, $turn);
        } elseif ($room['deadline'] !== null && $now >= $room['deadline']) {
            // Time's up: the computer plays this turn for them. Offline OFFLINE_STRIKES times in a row = gone.
            $live = seat_live($room['seats'][$turn], $now);
            computer_turn($room, $turn);
            $missed = $live ? 0 : ($room['seats'][$turn]['missed'] ?? 0) + 1;
            $room['seats'][$turn]['missed'] = $missed;
            if ($missed >= OFFLINE_STRIKES) seat_to_bot($room, $turn);
        } else {
            break;
        }
        after_change($room, $now);
    }
}

// The player is gone for good: a computer plays their seat from now on.
function seat_to_bot(array &$room, $i)
{
    $room['seats'][$i] = ['bot' => true, 'name' => $room['seats'][$i]['name'] . ' (computer)'];
    if ($room['host'] === $i) {
        $h = first_human($room);
        if ($h >= 0) $room['host'] = $h; // no humans left: the room expires on its own
    }
}

function leave_seat(array &$room, $pid, $now)
{
    $i = seat_of($room, $pid);
    if ($i < 0) return;
    if ($room['status'] === 'lobby') $room['seats'][$i] = null;
    else seat_to_bot($room, $i); // keep the game going
    $host = first_human($room);
    if ($host < 0) { $room = null; return; }
    if ($room['host'] === $i) $room['host'] = $host;
    if ($room['status'] === 'playing') after_change($room, $now);
    else $room['rev']++;
}

/* Lobby only: change the number of seats. Players keep their seats (and colours) when there's room;
   seats are added or open seats taken away at the end. False if the players wouldn't fit. */
function resize_room(array &$room, $size)
{
    $size = max(2, min((int) $room['cfg']['boardSize'], 13, $size));
    $people = count(array_filter($room['seats']));
    if ($size < $people) return false;
    $hostId = $room['seats'][$room['host']]['id'];
    while (count($room['seats']) < $size) $room['seats'][] = null;
    while (count($room['seats']) > $size) {
        $k = count($room['seats']) - 1;
        while ($room['seats'][$k] !== null) $k--; // the last open seat
        array_splice($room['seats'], $k, 1);
    }
    $room['size'] = $size;
    $room['host'] = seat_of($room, $hostId);
    return true;
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
