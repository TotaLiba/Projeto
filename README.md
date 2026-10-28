# TaskFlow · Fase 1

Sistema de acesso e gestão de usuários em PHP puro, HTML, CSS e JavaScript. Sem Composer, npm ou frameworks. O módulo de tarefas não está implementado.

## Estrutura

```text
TaskFlow/
├── .htaccess                 # Bloqueia acesso HTTP aos arquivos internos
├── .gitignore
├── config.example.php        # Modelo de configuração
├── config.local.php          # Configuração privada, criada na instalação e ignorada pelo Git
├── config.php                # Leitura da configuração e conexão PDO
├── functions.php             # Segurança, autenticação, usuários, recuperação e layout
├── database/schema.sql       # Banco e tabelas
├── bin/setup.php              # Chave e primeiro gestor, somente pelo terminal
├── public/                    # Raiz pública do site
│   ├── .htaccess
│   ├── index.php              # Visão geral da própria conta
│   ├── login.php
│   ├── logout.php
│   ├── users.php              # Cadastro e listagem, somente para gestores
│   ├── recover.php
│   ├── reset.php
│   └── assets/
│       ├── style.css
│       └── app.js
└── tests/
    ├── check.php              # Testes com banco dedicado
    └── smoke.py               # Testes HTTP com biblioteca padrão do Python
```

## Instalação no XAMPP

Requisitos: PHP **8.1 ou superior**, extensões `pdo_mysql` e `openssl`, Apache 2.4 e MySQL 8 ou MariaDB 10.4+. Os serviços do XAMPP devem estar iniciados. `php -m` lista as extensões ativas; o PHP do terminal e o Apache devem usar a configuração correta.

1. Copie o projeto para `C:\xampp\htdocs\TaskFlow`.
2. No phpMyAdmin, importe `database/schema.sql`. O script cria o banco `taskflow`, a tabela `users` e a tabela `password_resets`. Não contém senha nem usuário padrão.
3. Crie um usuário de banco dedicado (troque a senha do exemplo), executando como administrador no MySQL:

   ```sql
   CREATE USER 'taskflow'@'localhost' IDENTIFIED BY 'SUBSTITUA_POR_UMA_SENHA_FORTE';
   GRANT SELECT, INSERT, UPDATE ON taskflow.* TO 'taskflow'@'localhost';
   ```

4. Copie `config.example.php` para `config.local.php` e preencha `db` com host, porta, banco, usuário e senha. Não use a conta root na aplicação.
5. Abra o terminal na pasta do projeto e gere a chave:

   ```bat
   C:\xampp\php\php.exe bin\setup.php --key
   ```

   Cole o resultado em `app_key` no arquivo local. A chave contém 32 bytes em Base64. Guarde um backup seguro dela junto ao backup do banco. **Não troque a chave depois de cadastrar usuários**: os e-mails criptografados e seus hashes de busca dependem dela. O arquivo local não deve ser versionado nem exposto publicamente.

6. Confira `base_url`, por exemplo `http://localhost/TaskFlow/public`, sem barra no final. Os links enviados por e-mail sempre usam essa URL configurada. Para acessar de outro computador, use um endereço realmente acessível por ele; `localhost` aponta para a própria máquina de quem abre o link.
7. Crie o primeiro gestor pelo terminal:

   ```bat
   C:\xampp\php\php.exe bin\setup.php
   ```

   Informe nome, e-mail, cargo, setor e uma senha de 12 a 72 bytes. A senha é digitada visivelmente nesse terminal local; use um terminal privado. O comando recusa novos cadastros quando já existe um gestor. Os próximos usuários são cadastrados na aplicação. Funcionários precisam de um superior; gestores podem ficar sem superior.

8. Acesse `http://localhost/TaskFlow/public/` e entre com o gestor criado.

Os arquivos `.htaccess` exigem `AllowOverride All` no diretório do projeto. O da raiz nega acesso aos arquivos internos; o de `public` libera apenas o conteúdo público. Verifique que `/TaskFlow/database/schema.sql` e `/TaskFlow/config.local.php` respondem **403**, e `/TaskFlow/public/login.php` responde **200**. Não remova essa proteção para corrigir um erro 403; ajuste o Apache.

Para uma implantação própria, prefira apontar o `DocumentRoot` diretamente para `TaskFlow/public`. Exemplo de VirtualHost (ajuste o caminho e a resolução do nome local):

```apache
<VirtualHost *:80>
    ServerName taskflow.local
    DocumentRoot "C:/xampp/htdocs/TaskFlow/public"
    <Directory "C:/xampp/htdocs/TaskFlow/public">
        AllowOverride All
        Options -Indexes
        Require all granted
    </Directory>
</VirtualHost>
```

Reinicie o Apache após alterar sua configuração. Se usar esse endereço, ajuste também `base_url`.

## E-mail de recuperação

O código usa `mail()`. O transporte SMTP é configurado **no XAMPP**, fora do projeto. Não coloque credenciais SMTP nos arquivos versionados.

No Windows, configure o `sendmail.exe` fornecido pelo XAMPP. Em `C:\xampp\php\php.ini`, seção `[mail function]`, configure:

```ini
sendmail_path = "\"C:\xampp\sendmail\sendmail.exe\" -t"
```

Em `C:\xampp\sendmail\sendmail.ini`, seção `[sendmail]`, ajuste conforme seu provedor:

```ini
smtp_server=smtp.seu-provedor.com
smtp_port=587
smtp_ssl=tls
auth_username=conta@seu-dominio.com
auth_password=SENHA_OU_SENHA_DE_APLICATIVO
force_sender=conta@seu-dominio.com
```

