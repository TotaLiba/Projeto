<?php
declare(strict_types=1);

function config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }
    $path = __DIR__ . '/config.local.php';
    if (!is_file($path)) {
        throw new RuntimeException('Copie config.example.php para config.local.php e configure o ambiente.');
    }
    $config = require $path;
    $key = base64_decode($config['app_key'] ?? '', true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Configure app_key com a chave gerada por php bin/setup.php --key.');
    }
    $url = parse_url($config['base_url'] ?? '');
    if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true)
        || empty($url['host']) || isset($url['query']) || isset($url['fragment'])
        || isset($url['user']) || isset($url['pass'])) {
        throw new RuntimeException('base_url deve ser a URL absoluta da pasta public.');
    }
    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $c = config()['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
            $c['user'], $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
