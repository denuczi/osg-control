#!/usr/bin/env python3
"""OSG Nodo Control first-run/configuration wizard. Run INSIDE Linux/WSL2.
Credentials never appear on the command line or in Compose variables.
"""
import base64
import getpass
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import sys
import warnings
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

ROOT = Path(__file__).resolve().parents[2]
RUNTIME = ROOT / 'secrets'
APP_ENV = RUNTIME / 'laravel.env'
COMPOSE_ENV = ROOT / '.env'


def read_lines(path: Path):
    if not path.exists():
        return []
    return path.read_text(encoding='utf-8').splitlines()


def get_value(lines, key, default=''):
    for line in lines:
        if line.startswith(key + '='):
            value = line.split('=', 1)[1].strip()
            if len(value) >= 2 and value[0] == '"' and value[-1] == '"':
                return value[1:-1].replace('\\"', '"').replace('\\\\', '\\').replace('\\$', '$')
            return value
    return default


def set_value(lines, key, value):
    if not re.fullmatch(r'[A-Z][A-Z0-9_]*', key):
        raise ValueError('Nombre de clave inválido')
    if '\n' in value or '\r' in value or '\x00' in value:
        raise ValueError('Valor inválido con salto de línea')
    # Minimal dotenv double quote escaping, exact for user-provided names/password-free values.
    if not re.fullmatch(r'[A-Za-z0-9_./:@+,=-]*', value):
        value = '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$') + '"'
    changed = False
    for i, line in enumerate(lines):
        if line.startswith(key + '='):
            lines[i] = key + '=' + value
            changed = True
            break
    if not changed:
        lines.append(key + '=' + value)
    return lines


def persist(path: Path, lines, mode=0o600):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    if path == APP_ENV:
        path.parent.chmod(0o700)
    temp = path.with_name(path.name + '.tmp-' + secrets.token_hex(4))
    with temp.open('w', encoding='utf-8') as f:
        f.write('\n'.join(lines).rstrip('\n') + '\n')
        f.flush()
        os.fsync(f.fileno())
    temp.chmod(mode)
    temp.replace(path)


def question(prompt, default=''):
    suffix = f' [{default}]' if default else ''
    answer = input(f'{prompt}{suffix}: ').strip()
    return answer or default


def update_name(lines):
    current = get_value(lines, 'APP_NAME', 'OSG Nodo Control')
    name = question('Nombre visible de la aplicación', current)
    if not 1 <= len(name) <= 60 or any(ord(c) < 32 for c in name):
        raise ValueError('El nombre debe tener entre 1 y 60 caracteres imprimibles')
    set_value(lines, 'APP_NAME', name)
    return lines


def confirm(prompt):
    return input(f'{prompt} [s/N]: ').strip().lower() in ('s', 'si', 'sí')


class NoCredentialRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        # Never forward the bearer key to a redirected destination.
        return None


class ModelCatalogUnavailable(ValueError):
    pass


def verify_model(provider, model, key, endpoint):
    request = Request(endpoint.rstrip('/') + '/models', headers={
        'Authorization': 'Bearer ' + key,
        'Accept': 'application/json',
        'User-Agent': 'OSG-Nodo-Control-Setup',
    })
    try:
        with build_opener(NoCredentialRedirect()).open(request, timeout=10) as response:
            raw = response.read(1024 * 1024 + 1)
    except HTTPError as exc:
        if provider == 'compatible' and exc.code in (404, 405):
            raise ModelCatalogUnavailable('El proveedor no expone un catálogo GET /models.') from None
        if exc.code in (401, 403):
            raise ValueError('El proveedor rechazó la clave o sus permisos. No se guardó ningún cambio.') from None
        raise ValueError(f'No se pudo verificar el modelo (HTTP {exc.code}). No se guardó ningún cambio.') from None
    except (URLError, OSError, ValueError):
        raise ValueError('No se pudo conectar al proveedor de forma segura. Revisa Internet, TLS y el endpoint. No se guardó ningún cambio.') from None
    if len(raw) > 1024 * 1024:
        raise ValueError('El catálogo del proveedor supera el límite admitido. No se guardó ningún cambio.')
    try:
        catalog = json.loads(raw)
    except (ValueError, UnicodeError):
        raise ValueError('El proveedor no devolvió un catálogo JSON válido. No se guardó ningún cambio.') from None
    models = catalog.get('data') if isinstance(catalog, dict) else None
    if not isinstance(models, list):
        raise ValueError('El catálogo del proveedor tiene un formato inesperado. No se guardó ningún cambio.')
    if not any(isinstance(item, dict) and item.get('id') == model and item.get('active', True) is not False for item in models):
        raise ValueError('El modelo no aparece disponible en el catálogo de esta cuenta. Revisa su identificador exacto. No se guardó ningún cambio.')


