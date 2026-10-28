<?php
require_once dirname(__DIR__) . '/functions.php';
boot();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
    $token = is_string($_GET['token']) ? $_GET['token'] : '';
    $_SESSION['reset_token'] = preg_match('/^[a-f0-9]{64}$/D', $token) ? $token : '';
    redirect('reset.php');
}
$errors = [];
$token = $_SESSION['reset_token'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if ($error = password_error($password)) $errors[] = $error;
    if ($password !== ($_POST['confirmation'] ?? null)) $errors[] = 'As senhas não conferem.';
    if (!$errors) {
        if (reset_password($token, $password)) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['flash'] = 'Senha alterada. Entre com sua nova senha.';
            redirect('login.php');
        }
        $errors[] = 'Link inválido, expirado ou já utilizado. Solicite outro link.';
    }
}
page_start('Redefinir senha');
?>
<section class="auth card"><p class="eyebrow">ACESSO À CONTA</p><h1>Defina sua nova senha</h1>
<?php errors($errors); ?>
<?php if ($token): ?><form method="post"><?php csrf_field(); ?>
<label for="password">Nova senha</label><input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-help" required>
<p class="help" id="password-help">Use de 12 a 72 bytes. Acentos podem ocupar mais de um byte.</p>
<label for="confirmation">Confirme a senha</label><input id="confirmation" name="confirmation" type="password" autocomplete="new-password" required>
<button class="button" type="submit">Salvar nova senha</button></form>
<?php else: ?><p class="alert">Abra o link enviado ao seu e-mail para redefinir sua senha.</p><?php endif ?>
<a class="form-link" href="recover.php">Solicitar outro link</a></section>
<?php page_end(); ?>
