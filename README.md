# Wall Dash (PHP version)

A wall-and-race board game for 2, 3 or 4 players: online rooms, offline play, and an admin panel.
This is the PHP version of the Node.js project in `../wall-dash`. It runs on ordinary PHP web hosting: no Node, no database, no Composer.

## Requirements

- PHP 7.2 or newer (PHP 8 works)
- PHP must be able to write to the `data/` folder (it's created on first use)
- Apache, LiteSpeed or Nginx. Any host that runs `.php` files works.

## Put it online

1. Upload everything in this folder to your site, either the main web folder (`public_html`) or a subfolder like `public_html/wall-dash/`.
2. Open `config.php` and change `admin_password`. That password is only used the first time; after that, change it in the admin panel.
3. Open the site. The `data/` folder is created on the first request. If you see "The server can’t save data", create a `data` folder yourself and give it write permission (755 or 775 in your host's file manager).

- Game: `https://your-site.com/` (or `/wall-dash/`)
- Admin panel: `https://your-site.com/admin.html` (`/admin` also works on Apache)

Use HTTPS. The offline mode (service worker) only runs on HTTPS or localhost.

## Try it on your computer

```bash
php -S localhost:8080
```

Then open http://localhost:8080. The admin panel is at http://localhost:8080/admin.html.

## How it differs from the Node version

- **No WebSockets.** PHP hosting can't keep connections open, so while a player is in an online room the page asks `api.php` for changes about once a second (every 4 seconds when the tab is hidden). For a turn-based game you won't notice the difference.
- **Computer turns and turn timers** run when a player in that room checks in, because there's no background process. A room with nobody watching it simply waits.
- **A player shows as "offline"** when their page hasn't checked in for 12 seconds.
- **Rooms are saved as files** in `data/rooms/`, so restarting PHP doesn't end games in progress.
- **The admin's "players in rooms"** counts players currently in a room, not people on the home screen.

## Files

- `api.php`: every request from the game and the admin panel (`api.php?action=...`)
- `config.php`: first admin password and where data is saved
- `lib/Game.php`, `lib/AI.php`: rules and computer player for online games (PHP copies of the JS files)
- `lib/rooms.php`: online rooms
- `lib/app.php`: saved files, settings, admin sign-in
- `shared/game.js`, `shared/ai.js`: rules and computer player in the browser (offline play)
- `index.html`, `admin.html`, `js/`, `css/`: the pages
- `data/`: settings, admin password, stats and open rooms (created automatically)

**If you change the rules, change both copies:** `shared/game.js` and `lib/Game.php` (and `shared/ai.js` with `lib/AI.php`).
After changing any page files, bump `VERSION` in `sw.js` so phones fetch the new version.

## Keeping data private

Each file in `data/` starts with a line of PHP that makes the web server show nothing, so the files can't be downloaded even without extra setup. On Apache, `data/.htaccess` also blocks the folder.
To be extra safe, move the data folder outside your public web folder in `config.php`:

```php
'data_dir' => dirname(__DIR__) . '/wall-dash-data',
```

On Nginx you can also add:

```nginx
location ~ ^/(data|lib)/ { deny all; }
```

## How many players

Each player in an online room makes about one small request per second. Shared hosting handles a few dozen players at once without trouble. If your host limits "entry processes" or requests per second and you expect many more players, move to a VPS or use the Node version.

## Rules

- Each turn: move your pawn one square, or place a wall (two squares long).
- If another pawn is directly in front of you, jump over it. If a wall or pawn is behind it, step to its side instead.
- Walls can't overlap or cross, and can never shut any pawn in completely.
- Two ways to win (picked per game or room): be first to the gold centre ★ square, or first to any square on the far edge. The board fills your destination squares with colour.
- 2, 3 and 4 players: either way to win.
- 3 players: the fourth side stays empty. Turns go clockwise.
