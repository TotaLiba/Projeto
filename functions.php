<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log('TaskFlow: ' . get_class($e) . ' / code ' . $e->getCode());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Falha na operação. Verifique a configuração e o banco de dados.\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>Não foi possível concluir a operação.</h1><p>Tente novamente ou fale com o administrador.</p>';
    exit;
});

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function boot(): void
{
    $c = config();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('taskflow');
    session_set_cookie_params([
        'httponly' => true, 'secure' => (bool) $c['secure_cookie'],
        'samesite' => 'Lax', 'path' => '/',
    ]);
    session_start();
    header('Content-Type: text/html; charset=UTF-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    if (isset($_SESSION['user_id']) && time() - ($_SESSION['last_activity'] ?? 0) > $c['session_timeout']) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'] = 'Sua sessão expirou. Entre novamente.';
    }
    if (isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): void
{
    echo '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}

function check_csrf(): void
{
    if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals(csrf(), $_POST['csrf'])) {
        http_response_code(403);
        exit('Formulário expirado. Volte, atualize a página e tente novamente.');
    }
}

function post(string $name): string
{
    return is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
}

function email_normalize(string $email): string
{
    return strtolower(trim($email));
}

function email_hash(string $email): string
{
    return hash_hmac('sha256', email_normalize($email), base64_decode(config()['app_key'], true));
}

function email_encrypt(string $email): string
{
    $iv = random_bytes(12);
    $cipher = openssl_encrypt(email_normalize($email), 'aes-256-gcm',
        base64_decode(config()['app_key'], true), OPENSSL_RAW_DATA, $iv, $tag, 'TaskFlow.email.v1');
    if ($cipher === false) {
        throw new RuntimeException('Falha na criptografia.');
    }
    return base64_encode($iv . $tag . $cipher);
}

function email_decrypt(string $encrypted): string
{
    $data = base64_decode($encrypted, true);
    if ($data === false || strlen($data) < 29) {
        throw new RuntimeException('E-mail inválido no banco.');
    }
    $email = openssl_decrypt(substr($data, 28), 'aes-256-gcm',
        base64_decode(config()['app_key'], true), OPENSSL_RAW_DATA,
        substr($data, 0, 12), substr($data, 12, 16), 'TaskFlow.email.v1');
    if ($email === false) {
        throw new RuntimeException('Falha ao decifrar e-mail.');
    }
    return $email;
}

function password_error(string $password): ?string
{
    return strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")
        ? 'Use uma senha entre 12 e 72 bytes (acentos podem ocupar mais de um byte).' : null;
}

function current_user(): ?array
{
    static $user;
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    if ($user === null) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || (int) $user['auth_version'] !== ($_SESSION['auth_version'] ?? 0)) {
            $_SESSION = [];
            session_regenerate_id(true);
            return null;
        }
    }
    return $user;
}

function require_user(bool $manager = false): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    if ($manager && $user['role'] !== 'gestor') {
        http_response_code(403);
        exit('Acesso exclusivo do gestor.');
    }
    return $user;
}

function create_user(array $data, string $password): void
{
    $stmt = db()->prepare('INSERT INTO users
        (name, email_encrypted, email_hash, password_hash, job_title, department, superior_id, role)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$data['name'], email_encrypt($data['email']), email_hash($data['email']),
        password_hash($password, PASSWORD_DEFAULT), $data['job_title'], $data['department'],
        $data['superior_id'], $data['role']]);
}

