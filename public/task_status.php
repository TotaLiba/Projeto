<?php
require_once dirname(__DIR__) . '/functions.php';
boot();
$user=require_user();
if ($_SERVER['REQUEST_METHOD']!=='POST') {
    http_response_code(405); header('Allow: POST'); exit('Método não permitido.');
}
check_csrf();
$id=tf2_id($_POST['id'] ?? null); $status=post('status');
if (!$id || !isset(tf2_statuses()[$status])) { http_response_code(422); exit('Tarefa ou estado inválido.'); }
$sql="UPDATE tasks SET completed_at=CASE WHEN ?<>'concluida' THEN NULL WHEN completed_at IS NULL THEN UTC_TIMESTAMP() ELSE completed_at END,status=?,updated_at=UTC_TIMESTAMP() WHERE id=?";
$params=[$status,$status,$id];
if ($user['role']!=='gestor') {
    $sql.=' AND EXISTS (SELECT 1 FROM task_assignees a WHERE a.task_id=tasks.id AND a.user_id=?)';
    $params[]=(int)$user['id'];
}
tf2_sql($sql,$params);
redirect('tasks.php');
