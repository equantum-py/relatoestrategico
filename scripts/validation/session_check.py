#!/usr/bin/env python3
"""Prueba interactiva de sesión real, ejecutada por Dirección en su equipo.
Sin contraseña por argumentos/env/archivo; cookies y CSRF solo en RAM.
Solo escribe en auth/login y auth/logout. Nunca modifica contenido ni usuarios.
No demuestra persistencia MariaDB ni aceptación del frontend no desplegado.
"""
import getpass
import http.cookiejar
import json
import sys
import urllib.error
import urllib.request

API = 'https://api.relatoestrategico.cl'
ORIGIN = 'https://www.relatoestrategico.cl'

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # No transmitir credenciales a otro destino.

def main():
    if not sys.stdin.isatty():
        print('BLOQUEADO: requiere una terminal interactiva; no redirijas contraseñas.', file=sys.stderr)
        return 2
    if input('Prueba de sesión autorizada, sin cambios de contenido. Escribe VALIDAR: ').strip() != 'VALIDAR':
        return 2
    email = input('Correo de tu cuenta existente: ').strip()
    if not email:
        return 2
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(jar))
    csrf = None
    authenticated = False
    results = []
    failed = False

    def call(method, path, body=None, token=None):
        headers = {'Origin': ORIGIN, 'Accept': 'application/json', 'Cache-Control': 'no-store'}
        raw = None
        if body is not None:
            headers['Content-Type'] = 'application/json'
            raw = json.dumps(body).encode()
        if token:
            headers['X-CSRF-Token'] = token
        req = urllib.request.Request(API + path, data=raw, headers=headers, method=method)
        try:
            response = opener.open(req, timeout=20)
        except urllib.error.HTTPError as exc:
            response = exc
        with response:
            data = json.loads(response.read(120001))
            return response.code, data

    try:
        status, form = call('GET', '/v1/auth/csrf')
        if status != 200 or not isinstance(form.get('csrf'), str):
            raise ValueError('csrf_contract')
        password = getpass.getpass('Contraseña actual (oculta, no se guardará en disco): ')
        try:
            status, result = call('POST', '/v1/auth/login', {'email': email, 'password': password}, form['csrf'])
        finally:
            password = None
        if status != 200 or result.get('ok') is not True:
            results.append({'test': 'login', 'result': 'FAIL', 'http': status})
            failed = True
        else:
            authenticated = True
            csrf = result.get('csrf')
            results.append({'test': 'login', 'result': 'PASS', 'http': status})
            status, me = call('GET', '/v1/auth/me')
            valid = status == 200 and me.get('ok') is True and isinstance(me.get('user'), dict)
            if me.get('csrf'):
                csrf = me['csrf']
            results.append({'test': 'session_me', 'result': 'PASS' if valid else 'FAIL', 'http': status})
            failed |= not valid
    except Exception:
        # No imprimir excepciones ni respuestas que puedan contener información privada.
        results.append({'test': 'session', 'result': 'FAIL', 'reason': 'network_or_contract_error'})
        failed = True
    finally:
        if authenticated:
            try:
                status, result = call('POST', '/v1/auth/logout', {}, csrf)
                valid = status == 200 and result.get('ok') is True
                results.append({'test': 'logout', 'result': 'PASS' if valid else 'FAIL', 'http': status})
                failed |= not valid
                status, _ = call('GET', '/v1/auth/me')
                valid = status == 401
                results.append({'test': 'me_after_logout', 'result': 'PASS' if valid else 'FAIL', 'http': status})
                failed |= not valid
            except Exception:
                results.append({'test': 'logout', 'result': 'FAIL', 'reason': 'session_revocation_not_confirmed'})
                failed = True
        jar.clear()
        csrf = None
    print(json.dumps({'scope': 'real HTTP session only; no content writes; no browser-cookie proof', 'results': results}, indent=2))
    return 1 if failed else 0

if __name__ == '__main__':
    sys.exit(main())
