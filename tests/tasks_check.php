<?php
// Execute após check.php, somente no banco dedicado terminado em _test.
require dirname(__DIR__) . '/functions.php';
if (!str_ends_with(config()['db']['name'], '_test')) {
    fwrite(STDERR, "Use um banco dedicado terminado em _test.\n"); exit(1);
}
function task_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "OK: $label\n";
}
$users=tf2_sql('SELECT id,department FROM users WHERE email_hash IN (?,?) ORDER BY role', [email_hash('gestor@example.com'),email_hash('funcionario@example.com')])->fetchAll();
if (count($users)!==2) throw new RuntimeException('Execute tests/check.php antes.');
$manager=(int)tf2_sql('SELECT id FROM users WHERE email_hash=?',[email_hash('gestor@example.com')])->fetchColumn();
$member=(int)tf2_sql('SELECT id FROM users WHERE email_hash=?',[email_hash('funcionario@example.com')])->fetchColumn();
$tasks=[]; $group=0;
try {
    tf2_sql('INSERT INTO `groups` (name,department) VALUES (?,?)',['Teste '.bin2hex(random_bytes(8)),'Teste']);
    $group=(int)db()->lastInsertId();
    foreach ([$manager,$member] as $uid) tf2_sql('INSERT INTO group_members (group_id,user_id) VALUES (?,?)',[$group,$uid]);
    $input=['title'=>'Teste de grupo','description'=>'Descrição','category'=>'Teste','type'=>'group','group_id'=>$group,'priority'=>'urgente','status'=>'concluida','due_at'=>'2020-01-01T12:00','assignees'=>[$manager,$member]];
    $id=tf2_save($input,$manager); $tasks[]=$id;
    task_check((int)tf2_sql('SELECT COUNT(*) FROM task_assignees WHERE task_id=?',[$id])->fetchColumn()===2,'grupo permite múltiplos responsáveis');
    $completed=tf2_sql('SELECT completed_at FROM tasks WHERE id=?',[$id])->fetchColumn();
    task_check((bool)$completed,'conclusão registra data');
    tf2_save($input,$manager,$id);
    task_check(tf2_sql('SELECT completed_at FROM tasks WHERE id=?',[$id])->fetchColumn()===$completed,'edição preserva data de conclusão');
    $input['status']='em_andamento'; tf2_save($input,$manager,$id);
    task_check(tf2_sql('SELECT completed_at FROM tasks WHERE id=?',[$id])->fetchColumn()===null,'reabrir limpa data de conclusão');
    tf2_sql('DELETE FROM group_members WHERE group_id=? AND user_id=?',[$group,$member]);
    try {
        tf2_save($input,$manager,$id);
        throw new RuntimeException('Aceitou responsável fora do grupo.');
    } catch (InvalidArgumentException $e) {
        task_check(true,'grupo recusa responsável sem vínculo');
    }
    task_check((int)tf2_sql('SELECT COUNT(*) FROM task_assignees WHERE task_id=?',[$id])->fetchColumn()===2,'falha preserva responsáveis anteriores');
    task_check(tf2_date('2026-02-30T12:00')===null && tf2_date('2026-02-28T12:00')!==null,'valida calendário');
} finally {
    foreach ($tasks as $id) tf2_sql('DELETE FROM tasks WHERE id=?',[$id]);
    if ($group) tf2_sql('DELETE FROM `groups` WHERE id=?',[$group]);
}
echo "7 verificações de grupos e datas concluídas.\n";
