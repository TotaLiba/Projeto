"""Run after check.php against a server using the same dedicated _test database."""
import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

base = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://127.0.0.1:8085'

def client():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)), jar

def request(opener, path, data=None):
    payload = None if data is None else urllib.parse.urlencode(data).encode()
    try:
        response = opener.open(base + '/' + path, payload)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode(), response.headers

def csrf(html):
    return re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)

def check(ok, label):
    if not ok:
        raise AssertionError(label)
    print('OK:', label)

op, jar = client()
status, body, headers = request(op, 'users.php')
check('Acesse sua conta' in body, 'cadastro exige autenticação')
check("frame-ancestors 'none'" in headers['Content-Security-Policy'], 'cabeçalhos de segurança')
check(request(op, 'login.php', {'email':'gestor@example.com','password':'Teste-Seguro-123!'})[0] == 403, 'CSRF obrigatório no login')
_, body, _ = request(op, 'login.php')
old_id = next(iter(jar)).value
_, body, _ = request(op, 'login.php', {'csrf':csrf(body),'email':"' OR 1=1 --",'password':'errada'})
check('E-mail ou senha inválidos' in body, 'tentativa de SQL injection não autentica')
# A separate cookie jar avoids the intentionally imposed per-session delay.
op, jar = client()
_, body, _ = request(op, 'login.php')
old_id = next(iter(jar)).value
_, body, _ = request(op, 'login.php', {'csrf':csrf(body),'email':'GESTOR@example.com','password':'Teste-Seguro-123!'})
check('Olá, Gestor de teste.' in body and next(iter(jar)).value != old_id, 'login e regeneração da sessão')
_, body, _ = request(op, 'users.php')
check('&lt;script&gt;alert(1)&lt;/script&gt;' in body and '<script>alert(1)</script>' not in body, 'XSS escapado na listagem')
check(request(op, 'users.php', {'name':'Sem token'})[0] == 403, 'CSRF obrigatório no cadastro')
superior = re.search(r'<option value="(\d+)"[^>]*>Gestor de teste', body).group(1)
data = {'csrf':csrf(body),'name':'Nova pessoa','email':'nova@example.com','job_title':'Analista','department':'TI',
        'role':'funcionario','superior_id':superior,'password':'Senha-Inicial-123!','confirmation':'Senha-Inicial-123!'}
_, body, _ = request(op, 'users.php', data)
check('Usuário cadastrado com sucesso.' in body and 'Nova pessoa' in body, 'gestor cadastra funcionário com superior')
data['csrf'] = csrf(body)
_, body, _ = request(op, 'users.php', data)
check('Este e-mail já está cadastrado.' in body, 'cadastro duplicado recusado')
check(request(op, 'logout.php')[0] == 405, 'logout não aceita GET')
_, body, _ = request(op, 'logout.php', {'csrf':csrf(body)})
check('Acesse sua conta' in body and 'Acesse sua conta' in request(op, 'users.php')[1], 'logout encerra acesso')
op, _ = client()
_, body, _ = request(op, 'login.php')
_, body, _ = request(op, 'login.php', {'csrf':csrf(body),'email':'funcionario@example.com','password':'Teste-Seguro-123!'})
check('Sua conta' in body, 'funcionário acessa a própria conta')
check(request(op, 'users.php')[0] == 403, 'funcionário não acessa cadastro')
check(request(op, 'recover.php', {'email':'funcionario@example.com'})[0] == 403, 'CSRF obrigatório na recuperação')
_, body, _ = request(op, 'recover.php')
_, body, _ = request(op, 'recover.php', {'csrf':csrf(body),'email':'inexistente@example.com'})
check('Se o e-mail estiver cadastrado' in body, 'recuperação não revela conta inexistente')
check(request(op, 'reset.php', {'password':'Outra-Senha-123!'})[0] == 403, 'CSRF obrigatório na redefinição')
print('Fluxos HTTP concluídos.')
