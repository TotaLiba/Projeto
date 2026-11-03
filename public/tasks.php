<?php
require_once dirname(__DIR__) . '/functions.php';
boot(); $user=require_user();
$kanban=tf2_text($_GET,'view')==='kanban';
$tasks=tf2_list([], (int)$user['id']);
$statuses=tf2_statuses(); $priorities=tf2_priorities();
page_start('Minhas tarefas'); tf2_nav($user['role']==='gestor');
?>
<h1>Minhas tarefas</h1>
<p class="muted">Datas e horários em UTC. Atualize o estado das tarefas atribuídas a você.</p>
<p><a href="tasks.php">Lista</a> · <a href="tasks.php?view=kanban">Kanban</a></p>
<?php if (!$tasks): ?><p>Nenhuma tarefa atribuída a você.</p><?php endif ?>
<div class="<?= $kanban ? 'task-kanban' : '' ?>">
<?php foreach ($kanban ? $statuses : ['all'=>'Tarefas'] as $column=>$label): ?>
<section>
<?php if ($kanban): ?><h2><?= h($label) ?></h2><?php endif ?>
<?php $visible=array_filter($tasks,static fn($t)=>$column==='all' || $t['status']===$column); ?>
<?php if (!$visible && $kanban): ?><p>Nenhuma tarefa neste estado.</p><?php endif ?>
<?php foreach ($visible as $task): ?>
<article class="card">
<h2><?= h($task['title']) ?></h2>
<?php if ($task['overdue']): ?><p class="alert">Atrasada</p><?php endif ?>
<p><?= nl2br(h($task['description'])) ?></p>
<p>Prioridade: <?= h($priorities[$task['priority']]) ?><br>
Estado: <?= h($statuses[$task['status']]) ?><br>
Prazo: <?= h($task['due_at']) ?> UTC<br>
Categoria: <?= h($task['category']) ?><br>
Tipo: <?= h($task['type']==='individual' ? 'Individual' : 'Em grupo ou setor') ?><br>
Responsáveis: <?= h($task['assignee_names']) ?></p>
<?php if ($task['group_name']!==null): ?><p>Grupo: <?= h($task['group_name']) ?></p><?php endif ?>
<?php if ($task['parent_id']!==null): ?><p>Subtarefa de: <?= h($task['parent_title']) ?></p><?php endif ?>
<?php tf2_status_form($task); ?>
</article>
<?php endforeach ?>
</section>
<?php endforeach ?>
</div>
<?php page_end(); ?>
