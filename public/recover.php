<?php
require_once dirname(__DIR__) . '/functions.php';
boot();
$errors = [];
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = email_normalize(post('email'));
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Informe um e-mail válido.';
    } else {
        if (time() >= ($_SESSION['recover_after'] ?? 0)) {
            request_reset($email);
            $_SESSION['recover_after'] = time() + 30;
        }
        $sent = true;
    }
}
page_start('Recuperar senha');
?>
<section class="auth card"><p class="eyebrow">ACESSO À CONTA</p><h1>Recuperar senha</h1><p class="muted">Informe seu e-mail para receber um link de redefinição.</p>
<?php errors($errors); ?>
<?php if ($sent): ?><p class="notice" role="status">Se o e-mail estiver cadastrado, enviaremos um link. Confira também o spam. Aguarde 5 minutos antes de solicitar outro.</p><?php endif ?>
<form method="post"><?php csrf_field(); ?><label for="email">E-mail</label><input id="email" type="email" name="email" maxlength="254" autocomplete="email" required>
<button class="button" type="submit">Enviar link</button><a class="form-link" href="login.php">Voltar para o login</a></form></section>
<?php page_end(); ?>
