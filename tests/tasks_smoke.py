"""Executar após tests/check.php, com a migração Fase 2 no mesmo banco _test.
Uso: python3 tests/tasks_smoke.py http://127.0.0.1:8085
Usa as contas fictícias do check.php; cria e remove somente suas tarefas.
"""
import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

base = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://127.0.0.1:8085'
count = 0

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def request(op, path, data=None):
    payload = None if data is None else urllib.parse.urlencode(data, doseq=True).encode()
    try:
        response = op.open(base + '/' + path, payload)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode()

def token(body):
    return re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)

def check(ok, label):
    global count
    if not ok:
        raise AssertionError(label)
    count += 1
    print('OK:', label)

def login(email):
    op = client()
    _, body = request(op, 'login.php')
    status, body = request(op, 'login.php', {
        'csrf': token(body), 'email': email, 'password': 'Teste-Seguro-123!'
    })
    check(status == 200 and 'Sua conta' in body, 'login ' + email)
    return op

manager = login('gestor@example.com')
employee = login('funcionario@example.com')
anonymous = client()
check('Acesse sua conta' in request(anonymous, 'tasks.php')[1], 'tarefas exigem login')
for path in ['manage.php', 'task_form.php']:
    check(request(employee, path)[0] == 403, 'funcionário bloqueado em ' + path)
_, form = request(manager, 'task_form.php')
manager_id = re.search(r'<option value="(\d+)"[^>]*>Gestor de teste —', form).group(1)
employee_id = re.search(r'<option value="(\d+)"[^>]*>&lt;script&gt;alert\(1\)&lt;/script&gt; —', form).group(1)
prefix = 'Fase2-' + uuid.uuid4().hex[:8]
created = []

def data(title, assigned):
    _, body = request(manager, 'task_form.php')
    return {'csrf': token(body), 'title': title, 'description': '<script>teste</script>',
            'type': 'individual', 'assignees[]': assigned, 'priority': 'alta',
            'status': 'pendente', 'due_at': '2020-01-01T12:00', 'category': 'Teste'}

def create(title, assigned, **extra):
    values = data(title, assigned)
    values.update(extra)
    status, body = request(manager, 'task_form.php', values)
    check(status == 200 and 'Não foi possível' not in body, 'salvar ' + title)
    for row in re.findall(r'<tr>(.*?)</tr>', body, re.S):
        if title in row:
            task_id = re.search(r'task_form.php\?id=(\d+)', row).group(1)
            created.append(task_id)
            return task_id
    raise AssertionError('Tarefa não apareceu na gestão: ' + title)

try:
    own = create(prefix + '-funcionario', [employee_id])
    other = create(prefix + '-gestor', [manager_id])
    child = create(prefix + '-subtarefa', [employee_id], parent_id=own)
    _, body = request(employee, 'tasks.php')
    check(prefix + '-funcionario' in body and prefix + '-gestor' not in body, 'lista somente tarefas atribuídas')
    check('&lt;script&gt;teste&lt;/script&gt;' in body and '<script>teste</script>' not in body, 'descrição escapada')
    check('Atrasada' in body, 'atraso calculado')
    check(request(employee, 'tasks.php?view=kanban')[1].count('task-kanban') == 1, 'Kanban disponível')
    check(request(employee, 'task_status.php')[0] == 405, 'estado exige POST')
    check(request(employee, 'task_status.php', {'id': own, 'status': 'concluida'})[0] == 403, 'CSRF no estado')
    request(employee, 'task_status.php', {'csrf': token(body), 'id': other, 'status': 'concluida'})
    check(prefix + '-gestor' in request(manager, 'manage.php?status=pendente')[1], 'não altera tarefa alheia')
    request(employee, 'task_status.php', {'csrf': token(body), 'id': own, 'status': 'concluida', 'title': 'INDEVIDO', 'due_at': '2099-01-01T00:00'})
    _, listing = request(manager, 'manage.php?status=concluida')
    check(prefix + '-funcionario' in listing, 'responsável conclui tarefa')
    _, edit = request(manager, 'task_form.php?id=' + own)
    check(prefix + '-funcionario' in edit and '2020-01-01T12:00' in edit, 'funcionário não altera título ou prazo')
    for query, present in [('assignee='+employee_id, True), ('department=Operações', True),
                           ('priority=baixa', False), ('from=2099-01-01T00:00', False),
                           ('to=2020-01-01T12:00', True), ('status=atrasada', False)]:
        listing = request(manager, 'manage.php?' + urllib.parse.quote(query, safe='=&'))[1]
        check(bool(re.search('<td>' + re.escape(prefix + '-funcionario') + r'(?=\s|<)', listing)) == present, 'filtro ' + query)
    invalid = data(prefix + '-invalida', [manager_id, employee_id])
    check('exatamente um responsável' in request(manager, 'task_form.php', invalid)[1], 'individual recusa vários responsáveis')
    invalid = data(prefix + '-invalida', [employee_id])
    invalid['due_at'] = '2026-02-30T12:00'
    check('data e hora válidas' in request(manager, 'task_form.php', invalid)[1], 'recusa data inválida')
    invalid = data(prefix + '-invalida', [employee_id])
    invalid.update(type='group', department='Gestão')
    check('pertencer ao setor' in request(manager, 'task_form.php', invalid)[1], 'valida setor no servidor')
    cycle = data(prefix + '-funcionario', [employee_id])
    cycle.update(id=own, parent_id=child)
    check('ciclo de subtarefas' in request(manager, 'task_form.php', cycle)[1], 'recusa ciclo na hierarquia')
    sector = create(prefix + '-setor', [employee_id], type='group', department='Operações')
    edited = data(prefix + '-editada', [employee_id])
    edited.update(id=sector, status='em_andamento', priority='urgente')
    check(prefix + '-editada' in request(manager, 'task_form.php', edited)[1], 'gestor edita tarefa')
    check(request(manager, 'task_form.php', {'title':'sem CSRF'})[0] == 403, 'CSRF na criação')
    check(request(manager, 'manage.php', {'id':own, 'action':'delete', 'confirm':'1'})[0] == 403, 'CSRF na exclusão')
    _, confirm = request(manager, 'manage.php?delete=' + own)
    check('Confirmar exclusão' in confirm and 'subtarefas' in confirm, 'exclusão exige confirmação')
    check(request(manager, 'manage.php', {'csrf':token(confirm), 'id':own, 'action':'delete'})[0] == 422, 'recusa exclusão sem confirmação')
    check(request(employee, 'manage.php', {'csrf':token(body), 'id':own, 'action':'delete', 'confirm':'1'})[0] == 403, 'funcionário não exclui')
    request(manager, 'manage.php', {'csrf':token(confirm), 'id':own, 'action':'delete', 'confirm':'1'})
    check(request(manager, 'task_form.php?id='+own)[0] == 404 and request(manager, 'task_form.php?id='+child)[0] == 404, 'exclusão remove tarefa e subtarefa')
finally:
    for task_id in reversed(created):
        _, body = request(manager, 'manage.php')
        request(manager, 'manage.php', {'csrf':token(body), 'id':task_id, 'action':'delete', 'confirm':'1'})
print(f'{count} verificações da Fase 2 concluídas.')
