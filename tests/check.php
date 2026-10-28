<?php
// Destructive checks: use only a dedicated database ending in _test.
require dirname(__DIR__) . '/functions.php';
if (!str_ends_with(config()['db']['name'], '_test')) {
    fwrite(STDERR, "Use um banco dedicado com nome terminado em _test.\n"); exit(1);
}
function check(bool $ok, string $label): void {
    if (!$ok) { fwrite(STDERR, "FALHOU: $label\n"); exit(1); }
    echo "OK: $label\n";
}
db()->exec('DELETE FROM password_resets');
db()->exec('UPDATE users SET superior_id = NULL');
db()->exec('DELETE FROM users');
$password = 'Teste-Seguro-123!';
create_user(['name'=>'Gestor de teste', 'email'=>'gestor@example.com', 'job_title'=>'Diretor',
    'department'=>'Gestão', 'role'=>'gestor', 'superior_id'=>null], $password);
$manager = (int) db()->lastInsertId();
create_user(['name'=>'<script>alert(1)</script>', 'email'=>'funcionario@example.com', 'job_title'=>'Analista',
    'department'=>'Operações', 'role'=>'funcionario', 'superior_id'=>$manager], $password);
$id = (int) db()->lastInsertId();
$u = db()->query("SELECT * FROM users WHERE id = $id")->fetch();
check(!str_contains($u['email_encrypted'], '@') && email_decrypt($u['email_encrypted']) === 'funcionario@example.com', 'AES-256-GCM e leitura do e-mail');
check(email_hash(' FUNCIONARIO@example.com ') === $u['email_hash'], 'busca normalizada');
check(password_verify($password, $u['password_hash']) && !password_verify('errada', $u['password_hash']), 'hash e verificação de senha');
$encrypted = email_encrypt('teste@example.com');
check($encrypted !== email_encrypt('teste@example.com'), 'IV aleatório');
$bad = base64_decode($encrypted); $bad[28] = chr(ord($bad[28]) ^ 1);
try { email_decrypt(base64_encode($bad)); check(false, 'integridade'); }
catch (RuntimeException $e) { check(true, 'detecção de adulteração'); }
check(h('<script>"&') === '&lt;script&gt;&quot;&amp;', 'escape XSS');
check(password_error('curta') !== null && password_error(str_repeat('a', 73)) !== null, 'limites de senha');
try {
    create_user(['name'=>'Duplicado','email'=>'GESTOR@example.com','job_title'=>'Diretor','department'=>'Gestão','role'=>'gestor','superior_id'=>null], $password);
    check(false, 'e-mail único');
} catch (PDOException $e) { check(($e->errorInfo[1] ?? 0) === 1062, 'e-mail único'); }
function token(int $id, string $expiry): string {
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
        ->execute([$id, hash('sha256', $token), $expiry]);
    return $token;
}
$expired = token($id, gmdate('Y-m-d H:i:s', time()-60));
check(!reset_password($expired, 'Outra-Senha-123!'), 'token expirado recusado');
$valid = token($id, gmdate('Y-m-d H:i:s', time()+1800));
$other = token($id, gmdate('Y-m-d H:i:s', time()+1800));
check(!reset_password(str_repeat('a',64), 'Outra-Senha-123!'), 'token desconhecido recusado');
check(reset_password($valid, 'Outra-Senha-123!'), 'redefinição de senha');
check(!reset_password($valid, $password) && !reset_password($other, $password), 'uso único e invalidação de outros links');
$u = db()->query("SELECT * FROM users WHERE id = $id")->fetch();
check((int)$u['auth_version'] === 2 && password_verify('Outra-Senha-123!', $u['password_hash']), 'nova senha e revogação das sessões');
// Restore the employee password for HTTP tests.
db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
db()->exec('DELETE FROM password_resets');
echo "Verificações concluídas; contas de teste prontas para smoke.py.\n";
