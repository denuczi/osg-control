"""Agente Windows de Nodo Control.

No ejecuta comandos arbitrarios ni inicia conexiones entrantes. Requiere
registro mediante código de un solo uso emitido por el administrador.
"""
from __future__ import annotations

import argparse
import ipaddress
import json
import random
import re
import threading
import logging
import os
import platform
import subprocess
import sys
import time
import uuid
from datetime import datetime, timezone
from spool import LocalSpool
from inventory import NetworkMeter, collect_inventory, inserted_media_info
from network_usage import read_counters, eligible_interface_names
from process_inventory import capture_processes
from diagnostics_v6 import collect_diagnostics, system_events
from pathlib import Path
from urllib import error, request
from urllib.parse import urlparse

import psutil

LOG = logging.getLogger("nodo-agent")
CONFIG_DIR = (Path(os.environ.get("PROGRAMDATA", r"C:\ProgramData")) / "NodoControl" if os.name == "nt" else Path.home() / ".local" / "state" / "NodoControl")
CONFIG_FILE = CONFIG_DIR / "agent.json"
SPOOL_FILE = CONFIG_DIR / "telemetry.sqlite"
DEFAULT_SETTINGS = {
    "metrics_interval_seconds": 60,
    "heartbeat_interval_seconds": 30,
    "command_poll_seconds": 10,
}
SETTING_RANGES = {"metrics_interval_seconds": (5, 3600),
                  "heartbeat_interval_seconds": (20, 300),
                  "command_poll_seconds": (5, 60)}
# The server may request a 5-second metrics interval; process and inventory scans stay slower.
INVENTORY_INTERVAL_SECONDS = max(300, int(os.environ.get("NODO_INVENTORY_INTERVAL_SECONDS", "600")))
PROCESS_INTERVAL_SECONDS = 60
PROTECTED_PROCESSES = frozenset({
    "system", "registry", "idle", "smss.exe", "csrss.exe", "wininit.exe",
    "winlogon.exe", "services.exe", "lsass.exe", "svchost.exe",
    "explorer.exe", "dwm.exe", "spoolsv.exe", "securityhealthservice.exe",
    "nodo_agent.exe", "nodocontrolagent.exe", "nodocontrolconfig.exe", "python.exe", "pythonw.exe",
})


def save_config(data: dict) -> None:
    CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    if os.name != "nt":
        CONFIG_DIR.chmod(0o700)
    tmp = CONFIG_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(data, indent=2), encoding="utf-8")
    try:
        tmp.chmod(0o600)
    except OSError:
        pass
    tmp.replace(CONFIG_FILE)
    if os.name != "nt":
        CONFIG_FILE.chmod(0o600)


