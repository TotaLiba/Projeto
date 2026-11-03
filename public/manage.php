<?php
require_once dirname(__DIR__) . '/functions.php';
boot(); require_user(true);
$messages=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf(); $id=tf2_id($_POST['id'] ?? null);
    if (!$id || post('action')!=='delete' || post('confirm')!=='1') {
        http_response_code(422); $messages[]='Confirme a exclusão de uma tarefa válida.';
    } else {
        try {
            $stmt=tf2_sql('DELETE FROM tasks WHERE id=?',[$id]);
            if ($stmt->rowCount()) redirect('manage.php');
            http_response_code(404); $messages[]='Tarefa não encontrada.';
        } catch (Throwable $e) {
            error_log('TaskFlow: falha ao excluir tarefa / code '.$e->getCode());
            $messages[]='Não foi possível excluir a tarefa.';
        }
    }
}
$deleteTask=null;
if ($deleteId=tf2_id($_GET['delete'] ?? null)) {
    $deleteTask=tf2_sql('SELECT id,title FROM tasks WHERE id=?',[$deleteId])->fetch();
    if (!$deleteTask) { http_response_code(404); $messages[]='Tarefa não encontrada.'; }
}
$users=tf2_sql('SELECT id,name FROM users ORDER BY name,id')->fetchAll();
$departments=[];
foreach (['SELECT DISTINCT department FROM users', 'SELECT DISTINCT department FROM `groups`'] as $sql) {
    foreach (tf2_sql($sql)->fetchAll(PDO::FETCH_COLUMN) as $department) if ($department!=='') $departments[$department]=$department;
}
ksort($departments);
$values=[]; foreach (['assignee','department','status','priority','from','to'] as $key) $values[$key]=tf2_text($_GET,$key);
$filters=$values; $filters['assignee']=tf2_id($values['assignee']);
if ($values['assignee']!=='' && !$filters['assignee']) $messages[]='Responsável inválido.';
$statuses=tf2_statuses(); $priorities=tf2_priorities();
if ($values['status']!=='' && $values['status']!=='atrasada' && !isset($statuses[$values['status']])) $messages[]='Estado inválido.';
if ($values['priority']!=='' && !isset($priorities[$values['priority']])) $messages[]='Prioridade inválida.';
foreach (['from','to'] as $key) {
    if ($values[$key]!=='') {
        $filters[$key]=tf2_date($values[$key]);
        if (!$filters[$key]) $messages[]='Informe datas válidas nos filtros de prazo.';
    }
}
if ($filters['to']) $filters['to']=substr($filters['to'],0,16).':59';
if ($filters['from'] && $filters['to'] && $filters['from']>$filters['to']) $messages[]='O início do prazo deve ser anterior ao fim.';
$tasks=$messages ? [] : tf2_list($filters);
page_start('Gerenciar tarefas'); tf2_nav(true);
?>
<h1>Gerenciar tarefas</h1>
<?php errors($messages); ?>
<?php if ($deleteTask): ?>
<section class="card"><h2>Confirmar exclusão</h2>
<p>Excluir “<?= h($deleteTask['title']) ?>”?</p>
<p>As subtarefas e os vínculos de responsáveis também serão excluídos. Esta ação não pode ser desfeita.</p>
<form method="post" action="manage.php"><?php csrf_field(); ?>
<input type="hidden" name="action" value="delete"><input type="hidden" name="confirm" value="1">
<input type="hidden" name="id" value="<?= h((string)$deleteTask['id']) ?>">
<button class="button inline" type="submit">Confirmar exclusão</button> <a href="manage.php">Cancelar</a></form></section>
<?php endif ?>
<form method="get" action="manage.php" class="card grid-form">
<label>Responsável<select name="assignee"><option value="">Todos</option>
<?php foreach ($users as $member): ?><option value="<?= h((string)$member['id']) ?>" <?= $values['assignee']===(string)$member['id'] ? 'selected' : '' ?>><?= h($member['name']) ?></option><?php endforeach ?>
</select></label>
<label>Setor<select name="department"><option value="">Todos</option><?php tf2_options($departments,$values['department']); ?></select></label>
<label>Estado<select name="status"><option value="">Todos</option><?php tf2_options($statuses+['atrasada'=>'Atrasada'],$values['status']); ?></select></label>
<label>Prioridade<select name="priority"><option value="">Todas</option><?php tf2_options($priorities,$values['priority']); ?></select></label>
<label>Prazo a partir de — UTC<input type="datetime-local" name="from" value="<?= h($values['from']) ?>"></label>
<label>Prazo até — UTC<input type="datetime-local" name="to" value="<?= h($values['to']) ?>"></label>
<div class="full"><button class="button inline" type="submit">Filtrar</button> <a href="manage.php">Limpar filtros</a></div>
</form>
<?php if (!$messages && !$tasks): ?><p>Nenhuma tarefa encontrada.</p><?php endif ?>
<div class="table-scroll" role="region" aria-label="Tabela de tarefas" tabindex="0"><table>
<thead><tr><th>Tarefa</th><th>Responsáveis</th><th>Prioridade</th><th>Estado</th><th>Prazo UTC</th><th>Ações</th></tr></thead>
<tbody><?php foreach ($tasks as $task): ?><tr>
<td><?= h($task['title']) ?>
<?php if ($task['parent_id']!==null): ?><small>Subtarefa de: <?= h($task['parent_title']) ?></small><?php endif ?>
<?php if ($task['group_name']!==null): ?><small>Grupo: <?= h($task['group_name']) ?></small><?php endif ?></td>
<td><?= h($task['assignee_names']) ?></td><td><?= h($priorities[$task['priority']]) ?></td>
<td><?= h($statuses[$task['status']]) ?><?php if ($task['overdue']): ?><small>Atrasada</small><?php endif ?></td>
<td><?= h($task['due_at']) ?></td>
<td><a href="task_form.php?id=<?= h((string)$task['id']) ?>">Editar</a> · <a href="manage.php?delete=<?= h((string)$task['id']) ?>">Excluir</a></td>
</tr><?php endforeach ?></tbody></table></div>
<?php page_end(); ?>