def update_api(lines):
    # Work on a copy: cancellation and validation failures must preserve input.
    updated = lines.copy()
    provider = question('IA (groq/openai/compatible/ninguna)', get_value(lines, 'REPORT_AI_PROVIDER', 'ninguna')).lower()
    if provider not in ('groq', 'openai', 'compatible', 'ninguna'):
        raise ValueError('Proveedor no admitido')
    if provider == 'ninguna':
        print('Se desactivará el asistente conversacional. Las credenciales y la opción de IA de los PDF se conservarán.')
        if not confirm('¿Guardar esta desactivación?'):
            return None
        set_value(updated, 'NODO_ASSISTANT_ENABLED', 'false')
        return updated
    model_default = {'groq': 'openai/gpt-oss-20b', 'openai': 'gpt-4.1-mini', 'compatible': ''}[provider]
    if provider == get_value(lines, 'REPORT_AI_PROVIDER'):
        model_default = get_value(lines, 'REPORT_AI_MODEL', model_default)
    model = question('Modelo', model_default)
    if not model or len(model) > 120 or not model.isprintable() or any(c.isspace() for c in model):
        raise ValueError('Modelo inválido')
    endpoint = {'groq': 'https://api.groq.com/openai/v1', 'openai': 'https://api.openai.com/v1'}.get(provider)
    if provider == 'compatible':
        endpoint = question('Endpoint compatible HTTPS (ej. https://api.ejemplo.com/v1)', get_value(lines, 'REPORT_AI_COMPATIBLE_URL'))
        parsed = urlsplit(endpoint)
        host = parsed.hostname or ''
        try:
            ipaddress.ip_address(host)
            numeric_ip = True
        except ValueError:
            numeric_ip = False
        if (parsed.scheme != 'https' or not host or '.' not in host or numeric_ip or
            host.endswith(('.local', '.internal')) or parsed.username or parsed.password or
            parsed.query or parsed.fragment or len(endpoint) > 250 or
            not endpoint.isascii() or any(c.isspace() or ord(c) < 32 or ord(c) == 127 for c in endpoint)):
            raise ValueError('Endpoint HTTPS público inválido')
        set_value(updated, 'REPORT_AI_COMPATIBLE_URL', endpoint)
    try:
        with warnings.catch_warnings():
            warnings.simplefilter('error', getpass.GetPassWarning)
            key = getpass.getpass('Clave de API (no se mostrará): ')
    except getpass.GetPassWarning:
        raise ValueError('Se requiere una terminal que permita ocultar la clave. No se guardó ningún cambio.') from None
    if not key or len(key) > 4096 or any(ord(c) < 33 or ord(c) > 126 for c in key):
        raise ValueError('Clave vacía, demasiado larga o con espacios/caracteres no admitidos')
    print(f'Proveedor: {provider}\nModelo: {model}\nEndpoint: {endpoint}\nClave API: [oculta]')
    print('Se habilitará el asistente conversacional. La preferencia de conclusiones IA de los PDF no cambiará.')
    if not confirm('¿Verificar el modelo con este proveedor y guardar la configuración?'):
        return None
    try:
        verify_model(provider, model, key, endpoint)
        print('Modelo encontrado en el catálogo. Esto no comprueba generación, cuotas ni facturación.')
    except ModelCatalogUnavailable:
        print('Este proveedor compatible no permite comprobar el modelo con GET /models.')
        if input('Para guardar SIN verificar el modelo, escribe GUARDAR_SIN_VERIFICAR: ').strip() != 'GUARDAR_SIN_VERIFICAR':
            return None
    key_name = {'groq': 'GROQ_API_KEY', 'openai': 'OPENAI_API_KEY', 'compatible': 'REPORT_AI_COMPATIBLE_KEY'}[provider]
    set_value(updated, key_name, key)
    set_value(updated, 'REPORT_AI_PROVIDER', provider)
    set_value(updated, 'REPORT_AI_MODEL', model)
    set_value(updated, 'NODO_ASSISTANT_ENABLED', 'true')
    return updated


