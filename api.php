<?php
/* Every request from the game and the admin panel comes here: api.php?action=... with a JSON body. */
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/rooms.php';

ensure_data_dir();
$action = $_GET['action'] ?? '';
$in = request_body();
$now = now_ms();
if ($action !== 'settings') maybe_cleanup($now);

function player_id(array $in)
{
    $p = $in['pid'] ?? '';
    if (!is_string($p) || $p === '') json_out(['error' => 'Missing player id. Reload the page.']);
    return substr($p, 0, 64);
}

function require_online()
{
    if (!load_settings()['onlineEnabled']) json_out(['error' => 'Online play is switched off right now.']);
}

switch ($action) {
    case 'settings':
        json_out(load_settings());

    /* ---------------- Rooms ---------------- */
    case 'create':
        require_online();
        $pid = player_id($in);
        $s = load_settings();
        if (count(room_files()) >= $s['maxRooms']) json_out(['error' => 'The server is full. Try again in a few minutes.']);
        $size = in_array((int) ($in['size'] ?? 2), [2, 3, 4], true) ? (int) $in['size'] : 2;
        $room = [
            'code' => '', 'size' => $size, 'mode' => ($in['mode'] ?? '') === 'center' ? 'center' : 'edge',
            'status' => 'lobby', 'host' => 0, 'seats' => array_fill(0, $size, null), 'state' => null,
            'createdAt' => $now, 'touched' => $now, 'rev' => 1, 'botAt' => null, 'deadline' => null,
            'cfg' => ['boardSize' => $s['boardSize'], 'walls2p' => $s['walls2p'], 'walls3p' => $s['walls3p'], 'walls4p' => $s['walls4p'], 'turnTimer' => $s['turnTimer'], 'allowBots' => $s['allowBots']]
        ];
        $room['seats'][0] = ['id' => $pid, 'name' => clean_name($in['name'] ?? ''), 'bot' => false, 'seen' => $now];
        // Opening with 'x' fails if the file exists, so two rooms can never get the same code.
        for ($try = 0; $try < 20; $try++) {
            $code = new_code();
            $h = @fopen(room_file($code), 'x');
            if (!$h) continue;
            $room['code'] = $code;
            fwrite($h, encode_data($room));
            fclose($h);
            json_out(room_reply($room, $pid, $now));
        }
        json_out(['error' => 'Couldn’t create a room. Try again.']);

    case 'join':
        require_online();
        $pid = player_id($in);
        $reply = room_op(clean_code($in['code'] ?? ''), function (&$room) use ($pid, $in, $now) {
            if ($room['status'] === 'closed') return null;
            $i = seat_of($room, $pid);
            if ($i < 0) {
                if ($room['status'] !== 'lobby') return ['error' => 'That game has already started.'];
                $i = array_search(null, $room['seats'], true);
                if ($i === false) return ['error' => 'That room is full.'];
                $room['seats'][$i] = ['id' => $pid, 'name' => clean_name($in['name'] ?? ''), 'bot' => false, 'seen' => $now];
                $room['rev']++;
            } else {
                $room['seats'][$i]['seen'] = $now;
            }
            return room_reply($room, $pid, $now);
        });
        json_out($reply ?: ['error' => 'No room with that code. Check the code and try again.']);

    case 'poll':
        $pid = player_id($in);
        $sig = $in['sig'] ?? '';
        $reply = room_op(clean_code($in['code'] ?? ''), function (&$room) use ($pid, $sig, $now) {
            $i = seat_of($room, $pid);
            if ($room['status'] === 'closed' || $i < 0) return gone_reply($room);
            if ($now - $room['seats'][$i]['seen'] > 2000) $room['seats'][$i]['seen'] = $now; // fewer file writes
            run_due_turns($room, $now);
            $r = room_reply($room, $pid, $now);
            return $r['sig'] === $sig ? ['ok' => true, 'sig' => $sig] : $r;
        });
        json_out($reply ?: ['error' => 'gone', 'reason' => 'The room was closed.']);

    case 'start':
    case 'rematch':
        $pid = player_id($in);
        $fillBots = !empty($in['fillBots']);
        $reply = room_op(clean_code($in['code'] ?? ''), function (&$room) use ($pid, $action, $fillBots, $now) {
            $i = seat_of($room, $pid);
            if ($room['status'] === 'closed' || $i < 0) return gone_reply($room);
            $room['seats'][$i]['seen'] = $now;
            if ($action === 'rematch') {
                if ($room['status'] !== 'finished') return ['error' => 'No finished game to replay.'];
                if ($i !== $room['host']) return ['error' => 'Only the host can start a rematch.'];
            } else {
                if ($i !== $room['host']) return ['error' => 'Only the host can start.'];
                if ($room['status'] === 'playing') return ['error' => 'The game has already started.'];
                if (in_array(null, $room['seats'], true)) {
                    if (!$fillBots || !$room['cfg']['allowBots']) return ['error' => 'Wait for every seat to fill, or add computer players.'];
                    $b = 0;
                    foreach ($room['seats'] as $k => $s) if (!$s) $room['seats'][$k] = ['bot' => true, 'name' => BOT_NAMES[$b++] . ' (computer)'];
                }
            }
            start_game($room, $now);
            return room_reply($room, $pid, $now);
        });
        json_out($reply ?: ['error' => 'gone', 'reason' => 'The room was closed.']);

    case 'action':
        $pid = player_id($in);
        $a = $in['action'] ?? null;
        $reply = room_op(clean_code($in['code'] ?? ''), function (&$room) use ($pid, $a, $now) {
            $i = seat_of($room, $pid);
            if ($room['status'] === 'closed' || $i < 0) return gone_reply($room);
            $room['seats'][$i]['seen'] = $now;
            if ($room['status'] !== 'playing') return ['error' => 'No game in progress.'];
            run_due_turns($room, $now); // a turn timer may have run out first
            $res = Game::applyAction($room['state'], $i, $a);
            if ($res['ok']) after_change($room, $now);
            return array_merge(room_reply($room, $pid, $now), $res);
        });
        json_out($reply ?: ['error' => 'gone', 'reason' => 'The room was closed.']);

    case 'leave':
        $pid = player_id($in);
        room_op(clean_code($in['code'] ?? ''), function (&$room) use ($pid, $now) { leave_seat($room, $pid, $now); });
        json_out(['ok' => true]);

    /* ---------------- Admin ---------------- */
    case 'admin_login':
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $tries = read_data(data_file('logins')) ?: [];
        if (isset($tries[$ip]) && $tries[$ip]['until'] > $now) json_out(['error' => 'Too many tries. Wait a minute and try again.'], 429);
        $ok = check_password($in['password'] ?? '');
        with_data(data_file('logins'), 'c+', function (&$d) use ($ip, $ok, $now) {
            if (!is_array($d)) $d = [];
            if ($ok) { unset($d[$ip]); return; }
            $t = $d[$ip] ?? ['n' => 0, 'until' => 0];
            $t['n']++;
            if ($t['n'] >= 5) { $t['until'] = $now + 60000; $t['n'] = 0; }
            $t['at'] = $now;
            $d[$ip] = $t;
        });
        if (!$ok) json_out(['error' => 'Wrong password.'], 401);
        json_out(['token' => new_session()]);

    case 'admin_settings':
        require_admin();
        json_out(load_settings());

    case 'admin_save_settings':
        require_admin();
        list(, $s) = with_data(data_file('settings'), 'c+', function (&$d) use ($in) {
            $d = sanitize_settings($in, sanitize_settings($d ?: [], DEFAULTS));
            return $d;
        });
        json_out($s);

    case 'admin_password':
        require_admin();
        $next = $in['next'] ?? '';
        if (!check_password($in['current'] ?? '')) json_out(['error' => 'Current password is wrong.'], 400);
        if (!is_string($next) || str_len($next) < 6) json_out(['error' => 'Use at least 6 characters.'], 400);
        set_password($next);
        json_out(['ok' => true]);

    case 'admin_stats':
        require_admin();
        $online = 0;
        $rooms = [];
        foreach (room_files() as $f) {
            $r = read_data($f);
            if (!$r || $r['status'] === 'closed') continue;
            $players = [];
            foreach ($r['seats'] as $s) {
                if (!$s) continue;
                $live = seat_live($s, $now);
                if (empty($s['bot']) && $live) $online++;
                $players[] = ['name' => $s['name'], 'bot' => !empty($s['bot']), 'connected' => $live];
            }
            $rooms[] = ['code' => $r['code'], 'size' => $r['size'], 'mode' => $r['mode'], 'status' => $r['status'], 'createdAt' => $r['createdAt'], 'players' => $players];
        }
        usort($rooms, function ($a, $b) { return $a['createdAt'] - $b['createdAt']; });
        $stats = read_stats();
        json_out(['playersOnline' => $online, 'rooms' => $rooms, 'gamesStarted' => $stats['gamesStarted'], 'gamesFinished' => $stats['gamesFinished']]);

    case 'admin_close_room':
        require_admin();
        $ok = room_op(clean_code($in['code'] ?? ''), function (&$room) use ($now) {
            if ($room['status'] === 'closed') return false;
            // Kept for two minutes so players get told why, then cleaned up.
            $room['status'] = 'closed';
            $room['closedReason'] = 'The room was closed by an admin.';
            $room['botAt'] = $room['deadline'] = null;
            $room['touched'] = $now;
            $room['rev']++;
            return true;
        });
        if (!$ok) json_out(['error' => 'Room not found.'], 404);
        json_out(['ok' => true]);

    default:
        json_out(['error' => 'Unknown action.'], 404);
}
