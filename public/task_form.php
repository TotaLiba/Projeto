<?php
require_once dirname(__DIR__) . '/functions.php';
boot(); $user=require_user(true);
$isPost=$_SERVER['REQUEST_METHOD']==='POST';
if ($isPost) check_csrf();
$id=tf2_id($isPost ? ($_POST['id'] ?? null) : ($_GET['id'] ?? null));
$messages=[];
$task=['title'=>'','description'=>'','parent_id'=>'','type'=>'individual','group_id'=>'','category'=>'','priority'=>'media','status'=>'pendente','due_at'=>'','department'=>''];
$selected=[];
if ($id) {
    $existing=tf2_sql('SELECT * FROM tasks WHERE id=?',[$id])->fetch();
    if (!$existing) { http_response_code(404); exit('Tarefa não encontrada.'); }
    $task=array_merge($task,$existing);
    $task['due_at']=substr(str_replace(' ','T',$existing['due_at']),0,16);
    $assigned=tf2_sql('SELECT u.id,u.department FROM users u JOIN task_assignees a ON a.user_id=u.id WHERE a.task_id=?',[$id])->fetchAll();
    $selected=array_map('intval',array_column($assigned,'id'));
    $departments=array_values(array_unique(array_column($assigned,'department')));
    if (count($departments)===1) $task['department']=$departments[0];
}
if ($isPost) {
    foreach (['title','description','parent_id','type','group_id','category','priority','status','due_at','department'] as $key) $task[$key]=post($key);
    $selected=is_array($_POST['assignees'] ?? null) ? array_filter(array_map('tf2_id',$_POST['assignees'])) : [];
    try {
        tf2_save($_POST,(int)$user['id'],$id);
        redirect('manage.php');
    } catch (InvalidArgumentException $e) { $messages[]=$e->getMessage(); }
    catch (Throwable $e) {
        error_log('TaskFlow: falha ao salvar tarefa / code '.$e->getCode());
        $messages[]='Não foi possível salvar a tarefa. Tente novamente.';
    }
}
$users=tf2_sql('SELECT id,name,department FROM users ORDER BY name,id')->fetchAll();
$groups=tf2_sql('SELECT id,name,department FROM `groups` ORDER BY name,id')->fetchAll();
$parents=tf2_sql('SELECT id,title FROM tasks ORDER BY title,id')->fetchAll();
$departmentOptions=tf2_departments(array_column($users,'department'));
ksort($departmentOptions);
page_start($id ? 'Editar tarefa' : 'Nova tarefa'); tf2_nav(true);
?>
<h1><?= h($id ? 'Editar tarefa' : 'Nova tarefa') ?></h1>
<?php errors($messages); ?>
<form method="post" action="task_form.php" class="card grid-form">
<?php csrf_field(); ?>
<input type="hidden" name="id" value="<?= h((string)$id) ?>">
<label class="full">Título<input name="title" maxlength="200" required value="<?= h($task['title']) ?>"></label>
<label class="full">Descrição<textarea name="description" maxlength="15000" rows="5"><?= h($task['description']) ?></textarea></label>
<label>Tarefa principal<select name="parent_id"><option value="">Nenhuma</option>
<?php tf2_options(array_column(array_filter($parents,static fn($parent)=>(int)$parent['id']!==$id),'title','id'),(string)$task['parent_id']); ?>
</select></label>
<label>Tipo<select name="type"><?php tf2_options(['individual'=>'Individual','group'=>'Em grupo ou setor'],$task['type']); ?></select></label>
<label>Grupo<select name="group_id"><option value="">Sem grupo — usar setor</option>
<?php tf2_options(array_combine(array_column($groups,'id'),array_map(static fn($row)=>$row['name'].' — '.$row['department'],$groups)),(string)$task['group_id']); ?>
</select></label>
<label>Setor, quando não houver grupo<select name="department"><option value="">Selecione</option><?php tf2_options($departmentOptions,$task['department']); ?></select></label>
<label class="full">Responsáveis<select name="assignees[]" multiple required size="6">
<?php tf2_options(array_combine(array_column($users,'id'),array_map(static fn($row)=>$row['name'].' — '.$row['department'],$users)),$selected); ?>
</select></label>
<p class="help full">Individual: selecione uma pessoa. Em grupo ou setor: selecione uma ou mais pessoas. Use Ctrl ou Command para selecionar várias pessoas no computador. O servidor verifica os vínculos.</p>
<label>Categoria<input name="category" maxlength="100" value="<?= h($task['category']) ?>"></label>
<label>Prioridade<select name="priority"><?php tf2_options(tf2_priorities(),$task['priority']); ?></select></label>
<label>Estado<select name="status"><?php tf2_options(tf2_statuses(),$task['status']); ?></select></label>
<label>Prazo em UTC<input type="datetime-local" name="due_at" required value="<?= h($task['due_at']) ?>"></label>
<div class="full"><button class="button inline" type="submit">Salvar tarefa</button> <a href="manage.php">Cancelar</a></div>
</form>
<?php page_end(); ?>
