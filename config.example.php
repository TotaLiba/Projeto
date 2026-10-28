<?php
return [
    'db' => [
        'host' => '127.0.0.1', 'port' => 3306, 'name' => 'taskflow',
        'user' => 'taskflow', 'password' => '',
    ],
    // Generate once: php bin/setup.php --key. Keep this key backed up.
    'app_key' => '',
    'base_url' => 'http://localhost/TaskFlow/public',
    'mail_from' => 'taskflow@example.com',
    'session_timeout' => 1800,
    'secure_cookie' => false, // Set true with HTTPS in production.
];
