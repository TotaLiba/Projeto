# TaskFlow — Fase 2

## Instalação sem apagar a Fase 1

1. Faça backup do banco e dos arquivos da instalação.
2. No phpMyAdmin, selecione o banco já configurado no TaskFlow.
3. Use **Importar** para executar `migration_fase2.sql` uma única vez.
4. Publique os arquivos desta versão. Preserve `config.local.php` e a chave existente.
5. Entre normalmente e use os links da página **Visão geral**.

A migração cria somente quatro tabelas novas. Não execute novamente `database/schema.sql` na instalação existente. PHP 8.1+, PDO MySQL e OpenSSL continuam sendo os requisitos; não há dependência nova de Composer, npm ou mbstring.

## Uso

- Gestor: **Gerenciar tarefas** para filtrar, criar, editar e excluir.
- Qualquer usuário: **Minhas tarefas** para ver suas atribuições em lista ou Kanban e atualizar o estado.
- Tarefa individual exige um responsável. Tarefa em grupo/setor aceita um ou mais.
- Para setor, deixe o grupo vazio e selecione o setor e os responsáveis correspondentes.
- Grupos nomeados usam `groups` e `group_members`, preenchidos pelo administrador no phpMyAdmin. A interface de administração desses cadastros não faz parte desta fase.
- O prazo é obrigatório e usa UTC, indicado nos formulários e listagens.
- “Atrasada” é calculado na consulta, nunca armazenado como estado.
- Excluir uma tarefa também exclui todas as suas subtarefas e atribuições, após confirmação. Não exclui usuários ou grupos.
- Concluir uma tarefa não conclui automaticamente suas subtarefas.

## Mudanças por arquivo

| Arquivo | Alteração |
| --- | --- |
| `migration_fase2.sql` | Cria grupos, membros, tarefas e responsáveis, com índices e chaves estrangeiras. |
| `functions.php` | Acrescenta helpers `tf2_` para consultas preparadas, filtros, validação e gravação transacional. Nas funções anteriores, troca somente o texto `TaskFlow · Fase 1` por `TaskFlow`. |
| `public/index.php` | Substitui o aviso da próxima fase por links de tarefas conforme o perfil. |
| `public/tasks.php` | Lista e Kanban das tarefas atribuídas ao usuário. |
| `public/task_form.php` | Formulário de criação/edição exclusivo do gestor. |
| `public/manage.php` | Gestão com filtros e confirmação de exclusão por POST. |
| `public/task_status.php` | Atualização exclusiva do estado; funcionário só altera suas atribuições. |
| `public/assets/style.css` | Acrescenta regras pequenas para Kanban responsivo e textarea, usando as cores do tema existente. |
| `tests/tasks_check.php` | Verifica grupos, múltiplos responsáveis, integridade e datas. |
| `tests/tasks_smoke.py` | Verifica CRUD, filtros, Kanban, CSRF e autorização por HTTP. |
| `README.md` | Inclui acesso às instruções desta fase. |
| `FASE2.md` | Instalação, uso, alterações e validação. |

`config.php`, `config.local.php`, o schema original, JavaScript, telas de autenticação/recuperação e gestão de usuários não foram alterados. O menu lateral existente é preservado; as telas novas usam o mesmo layout.

## Testes

Use exclusivamente uma instalação separada cujo banco termine em `_test`. Importe o schema original nessa instalação e a migração da Fase 2. Configure o arquivo local privado para esse banco.

1. `php tests/check.php` — destrutivo: limpa usuários e tokens do banco de teste; exige que não existam tarefas vinculadas aos usuários.
2. `php tests/tasks_check.php` — usa as contas fictícias criadas no passo anterior e limpa seus próprios registros ao terminar.
3. Inicie o servidor: `php -S 127.0.0.1:8085 -t public`.
4. `python3 tests/smoke.py http://127.0.0.1:8085`.
5. `python3 tests/tasks_smoke.py http://127.0.0.1:8085` — cria e remove suas próprias tarefas.

Validação desta implementação: PHP 8.4 e MariaDB 11.8, sintaxe PHP, 13 verificações existentes de banco/segurança, 16 verificações HTTP da Fase 1, 7 verificações de grupos/datas e 35 verificações HTTP da Fase 2. Os testes existentes não foram modificados.

SMTP real, Apache de produção e inspeção visual em navegador não fazem parte dessa validação automatizada. A migração foi aplicada somente ao banco local dedicado de teste; a instalação de destino deve importá-la antes de abrir as telas novas.

## Escopo

Implementados CRUD, subtarefas com prevenção de ciclos, grupos/setores, responsáveis múltiplos, prioridades, prazo obrigatório, estados, atraso calculado, lista/Kanban e filtros por responsável, setor, estado, prioridade e intervalo de prazo. POSTs têm CSRF e autorização é verificada no servidor.

Fora desta entrega: administração visual de grupos, comentários, anexos, notificações, relatórios e calendário.
