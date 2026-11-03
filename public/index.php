<?php
require_once dirname(__DIR__) . '/functions.php';
boot();
$user = require_user();
page_start('Visão geral');
?>
<p class="eyebrow">SEU ESPAÇO DE TRABALHO</p><h1>Olá, <?= h($user['name']) ?>.</h1>
<p class="muted">Bem-vindo ao TaskFlow.</p>
<section class="card"><h2>Sua conta</h2><dl class="details">
<dt>E-mail</dt><dd><?= h(email_decrypt($user['email_encrypted'])) ?></dd>
<dt>Cargo</dt><dd><?= h($user['job_title']) ?></dd><dt>Setor</dt><dd><?= h($user['department']) ?></dd>
<dt>Perfil</dt><dd><?= $user['role'] === 'gestor' ? 'Gestor' : 'Funcionário' ?></dd>
</dl><?php if ($user['role'] === 'gestor'): ?><a class="button inline" href="users.php">Gerenciar usuários</a><?php endif ?></section>
<?php tf2_nav($user['role'] === 'gestor'); ?>
<?php page_end(); ?>