Use os parâmetros e o método de autenticação aceitos pelo provedor, mantenha a verificação TLS habilitada e restrinja o acesso ao arquivo de credenciais. Alguns provedores exigem senha de aplicativo ou bloqueiam autenticação por senha. Configure `mail_from` em `config.local.php` com o remetente autorizado. Reinicie o Apache.

Em Linux, `mail()` precisa de um agente local de envio configurado em `sendmail_path`. O código da aplicação permanece o mesmo.

Teste com um endereço real cadastrado: solicite a recuperação, abra o link recebido, redefina a senha e tente usar o link novamente. O segundo uso deve ser recusado. `mail()` retornar sucesso significa que o transporte aceitou a mensagem, não que ela chegou à caixa postal. Se não receber, consulte spam, logs do sendmail e logs de erro do Apache/PHP. Uma falha de transporte é registrada sem expor o link. Aguarde 5 minutos para solicitar outro envio.

## Segurança e comportamento

- Senhas com `password_hash(PASSWORD_DEFAULT)` e `password_verify`; limite de 72 bytes evita truncamento silencioso em bcrypt. Senhas nunca são guardadas ou enviadas por e-mail em texto puro.
- E-mails normalizados com remoção dos espaços externos e letras minúsculas; criptografia autenticada AES-256-GCM, IV aleatório de 12 bytes e tag de 16 bytes. HMAC-SHA-256 para busca e unicidade, sem armazenar o e-mail em texto puro.
- PDO com prepared statements e emulação desativada. Consultas sem parâmetros de usuário usam SQL fixo.
- Saídas dinâmicas escapadas com `htmlspecialchars`. CSP restringe recursos à própria origem e impede enquadramento em outros sites.
- CSRF em todos os formulários, inclusive login, recuperação e logout. Logout aceita somente POST.
- Sessão regenerada no login; cookies HttpOnly e SameSite=Lax; expiração após 30 minutos de inatividade, configurável em segundos.
- Permissões conferidas no servidor. Funcionários veem apenas sua própria conta; gestores cadastram e listam usuários. Não há cadastro público.
- Tokens de recuperação de 32 bytes aleatórios, guardados apenas como hash SHA-256, válidos por 30 minutos. Transações e bloqueios de linha impedem reutilização simultânea. Novo envio invalida links anteriores. Redefinir a senha revoga as sessões existentes.
- Após abrir o link, o token passa para a sessão e a URL é limpa. Cabeçalhos `no-store` e `no-referrer` reduzem exposição; configure também a política de retenção dos logs do servidor, que podem registrar a URL inicial.
- Recuperação usa resposta genérica para contas existentes e inexistentes. Há intervalo de 5 minutos por conta entre solicitações e 30 segundos por sessão; login aplica pausa de 2 segundos após falha na mesma sessão. Essas pausas não substituem limitação persistente de tentativas no servidor/proxy para um serviço público.
- Para produção, use HTTPS, configure `secure_cookie => true`, proteja o arquivo local e backups e mantenha PHP, Apache e banco atualizados. XAMPP é destinado a desenvolvimento local.

## Validação

Validação executada nesta entrega com PHP 8.4 e MariaDB 11.8: sintaxe PHP, importação do SQL, criptografia/autenticação, cadastro, permissões, proteção CSRF, escape XSS, tentativa de SQL injection, expiração/uso único dos tokens e fluxos HTTP. A recuperação completa foi exercitada com `mail()` e transporte local de captura, incluindo revogação das sessões e login com a nova senha. A expiração por inatividade também foi verificada.

As telas foram abertas no Chromium em 390 px e 1440 px, sem rolagem horizontal da página ou erros de JavaScript; menu móvel e obrigatoriedade do superior foram verificados. O transporte SMTP real e as regras do Apache/XAMPP precisam ser validados na instalação de destino: o servidor usado nos testes foi o servidor de desenvolvimento do PHP.

Para repetir os testes automatizados:

1. Use uma instalação separada com banco cujo nome termine em `_test`. Importe o SQL trocando o nome do banco. Aponte o arquivo local dessa instalação para ele, usando uma conta com permissão também de DELETE.
2. Execute `php tests/check.php`. **Esse teste apaga todos os usuários e tokens do banco de teste** e cria contas fictícias. Ele recusa nomes de banco que não terminem em `_test`.
3. Inicie `php -S 127.0.0.1:8085 -t public` na mesma instalação.
4. Em outro terminal, execute `python tests/smoke.py http://127.0.0.1:8085` (Python 3, sem dependências externas). Repita o passo 2 antes de cada nova execução.

Nunca execute os testes contra dados reais. Para verificar a sintaxe, execute `php -l` em cada arquivo PHP. Os testes não alteram código ou dependências.

## Implementado e pendente

Implementado nesta fase: estrutura, SQL, conexão PDO, primeiro gestor por CLI, login/logout, sessão e permissões, cadastro/listagem com superior, recuperação e redefinição de senha, proteções solicitadas, interface responsiva em português e instruções de instalação.

A configuração local da conexão, da chave, do remetente e do transporte SMTP é necessária em cada instalação. Não há contas, chaves ou credenciais de produção incluídas.

Para as próximas fases: módulo de tarefas; edição/desativação de usuários e perfis mais detalhados, caso necessários. Para exposição pública: limitação persistente de tentativas e monitoramento operacional. Nenhum módulo de tarefas foi antecipado.
