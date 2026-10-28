<?php
require_once dirname(__DIR__) . '/functions.php';
boot();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Use o botão Sair da conta.');
}
check_csrf();
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'],
    'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
redirect('login.php');
