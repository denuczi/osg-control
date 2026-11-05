"""Atomic and authenticated device binding to a Nodo Control server.

A code is consumed only by Laravel. The current local identity is left untouched
until the remote server has accepted the new code and returned a valid token.
Old telemetry remains in its device-specific SQLite file, never replayed with a
new identity.
"""
from __future__ import annotations

from dataclasses import dataclass
import ipaddress
import os
import re
import secrets
import time
import json
from datetime import datetime, timezone
import uuid
from pathlib import Path

import nodo_agent as agent

CODE_PATTERN = re.compile(r"[0-9a-fA-F]{48}\Z")
TOKEN_PATTERN = re.compile(r"[0-9a-fA-F]{64}\Z")


@dataclass(frozen=True)
class BindingResult:
    uuid: str
    server: str
    replaced: bool
    service_running: bool


def make_server(ip: str, port: str | int, scheme: str = "http") -> str:
    """Reject hostnames for explicit-IP enrollment and validate the port."""
    address = ipaddress.ip_address(ip.strip())
    if address.version != 4:
        raise ValueError("Esta versión del asistente requiere una dirección IPv4 del servidor.")
    if scheme not in ("http", "https"):
        raise ValueError("Protocolo no admitido.")
    try:
        port_int = int(str(port).strip())
    except (ValueError, TypeError) as exc:
        raise ValueError("Puerto inválido; debe ser un número entre 1 y 65535.") from exc
    if not (1 <= port_int <= 65535):
        raise ValueError("Puerto fuera de rango (1 a 65535).")
    private_ranges = (ipaddress.ip_network("10.0.0.0/8"),
                      ipaddress.ip_network("172.16.0.0/12"),
                      ipaddress.ip_network("192.168.0.0/16"))
    if not any(address in network for network in private_ranges):
        raise ValueError("Usá una dirección IPv4 privada RFC1918 del servidor de la LAN.")
    return agent.validate_server(f"{scheme}://{address}:{port_int}", allow_http=scheme == "http")


def _read_existing() -> dict | None:
    try:
        return agent.load_config()
    except RuntimeError as exc:
        # FileNotFoundError is distinguishable via __cause__, no blanket catch.
        if isinstance(exc.__cause__, FileNotFoundError):
            return None
        raise


def _verify_new_registration(server: str, code: str) -> dict:
    if not CODE_PATTERN.fullmatch(code.strip()):
        raise ValueError("ID de vinculación inválida: debe contener 48 caracteres hexadecimales.")
    proposed_uuid = str(uuid.uuid4())
    try:
        result = agent.call_api(server, "/api/v1/agents/enroll", "POST", payload={
        "code": code.strip().lower(),
        "uuid": proposed_uuid,
        "hostname": agent.platform.node()[:120],
        "platform": f"{agent.platform.system()} {agent.platform.release()}"[:120],
        })
    except agent.ApiError as exc:
        if exc.code == 422:
            raise ValueError("La ID de vinculación es inválida, está vencida o ya fue utilizada. No se cambió el equipo.") from exc
        if exc.code == 409:
            raise ValueError("El servidor rechazó el identificador de equipo; solicitá otra ID de vinculación.") from exc
        raise
    if not isinstance(result, dict) or result.get("device_id") != proposed_uuid or not isinstance(result.get("token"), str) or not TOKEN_PATTERN.fullmatch(result["token"]):
        raise RuntimeError("Respuesta de vinculación inválida: no se modificarán las credenciales vigentes.")
    return {"server": server, "uuid": proposed_uuid, "token": result["token"],
            "allow_http": server.startswith("http://"),
            "spool_file": f"telemetry-{proposed_uuid}.sqlite"}


def _activate_config(new: dict, *, lifecycle=None, protect_storage=None) -> BindingResult:
    """Commit a validated remote registration with best-effort rollback."""
    old = _read_existing()
    if protect_storage is not None:
        protect_storage()
    had_service = bool(lifecycle is not None and lifecycle.exists())
    if had_service:
        lifecycle.stop()
    committed = False
    try:
        agent.save_config(new)
        committed = True
        if lifecycle is not None:
            lifecycle.install()
            lifecycle.start()
    except Exception as exc:
        if committed:
            try:
                if old is not None:
                    agent.save_config(old)
                else:
                    agent.CONFIG_FILE.unlink(missing_ok=True)
            except Exception as rollback_exc:
                raise RuntimeError("Fallo al iniciar servicio y al restaurar configuración.") from rollback_exc
        if had_service and lifecycle is not None:
            try:
                lifecycle.start()
            except Exception:
                pass
        raise RuntimeError("La vinculación fue aprobada, pero no pudo iniciarse el servicio. Se restauró la configuración anterior.") from exc
    return BindingResult(new["uuid"], new["server"], old is not None,
                         lifecycle.running() if lifecycle is not None else False)


def link_device(ip: str, port: str | int, code: str, *, scheme: str = "http", lifecycle=None,
                protect_storage=None) -> BindingResult:
    """Legacy high-entropy one-time code: do not disturb the previous binding on failure."""
    server = make_server(ip, port, scheme)
    new = _verify_new_registration(server, code)
    return _activate_config(new, lifecycle=lifecycle, protect_storage=protect_storage)