function request_reset(string $email): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, email_encrypted FROM users WHERE email_hash = ? FOR UPDATE');
        $stmt->execute([email_hash($email)]);
        $user = $stmt->fetch();
        if (!$user) {
            $pdo->commit();
            return;
        }
        $stmt = $pdo->prepare('SELECT id FROM password_resets WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 5 MINUTE LIMIT 1');
        $stmt->execute([$user['id']]);
        if ($stmt->fetch()) {
            $pdo->commit();
            return;
        }
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
        $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 30 MINUTE)')
            ->execute([$user['id'], hash('sha256', $token)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $c = config();
    if (!filter_var($c['mail_from'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $c['mail_from'])) {
        throw new RuntimeException('Remetente inválido.');
    }
    $url = rtrim($c['base_url'], '/') . '/reset.php?token=' . $token;
    $body = "Recebemos uma solicitação para redefinir sua senha no TaskFlow.\r\n\r\n"
        . $url . "\r\n\r\nO link vale por 30 minutos e pode ser usado uma única vez.\r\n"
        . "Se você não solicitou, ignore esta mensagem.\r\n";
    $sent = mail(email_decrypt($user['email_encrypted']), 'TaskFlow - Redefinir senha', $body,
        ['From' => $c['mail_from'], 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8']);
    if (!$sent) error_log('TaskFlow: transporte de e-mail indisponível.');
}

function reset_password(string $token, string $password): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the user first, matching request_reset's lock order.
        $stmt = $pdo->prepare('SELECT user_id FROM password_resets WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        $id = $stmt->fetchColumn();
        if (!$id) { $pdo->rollBack(); return false; }
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$id]);
        $stmt = $pdo->prepare('SELECT id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() FOR UPDATE');
        $stmt->execute([hash('sha256', $token)]);
        if (!$stmt->fetch()) { $pdo->rollBack(); return false; }
        $pdo->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        $pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')->execute([$id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function page_start(string $title): void
{
    $user = current_user();
    ?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · TaskFlow</title><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head>
<body>
<header class="mobile-header"><a href="index.php" class="brand">Task<span>Flow</span></a><button type="button" id="menu-toggle" aria-controls="sidebar" aria-expanded="false">Menu</button></header>
<aside id="sidebar" class="sidebar"><a class="brand" href="index.php">Task<span>Flow</span></a><p class="tagline">Gestão que conecta pessoas.</p>
<nav aria-label="Menu principal">
<?php if ($user): ?>
<a href="index.php">Visão geral</a>
<?php if ($user['role'] === 'gestor'): ?><a href="users.php">Usuários</a><?php endif ?>
<?php else: ?><a href="login.php">Entrar</a><a href="recover.php">Recuperar senha</a><?php endif ?>
</nav><div class="sidebar-footer">
<?php if ($user): ?><strong><?= h($user['name']) ?></strong><span><?= $user['role'] === 'gestor' ? 'Gestor' : 'Funcionário' ?></span>
<form action="logout.php" method="post"><?php csrf_field(); ?><button class="button secondary" type="submit">Sair da conta</button></form>
<?php else: ?><span>TaskFlow</span><?php endif ?>
</div></aside><main><div class="content">
<?php if (isset($_SESSION['flash'])): ?><p class="notice" role="status"><?= h($_SESSION['flash']) ?></p><?php unset($_SESSION['flash']); endif ?>
<?php
}

function page_end(): void
{
    echo '</div></main></body></html>';
}

function errors(array $errors): void
{
    if (!$errors) return;
    echo '<div class="alert" role="alert"><ul>';
    foreach ($errors as $error) echo '<li>' . h($error) . '</li>';
    echo '</ul></div>';
}

// Fase 2: helpers exclusivos do módulo de tarefas.
function tf2_sql(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function tf2_statuses(): array
{
    return ['pendente'=>'Pendente', 'em_andamento'=>'Em andamento', 'concluida'=>'Concluída'];
}

function tf2_priorities(): array
{
    return ['baixa'=>'Baixa', 'media'=>'Média', 'alta'=>'Alta', 'urgente'=>'Urgente'];
}

function tf2_text(array $source, string $key): string
{
    return is_string($source[$key] ?? null) ? trim($source[$key]) : '';
}

function tf2_id(mixed $value): int
{
    if (!is_string($value) && !is_int($value)) return 0;
    return (int) (filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0);
}

function tf2_date(string $value): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
    return $date && $date->format('Y-m-d\TH:i') === $value ? $date->format('Y-m-d H:i:s') : null;
}

function tf2_options(array $options, string $selected): void
{
    foreach ($options as $value=>$label) {
        echo '<option value="'.h((string)$value).'"'.((string)$value === $selected ? ' selected' : '').'>'.h((string)$label).'</option>';
    }
}

function tf2_nav(bool $manager): void
{
    echo '<nav aria-label="Tarefas"><a href="tasks.php">Minhas tarefas</a>';
    if ($manager) echo ' · <a href="manage.php">Gerenciar tarefas</a> · <a href="task_form.php">Nova tarefa</a>';
    echo '</nav>';
}

function tf2_list(array $filters = [], ?int $userId = null): array
{
    $where = []; $params = [];
    foreach ([$userId, $filters['assignee'] ?? null] as $assigned) {
        if ($assigned) {
            $where[] = 'EXISTS (SELECT 1 FROM task_assignees a WHERE a.task_id=t.id AND a.user_id=?)';
            $params[] = $assigned;
        }
    }
    if (!empty($filters['department'])) {
        $where[] = '(g.department=? OR EXISTS (SELECT 1 FROM task_assignees a JOIN users u ON u.id=a.user_id WHERE a.task_id=t.id AND u.department=?))';
        $params[] = $filters['department']; $params[] = $filters['department'];
    }
    if (!empty($filters['status'])) {
        if ($filters['status'] === 'atrasada') $where[] = "t.due_at<UTC_TIMESTAMP() AND t.status<>'concluida'";
        else { $where[] = 't.status=?'; $params[] = $filters['status']; }
    }
    foreach (['priority'=>'t.priority = ?', 'from'=>'t.due_at >= ?', 'to'=>'t.due_at <= ?'] as $key=>$clause) {
        if (!empty($filters[$key])) { $where[]=$clause; $params[]=$filters[$key]; }
    }
    $sql = "SELECT t.*, g.name AS group_name, p.title AS parent_title,
        (t.due_at<UTC_TIMESTAMP() AND t.status<>'concluida') AS overdue,
        (SELECT GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') FROM task_assignees a JOIN users u ON u.id=a.user_id WHERE a.task_id=t.id) AS assignee_names
        FROM tasks t LEFT JOIN `groups` g ON g.id=t.group_id LEFT JOIN tasks p ON p.id=t.parent_id";
    if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
    return tf2_sql($sql." ORDER BY (t.status<>'concluida') DESC, t.due_at, t.id", $params)->fetchAll();
}

function tf2_status_form(array $task): void
{
    echo '<form method="post" action="task_status.php">';
    csrf_field();
    echo '<input type="hidden" name="id" value="'.h((string)$task['id']).'"><label>Estado<select name="status">';
    tf2_options(tf2_statuses(), $task['status']);
    echo '</select></label><button class="button inline" type="submit">Atualizar estado</button></form>';
}

function tf2_save(array $input, int $creatorId, int $id = 0): int
{
    $title=tf2_text($input,'title'); $description=tf2_text($input,'description');
    $category=tf2_text($input,'category'); $type=tf2_text($input,'type');
    $priority=tf2_text($input,'priority'); $status=tf2_text($input,'status');
    $department=tf2_text($input,'department');
    $parent=tf2_id($input['parent_id'] ?? null) ?: null;
    $group=tf2_id($input['group_id'] ?? null) ?: null;
    $due=tf2_date(tf2_text($input,'due_at'));
    // Conta caracteres UTF-8 sem adicionar uma dependência de mbstring à Fase 1.
    $titleLength=preg_match_all('/./us', $title);
    $categoryLength=preg_match_all('/./us', $category);
    if ($title === '' || $titleLength === false || $titleLength > 200) throw new InvalidArgumentException('Informe um título válido com até 200 caracteres.');
    if ($categoryLength === false || $categoryLength > 100 || strlen($description)>60000 || !preg_match('//u', $description)) throw new InvalidArgumentException('Categoria ou descrição inválida ou muito longa.');
    if (!in_array($type,['individual','group'],true) || !isset(tf2_priorities()[$priority]) || !isset(tf2_statuses()[$status])) throw new InvalidArgumentException('Tipo, prioridade ou estado inválido.');
    if (!$due) throw new InvalidArgumentException('Informe data e hora válidas em UTC.');
    if (!is_array($input['assignees'] ?? null)) throw new InvalidArgumentException('Selecione os responsáveis.');
    $assignees=[];
    foreach ($input['assignees'] as $value) {
        $uid=tf2_id($value);
        if (!$uid) throw new InvalidArgumentException('Responsável inválido.');
        $assignees[$uid]=$uid;
    }
    $assignees=array_values($assignees);
    if (!$assignees || count($assignees)>500) throw new InvalidArgumentException('Selecione entre 1 e 500 responsáveis.');
    if ($type==='individual') {
        if (count($assignees)!==1) throw new InvalidArgumentException('Selecione exatamente um responsável para a tarefa individual.');
        $group=null;
    } elseif (!$group && $department==='') throw new InvalidArgumentException('Selecione um grupo ou setor.');
    $pdo=db(); $pdo->beginTransaction();
    try {
        $parents=[];
        foreach (tf2_sql('SELECT id,parent_id FROM tasks ORDER BY id FOR UPDATE')->fetchAll() as $row) $parents[(int)$row['id']]=$row['parent_id']===null ? null : (int)$row['parent_id'];
        if ($id && !array_key_exists($id,$parents)) throw new InvalidArgumentException('Tarefa não encontrada.');
        if ($parent!==null && !array_key_exists($parent,$parents)) throw new InvalidArgumentException('Tarefa principal não encontrada.');
        $cursor=$parent; $seen=[];
        while ($cursor!==null) {
            if (($id && $cursor===$id) || isset($seen[$cursor])) throw new InvalidArgumentException('A tarefa principal criaria um ciclo de subtarefas.');
            $seen[$cursor]=true; $cursor=$parents[$cursor] ?? null;
        }
        $marks=implode(',',array_fill(0,count($assignees),'?'));
        $users=tf2_sql("SELECT id,department FROM users WHERE id IN ($marks)",$assignees)->fetchAll();
        if (count($users)!==count($assignees)) throw new InvalidArgumentException('Responsável não encontrado.');
        if ($type==='group' && $group) {
            $members=tf2_sql("SELECT user_id FROM group_members WHERE group_id=? AND user_id IN ($marks)",array_merge([$group],$assignees))->fetchAll(PDO::FETCH_COLUMN);
            if (count($members)!==count($assignees)) throw new InvalidArgumentException('Os responsáveis devem ser membros do grupo selecionado.');
        } elseif ($type==='group') {
            foreach ($users as $member) if ($member['department']!==$department) throw new InvalidArgumentException('Os responsáveis devem pertencer ao setor selecionado.');
        }
        $values=[$title,$description,$parent,$type,$group,$category,$priority,$due,$status,$status];
        if ($id) {
            tf2_sql("UPDATE tasks SET title=?,description=?,parent_id=?,type=?,group_id=?,category=?,priority=?,due_at=?,completed_at=CASE WHEN ?<>'concluida' THEN NULL WHEN completed_at IS NULL THEN UTC_TIMESTAMP() ELSE completed_at END,status=?,updated_at=UTC_TIMESTAMP() WHERE id=?",array_merge($values,[$id]));
            tf2_sql('DELETE FROM task_assignees WHERE task_id=?',[$id]);
        } else {
            tf2_sql("INSERT INTO tasks (title,description,parent_id,type,group_id,category,priority,due_at,completed_at,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,CASE WHEN ?='concluida' THEN UTC_TIMESTAMP() ELSE NULL END,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",array_merge($values,[$creatorId]));
            $id=(int)$pdo->lastInsertId();
        }
        foreach ($assignees as $uid) tf2_sql('INSERT INTO task_assignees (task_id,user_id) VALUES (?,?)',[$id,$uid]);
        $pdo->commit(); return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