def load_config() -> dict:
    try:
        # Read directly: Path.exists() may turn an access-denied situation into
        # a false 'not configured' diagnosis on some Windows versions.
        return json.loads(CONFIG_FILE.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise RuntimeError("No hay configuración. Primero registrá el equipo con 'enroll'.") from exc
    except PermissionError as exc:
        raise RuntimeError("Acceso denegado a agent.json: ejecutá con una cuenta autorizada; no vuelvas a registrar el equipo.") from exc


def validate_server(url: str, allow_http: bool) -> str:
    url = url.rstrip("/")
    parsed = urlparse(url)
    if parsed.scheme not in ("http", "https") or not parsed.hostname or parsed.username or parsed.password or parsed.path or parsed.query or parsed.fragment:
        raise ValueError("La URL debe tener formato http(s)://HOST[:PUERTO] sin ruta ni credenciales.")
    if parsed.scheme == "http":
        if not allow_http:
            raise ValueError("HTTP sin cifrar requiere autorización explícita para LAN (--allow-http).")
        try:
            host_ip = ipaddress.ip_address(parsed.hostname)
        except ValueError as exc:
            raise ValueError("Para HTTP LAN utilizá una dirección IP privada explícita.") from exc
        private_ranges = (
            ipaddress.ip_network("10.0.0.0/8"),
            ipaddress.ip_network("172.16.0.0/12"),
            ipaddress.ip_network("192.168.0.0/16"),
            ipaddress.ip_network("127.0.0.0/8"),
            ipaddress.ip_network("fc00::/7"),
            ipaddress.ip_network("::1/128"),
        )
        if not any(host_ip in block for block in private_ranges if block.version == host_ip.version):
            raise ValueError("HTTP LAN requiere IPv4 RFC1918 o IPv6 local (ULA/loopback).")
    return url


class ApiError(RuntimeError):
    """Only report an endpoint and validation *keys*; never log server values."""
    def __init__(self, code: int, retry_after: str | None = None,
                 endpoint: str = "", fields: tuple[str, ...] = ()):
        self.code, self.retry_after, self.endpoint, self.fields = code, retry_after, endpoint, fields
        detail = ", ".join(fields[:8]) if fields else "sin detalle de campos"
        super().__init__(f"HTTP {code} en {endpoint or 'API'} ({detail})")


def call_api(server: str, endpoint: str, method: str = "GET", *, token: str | None = None, payload: dict | None = None) -> dict:
    headers = {"Accept": "application/json", "User-Agent": "NodoAgent/1.1"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    body = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = request.Request(server + endpoint, data=body, headers=headers, method=method)
    try:
        with request.urlopen(req, timeout=10) as response:
            return json.load(response)
    except error.HTTPError as exc:
        fields = ()
        if exc.code == 422:
            # Laravel's validation error keys are safe to expose, values are not.
            # Bounded response reading avoids untrusted server content in logs.
            try:
                doc = json.loads(exc.read(8192))
                errors = doc.get("errors", {}) if isinstance(doc, dict) else {}
                if isinstance(errors, dict):
                    fields = tuple(k for k in list(errors)[:8]
                                   if isinstance(k, str) and re.fullmatch(r"[A-Za-z0-9_.*-]{1,100}", k))
            except (ValueError, OSError, UnicodeError):
                pass
        raise ApiError(exc.code, exc.headers.get("Retry-After"), endpoint, fields) from exc


def snapshot(network_meter: NetworkMeter | None = None, *, include_processes: bool = True) -> dict:
    # En Windows, el volumen raíz se representa como C:\\.
    disk_path = os.environ.get("SystemDrive", "C:") + "\\" if os.name == "nt" else "/"
    processes, total_processes = (
        capture_processes(psutil, _is_protected_process) if include_processes else ([], 0)
    )
    memory = psutil.virtual_memory()
    disk = psutil.disk_usage(disk_path)
    network = network_meter.sample() if network_meter else {"interfaces": []}
    # Rate and accumulated usage must follow the *same* eligible interfaces.
    # Never switch to the highest-traffic NIC on each sample.
    chosen = eligible_interface_names()
    links = [v for v in network['interfaces'] if v['name'] in chosen and
             v['rx_bps'] is not None and v['tx_bps'] is not None]
    rx_rate = sum(v['rx_bps'] for v in links) if links else None
    tx_rate = sum(v['tx_bps'] for v in links) if links else None
    return {
        "hostname": platform.node()[:120],
        "platform": f"{platform.system()} {platform.release()}"[:120],
        "cpu_percent": psutil.cpu_percent(interval=1),
        "memory_percent": memory.percent,
        "disk_percent": disk.percent,
        "memory_total_bytes": memory.total,
        "memory_used_bytes": memory.used,
        "memory_available_bytes": memory.available,
        "disk_total_bytes": disk.total,
        "disk_used_bytes": disk.used,
        "disk_free_bytes": disk.free,
        "network_rx_bps": rx_rate,
        "network_tx_bps": tx_rate,
        **({"processes": processes, "processes_total_count": total_processes} if include_processes else {}),
    }


def _is_protected_process(pid: int, name: str) -> bool:
    """Defend critical system and agent processes, independent of preflight."""
    return pid in (0, 4, os.getpid()) or name.casefold() in PROTECTED_PROCESSES


def terminate_process(params: dict) -> str:
    pid = params.get("pid")
    name = params.get("process_name")
    started_at = params.get("process_started_at")
    if type(pid) is not int or pid < 1 or not isinstance(name, str) or type(started_at) not in (float, int):
        raise ValueError("Parámetros de proceso inválidos.")
    if _is_protected_process(pid, name):
        raise PermissionError("No se permite finalizar un proceso protegido.")
    proc = psutil.Process(pid)
    if proc.name().casefold() != name.casefold() or abs(proc.create_time() - started_at) > 0.01:
        raise ValueError("El proceso ya no coincide con el PID, el nombre y la hora de inicio originales.")
    # The administrator has already authorized the command. The operating
    # system makes the authoritative access decision at terminate() time.
    proc.terminate()
    try:
        proc.wait(timeout=5)
    except psutil.TimeoutExpired:
        raise TimeoutError("Se solicitó el cierre, pero el proceso no terminó en cinco segundos.")
    return f"Proceso {name} (PID {pid}) finalizado."


def execute_command(command: dict) -> tuple[str, str]:
    action = command.get("action")
    params = command.get("parameters") or {}
    if not isinstance(params, dict):
        return "failed", "Parámetros inválidos."
    try:
        if action == "terminate_process":
            return "completed", terminate_process(params)
        if action == "show_message":
            return "failed", "Windows requiere el canal de notificación asíncrono para confirmar el botón Cerrar."
        if action in ("shutdown", "restart"):
            if platform.system() != "Windows":
                raise OSError("El apagado remoto de este agente solo está implementado para Windows.")
            mode = "/s" if action == "shutdown" else "/r"
            subprocess.run(["shutdown", mode, "/t", "30"], check=True, timeout=10)
            return "completed", ("Apagado" if action == "shutdown" else "Reinicio") + " programado dentro de 30 segundos."
        return "failed", "Acción no autorizada."
    except (psutil.Error, OSError, ValueError, PermissionError, subprocess.CalledProcessError, subprocess.TimeoutExpired, TimeoutError, RuntimeError, TypeError) as exc:
        return "failed", f"No se pudo ejecutar la orden: {type(exc).__name__}: {str(exc)[:300]}"


def enroll(args: argparse.Namespace) -> None:
    server = validate_server(args.server, args.allow_http)
    existing = load_config() if CONFIG_FILE.exists() else {}
    if existing.get("token"):
        raise RuntimeError("El equipo ya tiene credenciales. Contactá al administrador para reconfigurarlo.")
    device_uuid = existing.get("uuid") or str(uuid.uuid4())
    response = call_api(server, "/api/v1/agents/enroll", "POST", payload={
        "code": args.code, "uuid": device_uuid,
        "hostname": platform.node()[:120], "platform": f"{platform.system()} {platform.release()}"[:120],
    })
    save_config({"server": server, "uuid": device_uuid, "token": response["token"], "allow_http": bool(args.allow_http and urlparse(server).scheme == "http")})
    print("Registro completado. UUID:", device_uuid)
    print("Credenciales almacenadas en:", CONFIG_FILE)


def update_settings(config: dict, response: dict) -> dict:
    current = dict(DEFAULT_SETTINGS)
    for key, val in config.get("settings", {}).items():
        if key in SETTING_RANGES and type(val) is int:
            lo, hi = SETTING_RANGES[key]
            current[key] = min(hi, max(lo, val))
    incoming = response.get("settings", {})
    if isinstance(incoming, dict):
        for key, (lower, upper) in SETTING_RANGES.items():
            value = incoming.get(key)
            if type(value) is int and lower <= value <= upper:
                current[key] = value
    if config.get("settings") != current:
        config["settings"] = current
        save_config(config)
    return current


def metrics_sync_interval(settings: dict) -> int:
    """Upload new metric samples promptly without increasing other network polling.

    Normal intervals retain the 15-second cadence. A 5-second capture is
    delivered every 5 seconds when the server is reachable. Connection failures
    and 429 responses still use the existing backoff in run_agent().
    """
    requested = settings.get("metrics_interval_seconds", DEFAULT_SETTINGS["metrics_interval_seconds"])
    if type(requested) is not int:
        requested = DEFAULT_SETTINGS["metrics_interval_seconds"]
    return max(5, min(15, requested))


def sync_samples(spool: LocalSpool, server: str, token: str) -> dict | None:
    """Bounded bisection isolates invalid records without blocking valid samples.

    A persistently invalid singleton stays on disk and is deferred for 10 min.
    Re-installing the agent is never necessary; `retry-pending` clears deferrals.
    """
    batch = spool.pending_samples()
    if not batch:
        return None
    budget = [16]
    latest = None
    rejected = []

    def deliver(items: list[dict]) -> None:
        nonlocal latest
        if not items or budget[0] <= 0:
            return
        budget[0] -= 1
        try:
            response = call_api(server, "/api/v1/agents/metrics/batch", "POST", token=token,
                                payload={"samples": items})
        except ApiError as exc:
            if exc.code in (413, 422) and len(items) > 1:
                middle = len(items) // 2
                deliver(items[:middle])
                deliver(items[middle:])
                return
            if exc.code in (413, 422) and len(items) == 1:
                spool.defer_sample(items[0]["sample_uuid"], seconds=600)
                rejected.append(exc)
                return
            raise
        expected = {sample["sample_uuid"] for sample in items}
        accepted = response.get("accepted_ids", [])
        if (not isinstance(accepted, list) or not accepted
                or len(accepted) != len(set(accepted)) or not set(accepted).issubset(expected)):
            raise RuntimeError("Confirmación incompleta de métricas; se conserva la cola local")
        spool.acknowledge_samples(accepted)
        latest = response

    deliver(batch)
    if rejected:
        LOG.error("%d muestra(s) inválida(s) diferidas sin borrar datos. %s",
                  len(rejected), rejected[0])
        # An explicit non-success status is required for run --once.
        raise rejected[0]
    return latest


def sync_usage(spool: LocalSpool, server: str, token: str) -> dict | None:
    """Send durable snapshots; isolate rejected buckets without blocking valid ones."""
    pending = spool.pending_usage()
    if not pending:
        return None

    def deliver(items: list[dict]) -> dict | None:
        try:
            response = call_api(server, '/api/v1/agents/network-usage/batch', 'POST', token=token,
                                payload={'buckets': items})
        except ApiError as exc:
            if exc.code not in (413, 422):
                raise
            if len(items) == 1:
                spool.defer_usage(items[0]['bucket_uuid'])
                LOG.error('Hora de consumo inválida diferida sin borrar datos. %s', exc)
                return None
            halfway = len(items) // 2
            deliver(items[:halfway]); deliver(items[halfway:])
            return None
        accepted = response.get('accepted', [])
        expected = {(b['bucket_uuid'], b['revision']) for b in items}
        actual = {(b['bucket_uuid'], b['revision']) for b in accepted
                  if isinstance(b, dict) and 'bucket_uuid' in b and 'revision' in b}
        if actual != expected or len(accepted) != len(expected):
            raise RuntimeError('Confirmación de consumo inválida: se conserva la cola local')
        spool.acknowledge_usage(accepted)
        return response

    return deliver(pending)


def sync_results(spool: LocalSpool, server: str, token: str):
    for pending in spool.pending_results():
        call_api(server, f"/api/v1/agents/commands/{pending['id']}/result", "POST",
                 token=token, payload=pending["result"])
        spool.acknowledge_result(pending["id"])


def _capture_inventory(spool: LocalSpool) -> None:
    inventory = collect_inventory()
    spool.save_inventory(inventory)


def _inventory_worker(spool: LocalSpool, stop_event: threading.Event) -> None:
    # WMI/PowerShell pueden bloquear varios segundos en equipos lentos.
    # No bloquear heartbeat, ejecución de órdenes ni captura de métricas.
    while not stop_event.is_set():
        try:
            _capture_inventory(spool)
        except Exception as exc:
            LOG.warning("Inventario no disponible (%s): %s", type(exc).__name__, str(exc)[:180])
        stop_event.wait(INVENTORY_INTERVAL_SECONDS)


def _media_worker(spool: LocalSpool, stop_event: threading.Event, refresh_event: threading.Event | None = None) -> None:
    # No full Get-Partition/physical disk scan on this short cycle.
    while not stop_event.is_set():
        try:
            observed = datetime.now(timezone.utc).isoformat(timespec="milliseconds")
            media = inserted_media_info()
            if media is not None:
                spool.record_media_state(media, observed)
        except Exception as exc:
            LOG.warning("Sondeo de medios no disponible (%s)", type(exc).__name__)
        if refresh_event is None:
            stop_event.wait(60)
        else:
            # Passive Windows notification wakes a one-off metadata query.
            # Allow the mount manager time to expose the volume first.
            triggered = refresh_event.wait(60)
            refresh_event.clear()
            if triggered:
                stop_event.wait(2)


def _diagnostics_worker(spool: LocalSpool, stop_event: threading.Event) -> None:
    """Slow, isolated probes; never delay remote commands or telemetry.

    A sensor can be unsupported. The coordinator preserves that result as a
    status without interpreting missing values as healthy readings.
    """
    next_diagnostic = 0.0
    next_events = 0.0
    while not stop_event.is_set():
        now = time.monotonic()
        if now >= next_diagnostic:
            next_diagnostic = now + 6 * 3600
            try:
                spool.save_diagnostics(collect_diagnostics())
            except Exception as exc:
                LOG.warning('Diagnóstico de hardware no disponible (%s)', type(exc).__name__)
        if now >= next_events:
            next_events = now + 300
            try:
                collection = system_events(spool.system_event_bookmarks())
                if collection is not None:
                    spool.record_system_events(collection.events, advances=collection.advances,
                                               resets=collection.resets)
                    if collection.saturated:
                        # Drain bounded backlog faster without a busy polling loop.
                        next_events = time.monotonic() + 60
            except Exception as exc:
                LOG.warning('Eventos del sistema no disponibles (%s)', type(exc).__name__)
        stop_event.wait(min(60, max(1, min(next_diagnostic, next_events) - time.monotonic())))


def _sync_diagnostics(spool: LocalSpool, server: str, token: str) -> dict | None:
    snapshot = spool.pending_diagnostics()
    if snapshot is None:
        return None
    result = call_api(server, '/api/v1/agents/diagnostics', 'POST', token=token, payload=snapshot)
    if result.get('ok') is not True or result.get('captured_at') != snapshot['captured_at']:
        raise RuntimeError('Confirmación de diagnóstico inválida; datos conservados')
    spool.acknowledge_diagnostics(snapshot['captured_at'])
    return result


def _sync_system_events(spool: LocalSpool, server: str, token: str) -> dict | None:
    """Bisect rejected batches; preserve invalid singleton records for inspection.

    Server acceptance must exactly match a sent subset. Only acknowledged IDs
    leave the durable queue. A bounded request budget prevents long catch-up.
    """
    batch = spool.pending_system_events()
    if not batch:
        return None
    budget = [12]
    rejected: list[ApiError] = []
    latest = None

    def deliver(items: list[dict]) -> None:
        nonlocal latest
        if not items or budget[0] == 0:
            return
        budget[0] -= 1
        endpoint = '/api/v1/agents/system-events/batch'
        try:
            result = call_api(server, endpoint, 'POST', token=token, payload={'events': items})
        except ApiError as exc:
            if exc.code not in (413, 422):
                raise
            if len(items) > 1:
                mid = len(items) // 2
                deliver(items[:mid])
                deliver(items[mid:])
                return
            spool.defer_system_event(items[0]['event_uuid'])
            rejected.append(exc)
            return
        expected = {event['event_uuid'] for event in items}
        actual = result.get('accepted_ids', [])
        if (not isinstance(actual, list) or len(actual) != len(expected) or
                len(actual) != len(set(actual)) or set(actual) != expected):
            raise RuntimeError('Confirmación incompleta de eventos Windows; datos conservados')
        spool.acknowledge_system_events(actual)
        latest = result

    deliver(batch)
    if rejected:
        LOG.error('%d evento(s) Windows rechazados diferidos sin borrar datos. %s', len(rejected), rejected[0])
        raise rejected[0]
    return latest


def _diagnostic_sync_worker(spool: LocalSpool, server: str, token: str,
                            stop_event: threading.Event) -> None:
    """Slow diagnostic uploads never occupy the command/heartbeat coordinator."""
    due = {'diagnostics': 0.0, 'system_events': 0.0}
    actions = {'diagnostics': _sync_diagnostics, 'system_events': _sync_system_events}
    backoff = 0
    while not stop_event.is_set():
        now = time.monotonic()
        for channel, action in actions.items():
            if stop_event.is_set() or now < due[channel]:
                continue
            due[channel] = time.monotonic() + (180 if channel == 'diagnostics' else 60)
            try:
                action(spool, server, token)
                backoff = 0
            except ApiError as exc:
                LOG.warning('Sincronización %s: %s', channel, exc)
                if exc.code in (401, 403):
                    backoff = 300
                elif exc.code == 429:
                    backoff = max(60, min(600, int(exc.retry_after))) if (
                        exc.retry_after and exc.retry_after.isdecimal()) else 60
                elif exc.code >= 500:
                    backoff = min(120, max(10, backoff * 2))
                else:
                    backoff = 60
                due[channel] = time.monotonic() + backoff
            except (OSError, RuntimeError, ValueError, KeyError) as exc:
                LOG.warning('Sincronización %s no disponible (%s)', channel, type(exc).__name__)
                due[channel] = time.monotonic() + 60
        stop_event.wait(max(1, min(5, min(due.values()) - time.monotonic())))


def spool_path_for(config: dict) -> Path:
    """Isolate each server/device identity; never replay the old server outbox."""
    name = config.get("spool_file")
    if name is None:
        return SPOOL_FILE  # v5.1 backward compatible
    if not isinstance(name, str) or not re.fullmatch(
            r"telemetry-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.sqlite", name):
        raise ValueError("Nombre de base de datos local no válido.")
    return CONFIG_DIR / name


def run_agent(stop_event: threading.Event, *, allow_http: bool = False, once: bool = False) -> None:
    config = load_config()
    server = validate_server(config["server"], allow_http or config.get("allow_http", False))
    token = config["token"]
    spool = LocalSpool(spool_path_for(config))
    settings = update_settings(config, {})
    LOG.info("Agente activo: %s", config["uuid"])
    now = time.monotonic()
    channels = ("heartbeat", "commands", "results", "metrics_sync", "usage_sync", "events", "inventory")
    if once:
        channels += ("diagnostics", "system_events")
    due = {key: now for key in channels}
    due["metrics"] = now
    due["usage_capture"] = now
    failures = 0
    network_meter = NetworkMeter()
    next_connection = now
    last_process_capture = 0.0
    failed_once = []
    if once:
        try:
            _capture_inventory(spool)
        except Exception as exc:
            LOG.warning("Inventario de diagnóstico no disponible: %s", type(exc).__name__)
    elif os.environ.get("NODO_INVENTORY_ENABLED", "1") == "1":
        media_refresh = threading.Event()
        threading.Thread(target=_media_worker, args=(spool, stop_event, media_refresh),
                         name="media-poll", daemon=True).start()
        if os.name == 'nt':
            from windows_media_events import watch_media_events
            threading.Thread(target=watch_media_events, args=(spool, stop_event, media_refresh),
                             name="media-events", daemon=True).start()
        threading.Thread(target=_inventory_worker, args=(spool, stop_event),
                         name="NodoInventory", daemon=True).start()
    if not once:
        if os.environ.get("NODO_DIAGNOSTICS_ENABLED", "1") == "1":
            threading.Thread(target=_diagnostics_worker, args=(spool, stop_event),
                             name="NodoDiagnostics", daemon=True).start()
        threading.Thread(target=_diagnostic_sync_worker, args=(spool, server, token, stop_event),
                         name="NodoDiagnosticSync", daemon=True).start()

    legacy = config.pop("pending_result", None)
    if legacy:
        if spool.begin_command(legacy["id"]):
            spool.finish_command(legacy["id"], legacy["result"]["status"], legacy["result"]["message"])
        save_config(config)

    cycle_count = 0
    while not stop_event.is_set():
        now = time.monotonic()
        if now >= due["metrics"]:
            try:
                include_processes = (now - last_process_capture >= PROCESS_INTERVAL_SECONDS)
                spool.add_sample(snapshot(network_meter, include_processes=include_processes))
                if include_processes:
                    last_process_capture = now
            except (OSError, ValueError) as exc:
                LOG.error("No fue posible guardar una muestra local: %s", type(exc).__name__)
            finally:
                due["metrics"] = now + settings["metrics_interval_seconds"]
        # Separate, compact durable network accounting; no dependency on Laravel.
        if now >= due["usage_capture"]:
            try:
                boot_id, counters = read_counters()
                spool.record_usage(boot_id, counters)
            except (OSError, ValueError, OverflowError) as exc:
                LOG.error('No fue posible guardar consumo de red: %s', type(exc).__name__)
            finally:
                due["usage_capture"] = now + 30
        if now >= next_connection:
            jobs = (
                ("heartbeat", "/api/v1/agents/heartbeat", lambda: call_api(
                    server, "/api/v1/agents/heartbeat", "POST", token=token,
                    payload={"hostname": platform.node()[:120],
                             "platform": f"{platform.system()} {platform.release()}"[:120]})),
                ("commands", "/api/v1/agents/commands/next", lambda: _poll_commands(spool, server, token)),
                ("results", "/api/v1/agents/commands/*/result", lambda: sync_results(spool, server, token)),
                ("metrics_sync", "/api/v1/agents/metrics/batch", lambda: sync_samples(spool, server, token)),
                ("usage_sync", "/api/v1/agents/network-usage/batch", lambda: sync_usage(spool, server, token)),
                ("events", "/api/v1/agents/media-events/batch", lambda: _sync_events(spool, server, token)),
                ("inventory", "/api/v1/agents/inventory", lambda: _sync_inventory(spool, server, token)),
            )
            if once:
                jobs += (
                    ("diagnostics", "/api/v1/agents/diagnostics", lambda: _sync_diagnostics(spool, server, token)),
                    ("system_events", "/api/v1/agents/system-events/batch", lambda: _sync_system_events(spool, server, token)),
                )
            network_failure = None
            for channel, endpoint, work in jobs:
                if stop_event.is_set():
                    break
                if now < due[channel]:
                    continue
                default_delay = {
                    "heartbeat": settings["heartbeat_interval_seconds"],
                    "commands": settings["command_poll_seconds"],
                    "metrics_sync": metrics_sync_interval(settings),
                    "diagnostics": 180,
                    "system_events": 60,
                }.get(channel, 15)
                due[channel] = now + default_delay
                try:
                    response = work()
                    if response:
                        previous_interval = settings["metrics_interval_seconds"]
                        settings = update_settings(config, response)
                        # Do not wait for the former 60-second sampling cycle if
                        # an administrator enables 5-second capture during a demo.
                        if settings["metrics_interval_seconds"] < previous_interval:
                            actual_now = time.monotonic()
                            due["metrics"] = min(
                                due["metrics"], actual_now + settings["metrics_interval_seconds"])
                            due["metrics_sync"] = min(
                                due["metrics_sync"], actual_now + metrics_sync_interval(settings))
                except ApiError as exc:
                    failed_once.append(str(exc)) if once else None
                    LOG.error("Canal %s: %s", channel, exc)
                    if exc.code in (401, 403, 429) or (exc.code >= 500 and exc.code != 507):
                        network_failure = exc
                        break
                    # Invalid inventory must never stop metrics or result reporting.
                    due[channel] = now + (120 if exc.code == 507 else 60)
                except (OSError, RuntimeError, ValueError, KeyError, json.JSONDecodeError) as exc:
                    failed_once.append(f"{endpoint}: {type(exc).__name__}") if once else None
                    LOG.warning("Canal %s no disponible (%s)", channel, type(exc).__name__)
                    if isinstance(exc, (OSError, TimeoutError)):
                        network_failure = exc
                        break
                    due[channel] = now + 60
            if network_failure is not None:
                failures += 1
                delay = min(30, 2 ** min(failures, 5)) + random.uniform(0, 2)
                if isinstance(network_failure, ApiError):
                    if network_failure.code == 429 and network_failure.retry_after and network_failure.retry_after.isdecimal():
                        delay = max(delay, min(600, int(network_failure.retry_after)))
                    if network_failure.code in (401, 403):
                        delay = max(delay, 300)
                next_connection = now + delay
                # Schedule channels to resume promptly once connectivity is back.
                for channel in channels:
                    due[channel] = min(due[channel], next_connection)
                LOG.warning("Sin conexión; %d muestras pendientes. Reintento en %.0f s (%s)",
                            spool.sample_count(), delay, type(network_failure).__name__)
            else:
                failures = 0
                next_connection = now
        cycle_count += 1
        if cycle_count % 300 == 0:
            try:
                spool.purge_acked_commands(keep_days=90)
            except OSError as exc:
                LOG.warning("No fue posible depurar recibos: %s", type(exc).__name__)
        if once:
            if failed_once:
                raise RuntimeError("Diagnóstico con fallos: " + "; ".join(failed_once[:4]))
            return
        stop_event.wait(1)


def _sync_inventory(spool: LocalSpool, server: str, token: str) -> dict | None:
    data = spool.pending_inventory()
    if not data:
        return None
    response = call_api(server, "/api/v1/agents/inventory", "POST", token=token, payload=data)
    spool.acknowledge_inventory(data["captured_at"])
    return response


def _sync_events(spool: LocalSpool, server: str, token: str) -> dict | None:
    events = spool.pending_media_events(ready_only=True)
    if not events:
        return None
    budget = [12]
    rejected = []
    latest = None

    def deliver(items: list[dict]) -> None:
        nonlocal latest
        if not items or budget[0] <= 0:
            return
        budget[0] -= 1
        endpoint = "/api/v1/agents/media-events/batch"
        try:
            result = call_api(server, endpoint, "POST", token=token, payload={"events": items})
        except ApiError as exc:
            if exc.code in (413, 422) and len(items) > 1:
                half = len(items) // 2
                deliver(items[:half]); deliver(items[half:])
                return
            if exc.code in (413, 422) and len(items) == 1:
                spool.defer_media_event(items[0]["event_uuid"])
                rejected.append(exc)
                return
            raise
        expected = {event["event_uuid"] for event in items}
        accepted = result.get("accepted_ids", [])
        if not isinstance(accepted, list) or set(accepted) != expected:
            raise RuntimeError("Confirmación inválida de eventos USB")
        spool.acknowledge_media_events(accepted)
        latest = result

    deliver(events)
    if rejected:
        LOG.error("%d evento(s) USB inválidos diferidos sin borrarlos. %s", len(rejected), rejected[0])
        raise rejected[0]
    return latest


def _dispatch_notification(spool: LocalSpool, ident: str, params: dict) -> None:
    """Launches an unprivileged user-session window; ACK only after actual click.

    A crash leaves an unknown receipt, not a fabricated completed event.
    """
    if not isinstance(params, dict):
        spool.finish_command(ident, "failed", "Parámetros de notificación inválidos.")
        return
    try:
        from windows_interactive import launch_message, wait_for_close
        handles = launch_message(params.get("message"))
        spool.mark_awaiting_user(ident)
    except (ValueError, OSError, RuntimeError, ImportError, TypeError) as exc:
        spool.finish_command(ident, "failed", f"No se pudo mostrar el mensaje: {type(exc).__name__}")
        return

    def confirm() -> None:
        try:
            status, reason = wait_for_close(handles)
            spool.finish_command(ident, status, reason)
            LOG.info("Notificación %s: %s", ident, status)
        except Exception as exc:
            LOG.error("Error al registrar confirmación %s (%s)", ident, type(exc).__name__)
            try:
                spool.finish_command(ident, "unknown", "No se pudo verificar el cierre del mensaje.")
            except OSError:
                LOG.exception("No se pudo guardar el estado final de notificación")

    threading.Thread(target=confirm, name="notification-ack", daemon=True).start()


def _poll_commands(spool: LocalSpool, server: str, token: str) -> dict:
    response = call_api(server, "/api/v1/agents/commands/next", "POST", token=token, payload={})
    command = response.get("command")
    if command:
        ident = command.get("id")
        if not isinstance(ident, str) or not ident:
            raise ValueError("Orden recibida sin identificador válido")
        if spool.begin_command(ident):
            if command.get("action") == "show_message":
                _dispatch_notification(spool, ident, command.get("parameters") or {})
            else:
                status, message = execute_command(command)
                spool.finish_command(ident, status, message)
                LOG.info("Orden %s: %s", ident, status)
        # A retransmitted command must only resend a receipt, never reexecute.
        sync_results(spool, server, token)
    return response


def run(args: argparse.Namespace) -> None:
    try:
        run_agent(threading.Event(), allow_http=args.allow_http, once=getattr(args, "once", False))
    except KeyboardInterrupt:
        LOG.info("Agente detenido.")


def show_status() -> None:
    config = load_config()
    spool = LocalSpool(spool_path_for(config))
    print("Equipo:", config.get("uuid", "Desconocido"))
    print("Servidor:", validate_server(config["server"], True))
    print("Muestras pendientes:", spool.sample_count())
    print("Horas de consumo pendientes:", spool.usage_pending_count())
    print("Eventos Windows diferidos:", spool.deferred_system_event_count())
    print("Resultados pendientes:", len(spool.pending_results()))
    print("Intervalos actuales:", json.dumps(config.get("settings", DEFAULT_SETTINGS)))


def main() -> None:
    parser = argparse.ArgumentParser(description="Agente Windows de Nodo Control")
    commands = parser.add_subparsers(dest="mode", required=True)
    enroll_parser = commands.add_parser("enroll", help="Registrar este equipo con código de un solo uso")
    enroll_parser.add_argument("--server", required=True, help="URL del servidor Laravel")
    enroll_parser.add_argument("--code", required=True, help="Código emitido con artisan nodo:enroll-code")
    enroll_parser.add_argument("--allow-http", action="store_true", help="Autorizar HTTP para IP privada explícita en LAN controlada")
    run_parser = commands.add_parser("run", help="Enviar métricas y atender órdenes")
    run_parser.add_argument("--allow-http", action="store_true", help="Autorizar HTTP para IP privada explícita en LAN controlada")
    run_parser.add_argument("--once", action="store_true", help="Ejecutar un ciclo de diagnóstico y salir")
    commands.add_parser("status", help="Ver muestras pendientes e intervalos sin mostrar credenciales")
    commands.add_parser("retry-pending", help="Reintentar muestras diferidas tras corregir el contrato")
    commands.add_parser("allow-lan-http", help="Autorizar explícitamente HTTP privado en un agente ya registrado")
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    try:
        if args.mode == "enroll":
            enroll(args)
        elif args.mode == "status":
            show_status()
        elif args.mode == "retry-pending":
            config = load_config()
            changed = LocalSpool(spool_path_for(config)).retry_deferred_samples()
            print(f"Registros habilitados para reintento: {changed}")
        elif args.mode == "allow-lan-http":
            config = load_config()
            if urlparse(config["server"]).scheme != "http":
                raise ValueError("El servidor configurado ya utiliza HTTPS")
            validate_server(config["server"], True)
            config["allow_http"] = True
            save_config(config)
            print("Autorización HTTP LAN guardada para el servicio Windows. La red debe ser confiable.")
        else:
            run(args)
    except (ValueError, RuntimeError, OSError) as exc:
        LOG.error("%s", exc)
        sys.exit(1)


if __name__ == "__main__":
    main()
