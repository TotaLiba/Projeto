<?php
require_once dirname(__DIR__) . '/functions.php';
boot();
if (current_user()) redirect('index.php');
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = email_normalize(post('email'));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (time() < ($_SESSION['login_after'] ?? 0)) {
        $errors[] = 'Aguarde alguns segundos antes de tentar novamente.';
    } else {
        $stmt = db()->prepare('SELECT id, password_hash, auth_version FROM users WHERE email_hash = ?');
        $stmt->execute([email_hash($email)]);
        $user = $stmt->fetch();
        // A valid dummy hash keeps unknown accounts on the same password-check path.
        $valid = password_verify($password, $user['password_hash'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if ($user && $valid && password_error($password) === null) {
            session_regenerate_id(true);
            $_SESSION = ['user_id' => (int) $user['id'], 'auth_version' => (int) $user['auth_version'], 'last_activity' => time()];
            redirect('index.php');
        }
        $_SESSION['login_after'] = time() + 2;
        $errors[] = 'E-mail ou senha inválidos.';
    }
}
page_start('Entrar');
?>
<section class="auth card"><p class="eyebrow">BEM-VINDO AO TASKFLOW</p><h1>Acesse sua conta</h1><p class="muted">Entre com seus dados de trabalho.</p>
<?php errors($errors); ?>
<form method="post"><?php csrf_field(); ?>
<label for="email">E-mail</label><input id="email" name="email" type="email" maxlength="254" autocomplete="username" value="<?= h(post('email')) ?>" required>
<label for="password">Senha</label><input id="password" name="password" type="password" autocomplete="current-password" required>
<button class="button" type="submit">Entrar</button><a class="form-link" href="recover.php">Esqueci minha senha</a>
</form></section>
<?php page_end(); ?>
