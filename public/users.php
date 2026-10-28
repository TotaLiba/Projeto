<?php
require_once dirname(__DIR__) . '/functions.php';
boot();
require_user(true);
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $data = ['name' => post('name'), 'email' => email_normalize(post('email')),
        'job_title' => post('job_title'), 'department' => post('department'),
        'role' => post('role'), 'superior_id' => post('superior_id') ?: null];
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    foreach (['name' => 'Nome', 'job_title' => 'Cargo', 'department' => 'Setor'] as $key => $label) {
        if ($data[$key] === '' || preg_match('//u', $data[$key]) !== 1
            || preg_match_all('/./us', $data[$key]) > 120) $errors[] = "$label deve ter entre 1 e 120 caracteres.";
    }
    if (strlen($data['email']) > 254 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Informe um e-mail válido.';
    if (!in_array($data['role'], ['gestor', 'funcionario'], true)) $errors[] = 'Selecione um perfil válido.';
    if ($error = password_error($password)) $errors[] = $error;
    if ($password !== ($_POST['confirmation'] ?? null)) $errors[] = 'As senhas não conferem.';
    if ($data['role'] === 'funcionario' && !$data['superior_id']) $errors[] = 'Selecione o superior do funcionário.';
    if ($data['superior_id'] !== null) {
        $stmt = db()->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([preg_match('/^[0-9]+$/D', $data['superior_id']) ? $data['superior_id'] : '0']);
        if (!$stmt->fetch()) $errors[] = 'Selecione um superior válido.';
    }
    if (!$errors) {
        try {
            create_user($data, $password);
            $_SESSION['flash'] = 'Usuário cadastrado com sucesso.';
            redirect('users.php');
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) $errors[] = 'Este e-mail já está cadastrado.';
            else throw $e;
        }
    }
}
$users = db()->query('SELECT u.*, s.name AS superior_name FROM users u LEFT JOIN users s ON s.id = u.superior_id ORDER BY u.name')->fetchAll();
page_start('Usuários');
?>
<p class="eyebrow">EQUIPE</p><h1>Usuários</h1><p class="muted">Cadastre pessoas e organize os vínculos da empresa.</p>
<section class="card"><h2>Novo usuário</h2><?php errors($errors); ?>
<form method="post" class="grid-form"><?php csrf_field(); ?>
<?php foreach (['name' => 'Nome completo', 'email' => 'E-mail', 'job_title' => 'Cargo', 'department' => 'Setor'] as $key => $label): ?>
<div><label for="<?= h($key) ?>"><?= h($label) ?></label><input id="<?= h($key) ?>" name="<?= h($key) ?>" type="<?= $key === 'email' ? 'email' : 'text' ?>" maxlength="<?= $key === 'email' ? 254 : 120 ?>" value="<?= h(post($key)) ?>" required></div>
<?php endforeach ?>
<div><label for="role">Perfil</label><select id="role" name="role" required><option value="funcionario" <?= post('role') !== 'gestor' ? 'selected' : '' ?>>Funcionário</option><option value="gestor" <?= post('role') === 'gestor' ? 'selected' : '' ?>>Gestor</option></select></div>
<div><label for="superior_id">Superior</label><select id="superior_id" name="superior_id"><option value="">Sem superior (somente gestor)</option>
<?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= post('superior_id') === (string) $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?> · <?= h($u['job_title']) ?></option><?php endforeach ?>
</select></div>
<div><label for="password">Senha inicial</label><input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-help" required><p class="help" id="password-help">De 12 a 72 bytes; acentos podem ocupar mais de um byte.</p></div>
<div><label for="confirmation">Confirme a senha</label><input id="confirmation" name="confirmation" type="password" autocomplete="new-password" required></div>
<div class="full"><button class="button inline" type="submit">Cadastrar usuário</button></div></form></section>
<section class="card"><h2>Pessoas cadastradas <span class="badge"><?= count($users) ?></span></h2><div class="table-scroll"><table><caption class="sr-only">Usuários e seus vínculos</caption><thead><tr><th>Nome / E-mail</th><th>Cargo / Setor</th><th>Perfil</th><th>Superior</th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr><td><strong><?= h($u['name']) ?></strong><small><?= h(email_decrypt($u['email_encrypted'])) ?></small></td><td><?= h($u['job_title']) ?><small><?= h($u['department']) ?></small></td><td><span class="badge"><?= $u['role'] === 'gestor' ? 'Gestor' : 'Funcionário' ?></span></td><td><?= h($u['superior_name'] ?? '—') ?></td></tr><?php endforeach ?>
</tbody></table></div></section>
<?php page_end(); ?>
