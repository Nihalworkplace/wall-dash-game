<?php
// Server settings. Game settings (board size, walls, turn timer and so on) are changed in the admin panel instead.
return [
    // Admin panel password the first time it's used. After that, change it in the admin panel.
    'admin_password' => 'admin123',

    // Where settings, the admin password and open rooms are saved. PHP must be able to write here.
    // Safest is a folder outside your public web folder, for example: dirname(__DIR__) . '/wall-dash-data'
    'data_dir' => __DIR__ . '/data',
];