def request_pairing(ip: str, port: str | int, *, scheme: str = "http") -> tuple[str, dict]:
    """Create a pending request; the six-digit code is never an authentication token."""
    server = make_server(ip, port, scheme)
    token = secrets.token_hex(32)
    proposed_uuid = str(uuid.uuid4())
    response = agent.call_api(server, "/api/v1/agents/pairing", "POST", payload={
        "uuid": proposed_uuid,
        "token": token,
        "hostname": agent.platform.node()[:120],
        "platform": f"{agent.platform.system()} {agent.platform.release()}"[:120],
    })
    if not isinstance(response, dict) or not isinstance(response.get("request_id"), str) or \
            not re.fullmatch(r"[0-9a-fA-F-]{36}", response["request_id"]) or \
            not re.fullmatch(r"[0-9]{6}", str(response.get("verification_code", ""))):
        raise RuntimeError("Respuesta de emparejamiento inválida.")
    pending = {
        "server": server, "uuid": proposed_uuid, "token": token,
        "request_id": response["request_id"],
        "verification_code": response["verification_code"],
        "expires_at": response.get("expires_at"),
        "allow_http": server.startswith("http://"),
        "spool_file": f"telemetry-{proposed_uuid}.sqlite",
    }
    return response["verification_code"], pending


def await_pairing(pending: dict, *, timeout_seconds: float = 300,
                  interval_seconds: float = 3, lifecycle=None, protect_storage=None) -> BindingResult:
    """Poll with a 256-bit agent secret. A lost response can be safely retried."""
    deadline = time.monotonic() + max(1, timeout_seconds)
    while time.monotonic() < deadline:
        try:
            response = agent.call_api(pending["server"],
                "/api/v1/agents/pairing/" + pending["request_id"], "GET",
                token=pending["token"])
        except (OSError, agent.ApiError):
            # The server may have approved the device while this request failed.
            response = {"status": "pending"}
        if not isinstance(response, dict):
            raise RuntimeError("Respuesta de autorización inválida.")
        status = response.get("status")
        if status == "approved":
            if response.get("device_id") != pending["uuid"]:
                raise RuntimeError("Identidad de equipo inesperada en la aprobación.")
            new = {key: pending[key] for key in
                   ("server", "uuid", "token", "allow_http", "spool_file")}
            return _activate_config(new, lifecycle=lifecycle, protect_storage=protect_storage)
        if status in ("rejected", "expired"):
            raise ValueError("La solicitud fue rechazada o venció. No se alteró la vinculación actual.")
        if status != "pending":
            raise RuntimeError("Estado de vinculación desconocido.")
        time.sleep(min(interval_seconds, max(0, deadline - time.monotonic())))
    raise TimeoutError("La autorización no llegó a tiempo. Generá una nueva solicitud en Windows.")


def _pending_file() -> Path:
    return agent.CONFIG_DIR / "pending-pairing.json"


def _read_pending(server: str) -> dict | None:
    path = _pending_file()
    try:
        pending = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError:
        return None
    except (OSError, ValueError) as exc:
        raise RuntimeError("No se pudo leer el estado de vinculación pendiente; no se reemplazó la identidad actual.") from exc
    try:
        expiry = datetime.fromisoformat(pending["expires_at"].replace("Z", "+00:00"))
        if expiry.tzinfo is None:
            raise ValueError("Sin zona horaria")
    except (KeyError, ValueError, AttributeError) as exc:
        raise RuntimeError("El estado de vinculación pendiente es inválido.") from exc
    if pending.get("server") != server or expiry <= datetime.now(timezone.utc):
        path.unlink(missing_ok=True)
        return None
    if not TOKEN_PATTERN.fullmatch(str(pending.get("token", ""))) or not \
            re.fullmatch(r"[0-9]{6}", str(pending.get("verification_code", ""))):
        raise RuntimeError("Credencial de vinculación pendiente inválida.")
    return pending


def _save_pending(pending: dict, protect_storage=None) -> None:
    if protect_storage is not None:
        protect_storage()
    agent.CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    path = _pending_file()
    temporary = path.with_suffix(".tmp")
    try:
        temporary.write_text(json.dumps(pending, ensure_ascii=False), encoding="utf-8")
        if os.name != 'nt':
            temporary.chmod(0o600)
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)


def quick_link_device(ip: str, port: str | int, *, scheme: str = "http", on_code=None,
                      lifecycle=None, protect_storage=None) -> BindingResult:
    server = make_server(ip, port, scheme)
    pending = _read_pending(server)
    if pending is None:
        code, pending = request_pairing(ip, port, scheme=scheme)
        _save_pending(pending, protect_storage=protect_storage)
    else:
        code = pending["verification_code"]
    if on_code is not None:
        on_code(code)
    result = await_pairing(pending, lifecycle=lifecycle, protect_storage=protect_storage)
    _pending_file().unlink(missing_ok=True)
    return result


def retry_service(*, lifecycle, protect_storage=None) -> bool:
    existing = _read_existing()
    if not existing or not existing.get("token"):
        raise RuntimeError("No hay identidad registrada para recuperar. Vinculá el equipo primero.")
    if protect_storage is not None:
        protect_storage()
    if lifecycle.exists() and lifecycle.running():
        lifecycle.stop()
    lifecycle.install()  # also updates a legacy service binary path
    lifecycle.start()
    return lifecycle.running()
