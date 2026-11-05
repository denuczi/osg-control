"""Windows service -> authorized active user session (no Session 0 GUI).

Launches a *separate*, unprivileged notifier executable. Never opens a shell,
reuses a privileged token, or claims that a window was acknowledged by the user.
"""
from __future__ import annotations

import base64
import os
import sys
from pathlib import Path


def launch_message(text: str) -> list:
    if os.name != "nt":
        raise OSError("Solo Windows puede presentar notificaciones interactivas")
    if not isinstance(text, str) or not 1 <= len(text.strip()) <= 500:
        raise ValueError("El mensaje debe contener de 1 a 500 caracteres")

    import win32con
    import win32process
    import win32profile
    import win32security
    import win32ts

    # The notifier is packaged alongside the agent in Program Files.
    binary = Path(sys.executable).resolve().parent / "Notifier" / "OSGNotifier.exe"
    if not binary.is_file():
        raise FileNotFoundError("Falta OSGNotifier.exe en la instalación")
    encoded = base64.urlsafe_b64encode(text.encode("utf-8")).decode("ascii")
    command = f'"{binary}" --message {encoded}'
    sessions = win32ts.WTSEnumerateSessions(win32ts.WTS_CURRENT_SERVER_HANDLE)
    # Only active sessions with real logged-in users, never Session 0.
    targets = sorted({int(session['SessionId']) for session in sessions
                      if session['State'] == win32ts.WTSActive and int(session['SessionId']) > 0})
    if not targets:
        raise RuntimeError("No hay sesiones de usuario activas para mostrar el mensaje")
    processes = []
    failures = []
    for session_id in targets[:8]:
        token = primary = None
        proc = thread = None
        env = None
        try:
            token = win32ts.WTSQueryUserToken(session_id)
            primary = win32security.DuplicateTokenEx(
                token, win32security.SecurityImpersonation, win32con.TOKEN_ALL_ACCESS,
                win32security.TokenPrimary)
            env = win32profile.CreateEnvironmentBlock(primary, False)
            startup = win32process.STARTUPINFO()
            startup.lpDesktop = 'winsta0\\default'
            proc, thread, _, _ = win32process.CreateProcessAsUser(
                primary, str(binary), command, None, None, False,
                win32con.CREATE_UNICODE_ENVIRONMENT | win32con.CREATE_NEW_PROCESS_GROUP,
                env, str(binary.parent), startup)
            processes.append(proc)
            proc = None  # Ownership transferred to the asynchronous acknowledgement waiter.
        except Exception as exc:
            # Keep diagnostic Win32 code, but do not include message contents or tokens.
            code = getattr(exc, 'winerror', None)
            if code is None and getattr(exc, 'args', None) and isinstance(exc.args[0], int):
                code = exc.args[0]
            failures.append(f"sesión {session_id}: {type(exc).__name__} {code or ''}".strip())
            # Fail closed: do not fall back to Session 0 or privileged GUI.
            continue
        finally:
            for handle in (thread, proc, primary, token):
                if handle is not None:
                    try:
                        handle.Close()
                    except Exception:
                        pass
            if env is not None:
                try:
                    win32profile.DestroyEnvironmentBlock(env)
                except Exception:
                    pass
    if not processes:
        raise OSError("No se pudo iniciar el mensaje en sesión de usuario: " + "; ".join(failures)[:200])
    if failures:
        # Some sessions may already show the notification. Do not claim full delivery.
        for handle in processes:
            try:
                handle.Close()
            except Exception:
                pass
        raise OSError("El mensaje no pudo abrirse en todas las sesiones activas: " + "; ".join(failures)[:200])
    return processes


def wait_for_close(process_handles: list, timeout_seconds: int = 540) -> tuple[str, str]:
    """Exit code zero is emitted only by the notifier's Cerrar button.

    Handles are created by CreateProcessAsUser, not data supplied by the user.
    No IPC secret, writable file or privileged GUI is exposed to the session.
    """
    import win32event
    import win32process

    try:
        result = win32event.WaitForMultipleObjects(process_handles, True, timeout_seconds * 1000)
        if result == win32event.WAIT_TIMEOUT:
            return "unknown", "El mensaje expiró sin confirmación del usuario."
        if result != win32event.WAIT_OBJECT_0:
            return "failed", "No se pudo confirmar el cierre de la notificación."
        if any(win32process.GetExitCodeProcess(handle) != 0 for handle in process_handles):
            return "failed", "Una sesión cerró inesperadamente el notificador sin presionar Cerrar."
        return "completed", "El usuario confirmó el mensaje mediante el botón Cerrar."
    finally:
        for handle in process_handles:
            try:
                handle.Close()
            except Exception:
                pass


def show_message(text: str) -> str:
    """Compatibility helper. A synchronous call cannot claim click acknowledgement."""
    handles = launch_message(text)
    status, description = wait_for_close(handles)
    if status != 'completed':
        raise RuntimeError(description)
    return description
