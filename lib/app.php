<?php
/* Shared helpers: config, JSON files in the data folder, settings, admin sign-in, stats. */
ini_set('display_errors', '0'); // a PHP warning in the output would break the JSON replies

require __DIR__ . '/Game.php';
require __DIR__ . '/AI.php';

$CONFIG = require dirname(__DIR__) . '/config.php';
define('DATA_DIR', rtrim($CONFIG['data_dir'], '/\\'));
define('ROOMS_DIR', DATA_DIR . '/rooms');

// Each data file starts with this line, so the web server runs it (and shows nothing) instead of serving the JSON.
const GUARD = "<?php http_response_code(404); exit; ?>\n";

function now_ms()
{
    return (int) floor(microtime(true) * 1000);
}

function json_out($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_body()
{
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 51200) json_out(['error' => 'Request too large.'], 413);
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function str_len($s)
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function str_cut($s, $n)
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

/* ---------------- Data files ---------------- */
function ensure_data_dir()
{
    if (!is_dir(ROOMS_DIR) && !@mkdir(ROOMS_DIR, 0775, true)) {
        json_out(['error' => 'The server can’t save data. Make the data folder writable (see README).'], 500);
    }
    if (!is_file(DATA_DIR . '/.htaccess')) {
        @file_put_contents(DATA_DIR . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
}

function data_file($name)
{
    return DATA_DIR . '/' . $name . '.php';
}

function decode_data($raw)
{
    if (strncmp($raw, GUARD, strlen(GUARD)) === 0) $raw = substr($raw, strlen(GUARD));
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

function encode_data($d)
{
    return GUARD . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function read_data($path)
{
    $h = @fopen($path, 'r');
    if (!$h) return null;
    flock($h, LOCK_SH);
    $raw = stream_get_contents($h);
    flock($h, LOCK_UN);
    fclose($h);
    return $raw === '' ? null : decode_data($raw);
}

/* Runs $fn on a file's data while holding an exclusive lock, then saves whatever $fn left in it.
   $fn gets the data by reference (null if the file is empty); setting it to null deletes the file.
   Mode 'c+' creates the file if it's missing, 'r+' gives up if it doesn't exist.
   Returns [file found, $fn's return value]. */
function with_data($path, $mode, callable $fn)
{
    $h = @fopen($path, $mode);
    if (!$h) return [false, null];
    flock($h, LOCK_EX);
    $raw = stream_get_contents($h);
    $data = $raw === '' ? null : decode_data($raw);
    $result = $fn($data);
    $out = $data === null ? '' : encode_data($data);
    if ($out !== $raw) {
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, $out);
        fflush($h);
    }
    flock($h, LOCK_UN);
    fclose($h);
    if ($data === null && $mode === 'r+') @unlink($path);
    return [true, $result];
}

/* ---------------- Settings ---------------- */
const DEFAULTS = [
    'gameName' => 'Wall Dash',
    'boardSize' => 9,          // odd, 5–13
    'walls2p' => 10,
    'walls3p' => 7,
    'walls4p' => 5,
    'default4pMode' => 'center', // starting "Race to" choice: 'center' | 'edge'
    'turnTimer' => 0,          // seconds per turn in online games, 0 = off
    'allowBots' => true,       // computer players (offline and to fill online seats)
    'onlineEnabled' => true,
    'maxRooms' => 200,
    'announcement' => ''
];

function clamp_int($v, $min, $max, $d)
{
    return is_numeric($v) ? max($min, min($max, (int) $v)) : $d;
}

function sanitize_settings(array $in, array $base)
{
    $s = $base;
    $str = function ($v) { return is_scalar($v) ? (string) $v : ''; };
    if (array_key_exists('gameName', $in)) { $s['gameName'] = str_cut(trim($str($in['gameName'])), 40); if ($s['gameName'] === '') $s['gameName'] = DEFAULTS['gameName']; }
    if (array_key_exists('boardSize', $in)) { $n = clamp_int($in['boardSize'], 5, 13, 9); if ($n % 2 === 0) $n += 1; $s['boardSize'] = min(13, $n); }
    if (array_key_exists('walls2p', $in)) $s['walls2p'] = clamp_int($in['walls2p'], 0, 40, 10);
    if (array_key_exists('walls3p', $in)) $s['walls3p'] = clamp_int($in['walls3p'], 0, 40, 7);
    if (array_key_exists('walls4p', $in)) $s['walls4p'] = clamp_int($in['walls4p'], 0, 40, 5);
    if (array_key_exists('default4pMode', $in)) $s['default4pMode'] = $in['default4pMode'] === 'edge' ? 'edge' : 'center';
    if (array_key_exists('turnTimer', $in)) $s['turnTimer'] = clamp_int($in['turnTimer'], 0, 600, 0);
    if (array_key_exists('allowBots', $in)) $s['allowBots'] = (bool) $in['allowBots'];
    if (array_key_exists('onlineEnabled', $in)) $s['onlineEnabled'] = (bool) $in['onlineEnabled'];
    if (array_key_exists('maxRooms', $in)) $s['maxRooms'] = clamp_int($in['maxRooms'], 1, 10000, 200);
    if (array_key_exists('announcement', $in)) $s['announcement'] = str_cut($str($in['announcement']), 300);
    return $s;
}

function load_settings()
{
    $saved = read_data(data_file('settings'));
    $s = sanitize_settings($saved ?: [], DEFAULTS);
    if ($saved === null && !is_file(data_file('settings'))) save_settings($s);
    return $s;
}

function save_settings(array $s)
{
    with_data(data_file('settings'), 'c+', function (&$d) use ($s) { $d = $s; });
}

/* ---------------- Admin sign-in ---------------- */
function admin_record()
{
    global $CONFIG;
    $a = read_data(data_file('admin'));
    if ($a && isset($a['hash'])) return $a;
    $initial = getenv('ADMIN_PASSWORD') ?: $CONFIG['admin_password'];
    list(, $a) = with_data(data_file('admin'), 'c+', function (&$d) use ($initial) {
        if (!$d || !isset($d['hash'])) $d = ['hash' => password_hash((string) $initial, PASSWORD_DEFAULT)];
        return $d;
    });
    return $a;
}

function check_password($pw)
{
    $a = admin_record();
    return is_string($pw) && $a && password_verify($pw, $a['hash']);
}

function set_password($pw)
{
    with_data(data_file('admin'), 'c+', function (&$d) use ($pw) { $d = ['hash' => password_hash($pw, PASSWORD_DEFAULT)]; });
}

function require_admin()
{
    // A custom header, because some PHP hosts drop the Authorization header before PHP sees it.
    $t = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if ($t === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) $t = preg_replace('/^Bearer /', '', $_SERVER['HTTP_AUTHORIZATION']);
    $sessions = read_data(data_file('sessions')) ?: [];
    $k = hash('sha256', $t);
    if ($t === '' || !isset($sessions[$k]) || $sessions[$k] < now_ms()) json_out(['error' => 'Sign in again.'], 401);
}

function new_session()
{
    $token = bin2hex(random_bytes(24));
    $exp = now_ms() + 12 * 3600 * 1000;
    with_data(data_file('sessions'), 'c+', function (&$d) use ($token, $exp) {
        if (!is_array($d)) $d = [];
        $d[hash('sha256', $token)] = $exp;
    });
    return $token;
}

/* ---------------- Stats ---------------- */
function bump_stat($key)
{
    with_data(data_file('stats'), 'c+', function (&$d) use ($key) {
        if (!is_array($d)) $d = ['gamesStarted' => 0, 'gamesFinished' => 0];
        $d[$key] = ($d[$key] ?? 0) + 1;
    });
}

function read_stats()
{
    return (read_data(data_file('stats')) ?: []) + ['gamesStarted' => 0, 'gamesFinished' => 0];
}
