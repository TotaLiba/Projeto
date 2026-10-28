<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') === '--key') {
    echo base64_encode(random_bytes(32)) . PHP_EOL;
    exit;
}
require_once dirname(__DIR__) . '/functions.php';
config();
if ((int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'gestor'")->fetchColumn() > 0) {
    fwrite(STDERR, "Já existe um gestor. Use o cadastro pela aplicação.\n");
    exit(1);
}
function ask(string $label): string
{
    fwrite(STDOUT, $label . ': ');
    $line = fgets(STDIN);
    if ($line === false) { fwrite(STDERR, "Entrada encerrada.\n"); exit(1); }
    return trim($line);
}
$data = ['name' => ask('Nome'), 'email' => email_normalize(ask('E-mail')),
    'job_title' => ask('Cargo'), 'department' => ask('Setor'), 'role' => 'gestor', 'superior_id' => null];
fwrite(STDOUT, "A senha será visível neste terminal local. Não use um terminal compartilhado.\n");
$password = ask('Senha (12 a 72 bytes)');
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 254
    || password_error($password)) { fwrite(STDERR, "E-mail ou senha inválidos.\n"); exit(1); }
foreach (['name', 'job_title', 'department'] as $field) {
    if ($data[$field] === '' || preg_match('//u', $data[$field]) !== 1 || preg_match_all('/./us', $data[$field]) > 120) {
        fwrite(STDERR, "Nome, cargo e setor devem ter de 1 a 120 caracteres.\n"); exit(1);
    }
}
create_user($data, $password);
echo "Gestor criado. Acesse a tela de login.\n";
