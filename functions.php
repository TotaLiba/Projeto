<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log('TaskFlow: ' . get_class($e) . ' / code ' . $e->getCode());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Falha na operação. Verifique a configuração e o banco de dados.\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>Não foi possível concluir a operação.</h1><p>Tente novamente ou fale com o administrador.</p>';
    exit;
});

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function boot(): void
{
    $c = config();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('taskflow');
    session_set_cookie_params([
        'httponly' => true, 'secure' => (bool) $c['secure_cookie'],
        'samesite' => 'Lax', 'path' => '/',
    ]);
    session_start();
    header('Content-Type: text/html; charset=UTF-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    if (isset($_SESSION['user_id']) && time() - ($_SESSION['last_activity'] ?? 0) > $c['session_timeout']) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'] = 'Sua sessão expirou. Entre novamente.';
    }
    if (isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): void
{
    echo '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}

function check_csrf(): void
{
    if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals(csrf(), $_POST['csrf'])) {
        http_response_code(403);
        exit('Formulário expirado. Volte, atualize a página e tente novamente.');
    }
}

function post(string $name): string
{
    return is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
}

function email_normalize(string $email): string
{
    return strtolower(trim($email));
}

function email_hash(string $email): string
{
    return hash_hmac('sha256', email_normalize($email), base64_decode(config()['app_key'], true));
}

function email_encrypt(string $email): string
{
    $iv = random_bytes(12);
    $cipher = openssl_encrypt(email_normalize($email), 'aes-256-gcm',
        base64_decode(config()['app_key'], true), OPENSSL_RAW_DATA, $iv, $tag, 'TaskFlow.email.v1');
    if ($cipher === false) {
        throw new RuntimeException('Falha na criptografia.');
    }
    return base64_encode($iv . $tag . $cipher);
}

function email_decrypt(string $encrypted): string
{
    $data = base64_decode($encrypted, true);
    if ($data === false || strlen($data) < 29) {
        throw new RuntimeException('E-mail inválido no banco.');
    }
    $email = openssl_decrypt(substr($data, 28), 'aes-256-gcm',
        base64_decode(config()['app_key'], true), OPENSSL_RAW_DATA,
        substr($data, 0, 12), substr($data, 12, 16), 'TaskFlow.email.v1');
    if ($email === false) {
        throw new RuntimeException('Falha ao decifrar e-mail.');
    }
    return $email;
}

function password_error(string $password): ?string
{
    return strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")
        ? 'Use uma senha entre 12 e 72 bytes (acentos podem ocupar mais de um byte).' : null;
}

function current_user(): ?array
{
    static $user;
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    if ($user === null) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || (int) $user['auth_version'] !== ($_SESSION['auth_version'] ?? 0)) {
            $_SESSION = [];
            session_regenerate_id(true);
            return null;
        }
    }
    return $user;
}

function require_user(bool $manager = false): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    if ($manager && $user['role'] !== 'gestor') {
        http_response_code(403);
        exit('Acesso exclusivo do gestor.');
    }
    return $user;
}

function create_user(array $data, string $password): void
{
    $stmt = db()->prepare('INSERT INTO users
        (name, email_encrypted, email_hash, password_hash, job_title, department, superior_id, role)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$data['name'], email_encrypt($data['email']), email_hash($data['email']),
        password_hash($password, PASSWORD_DEFAULT), $data['job_title'], $data['department'],
        $data['superior_id'], $data['role']]);
}

function request_reset(string $email): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, email_encrypted FROM users WHERE email_hash = ? FOR UPDATE');
        $stmt->execute([email_hash($email)]);
        $user = $stmt->fetch();
        if (!$user) {
            $pdo->commit();
            return;
        }
        $stmt = $pdo->prepare('SELECT id FROM password_resets WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 5 MINUTE LIMIT 1');
        $stmt->execute([$user['id']]);
        if ($stmt->fetch()) {
            $pdo->commit();
            return;
        }
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
        $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 30 MINUTE)')
            ->execute([$user['id'], hash('sha256', $token)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $c = config();
    if (!filter_var($c['mail_from'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $c['mail_from'])) {
        throw new RuntimeException('Remetente inválido.');
    }
    $url = rtrim($c['base_url'], '/') . '/reset.php?token=' . $token;
    $body = "Recebemos uma solicitação para redefinir sua senha no TaskFlow.\r\n\r\n"
        . $url . "\r\n\r\nO link vale por 30 minutos e pode ser usado uma única vez.\r\n"
        . "Se você não solicitou, ignore esta mensagem.\r\n";
    $sent = mail(email_decrypt($user['email_encrypted']), 'TaskFlow - Redefinir senha', $body,
        ['From' => $c['mail_from'], 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8']);
    if (!$sent) error_log('TaskFlow: transporte de e-mail indisponível.');
}

function reset_password(string $token, string $password): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the user first, matching request_reset's lock order.
        $stmt = $pdo->prepare('SELECT user_id FROM password_resets WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        $id = $stmt->fetchColumn();
        if (!$id) { $pdo->rollBack(); return false; }
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$id]);
        $stmt = $pdo->prepare('SELECT id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() FOR UPDATE');
        $stmt->execute([hash('sha256', $token)]);
        if (!$stmt->fetch()) { $pdo->rollBack(); return false; }
        $pdo->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        $pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')->execute([$id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function page_start(string $title): void
{
    $user = current_user();
    ?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · TaskFlow</title><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head>
<body>
<header class="mobile-header"><a href="index.php" class="brand">Task<span>Flow</span></a><button type="button" id="menu-toggle" aria-controls="sidebar" aria-expanded="false">Menu</button></header>
<aside id="sidebar" class="sidebar"><a class="brand" href="index.php">Task<span>Flow</span></a><p class="tagline">Gestão que conecta pessoas.</p>
<nav aria-label="Menu principal">
<?php if ($user): ?>
<a href="index.php">Visão geral</a>
<?php if ($user['role'] === 'gestor'): ?><a href="users.php">Usuários</a><?php endif ?>
<?php else: ?><a href="login.php">Entrar</a><a href="recover.php">Recuperar senha</a><?php endif ?>
</nav><div class="sidebar-footer">
<?php if ($user): ?><strong><?= h($user['name']) ?></strong><span><?= $user['role'] === 'gestor' ? 'Gestor' : 'Funcionário' ?></span>
<form action="logout.php" method="post"><?php csrf_field(); ?><button class="button secondary" type="submit">Sair da conta</button></form>
<?php else: ?><span>TaskFlow · Fase 1</span><?php endif ?>
</div></aside><main><div class="content">
<?php if (isset($_SESSION['flash'])): ?><p class="notice" role="status"><?= h($_SESSION['flash']) ?></p><?php unset($_SESSION['flash']); endif ?>
<?php
}

function page_end(): void
{
    echo '</div></main></body></html>';
}

function errors(array $errors): void
{
    if (!$errors) return;
    echo '<div class="alert" role="alert"><ul>';
    foreach ($errors as $error) echo '<li>' . h($error) . '</li>';
    echo '</ul></div>';
}