def update_network(app_lines, compose_lines):
    print('Configura desde dónde se podrá acceder al servidor OSG Nodo Control (Laravel).')
    print('1) Solo desde este mismo servidor: panel en esta computadora; los agentes de otras computadoras no podrán conectarse.')
    print('2) Desde otras computadoras de tu red (LAN): permite conectar sus agentes Windows y abrir el panel desde esos equipos.')
    default = '1' if get_value(compose_lines, 'OSG_BIND_IP', '127.0.0.1') == '127.0.0.1' else '2'
    choice = question('Elige una opción: 1 o 2', default).lower()
    # Keep the old answers usable for existing operators and scripts.
    mode = {'1': 'local', '2': 'lan', 'local': 'local', 'lan': 'lan'}.get(choice)
    if mode is None:
        raise ValueError('Elige 1 para acceso solo desde este servidor, o 2 para conectar otras computadoras de la red.')
    port = question('Puerto HTTP del panel y de la conexión de los agentes', get_value(compose_lines, 'OSG_PORT', '8088'))
    if not port.isdecimal() or not 1024 <= int(port) <= 65535:
        raise ValueError('Puerto inválido (1024-65535)')
    if mode == 'lan':
        print('ATENCIÓN: LAN HTTP SIN CIFRAR. Solo para laboratorio con red privada y control de firewall.')
        if question('Escribe EXACTAMENTE ACEPTO_HTTP_LAN para confirmar') != 'ACEPTO_HTTP_LAN':
            raise ValueError('No se habilitó HTTP en LAN')
        host = question('IP o nombre del equipo donde instalaste Laravel, accesible desde los Windows (ej. 192.168.1.10)')
        if not host or len(host) > 180 or not re.fullmatch(r'[A-Za-z0-9.-]+', host):
            raise ValueError('Host inválido')
        set_value(compose_lines, 'OSG_BIND_IP', '0.0.0.0')
        set_value(app_lines, 'APP_URL', f'http://{host}:{port}')
    else:
        set_value(compose_lines, 'OSG_BIND_IP', '127.0.0.1')
        set_value(app_lines, 'APP_URL', f'http://127.0.0.1:{port}')
    set_value(compose_lines, 'OSG_PORT', port)
    set_value(app_lines, 'SESSION_SECURE_COOKIE', 'false')
    return app_lines, compose_lines


def update_tls(app_lines, compose_lines):
    print('Requiere certificado TLS válido con fullchain.pem y privkey.pem en secrets/tls/.')
    host = question('Dominio o IP incluido en el certificado')
    if not host or not re.fullmatch(r'[A-Za-z0-9.-]{1,180}', host):
        raise ValueError('Host inválido')
    port = question('Puerto TLS', get_value(compose_lines, 'OSG_TLS_PORT', '8443'))
    if not port.isdecimal() or not 1024 <= int(port) <= 65535:
        raise ValueError('Puerto TLS inválido')
    cert_dir = RUNTIME / 'tls'
    if not (cert_dir / 'fullchain.pem').is_file() or not (cert_dir / 'privkey.pem').is_file():
        raise ValueError('Faltan secrets/tls/fullchain.pem o privkey.pem')
    set_value(compose_lines, 'OSG_TLS_PORT', port)
    # HTTP should remain on loopback only if TLS is the public entrance.
    set_value(compose_lines, 'OSG_BIND_IP', '127.0.0.1')
    set_value(app_lines, 'APP_URL', f'https://{host}:{port}')
    set_value(app_lines, 'SESSION_SECURE_COOKIE', 'true')
    print('Usa: docker compose -f compose.yaml -f compose.https.yaml up -d')
    return app_lines, compose_lines


def run(mode):
    if os.name != 'posix':
        raise RuntimeError('Ejecuta este asistente en Linux o Debian WSL2, no sobre NTFS.')
    if mode == 'init':
        if APP_ENV.exists() or COMPOSE_ENV.exists():
            raise RuntimeError('La configuración ya existe. Utiliza name, api o network; nunca regeneres APP_KEY.')
        lines = read_lines(ROOT / '.env.example')
        set_value(lines, 'APP_NAME', 'OSG Nodo Control')
        set_value(lines, 'APP_ENV', 'production')
        set_value(lines, 'APP_DEBUG', 'false')
        set_value(lines, 'APP_KEY', 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode())
        set_value(lines, 'DB_DATABASE', '/data/sqlite/database.sqlite')
        set_value(lines, 'QUEUE_CONNECTION', 'sync')  # Report jobs explicitly use database+reports.
        set_value(lines, 'NODO_ASSISTANT_ENABLED', 'false')
        set_value(lines, 'LOG_CHANNEL', 'stderr')
        set_value(lines, 'APP_URL', 'http://127.0.0.1:8088')
        set_value(lines, 'SESSION_SECURE_COOKIE', 'false')
        compose = ['OSG_BIND_IP=127.0.0.1', 'OSG_PORT=8088', 'COMPOSE_PROJECT_NAME=osg-nodo-control']
        update_name(lines)
        persist(APP_ENV, lines, 0o644)  # bind-mounted file readable by UID 33; parent secrets/ is mode 0700
        persist(COMPOSE_ENV, compose, 0o600)
        print('Configuración creada. APP_KEY se conserva entre actualizaciones.')
        print('API opcional: python3 scripts/docker/configure.py api')
        print('LAN de laboratorio: python3 scripts/docker/configure.py network')
        return
    if not APP_ENV.exists() or not COMPOSE_ENV.exists():
        raise RuntimeError('Ejecuta primero: python3 scripts/docker/configure.py init')
    lines = read_lines(APP_ENV)
    compose = read_lines(COMPOSE_ENV)
    if mode == 'name':
        update_name(lines)
    elif mode == 'api':
        lines = update_api(lines)
        if lines is None:
            print('Configuración cancelada. No se guardó ningún cambio.')
            return
    elif mode == 'network':
        lines, compose = update_network(lines, compose)
    elif mode == 'tls':
        lines, compose = update_tls(lines, compose)
    else:
        raise ValueError('Uso: configure.py init|name|api|network|tls')
    persist(APP_ENV, lines, 0o644)
    if mode != 'api':
        persist(COMPOSE_ENV, compose, 0o600)
    print('Configuración actualizada. Ejecuta: docker compose up -d --force-recreate')


if __name__ == '__main__':
    try:
        run(sys.argv[1] if len(sys.argv) == 2 else '')
    except (ValueError, RuntimeError, OSError, EOFError, KeyboardInterrupt) as exc:
        print(f'ERROR: {exc}', file=sys.stderr)
        sys.exit(1)
