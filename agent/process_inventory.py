"""Bounded Windows process inventory for display only.

Display names and group identifiers are advisory metadata. They must never be
used for process authorization; remote commands retain PID/name/start-time checks.
No executable paths, command lines or user names are transmitted to the server.
"""
from __future__ import annotations

from functools import lru_cache
import re
import hashlib
import ntpath
import os
from typing import Callable

MAX_CAPTURED_PROCESSES = 250
# Shared runtime hosts frequently launch unrelated workloads from the same binary.
# Without an application-specific identity they must remain separate processes.
GENERIC_HOSTS = frozenset({
    'python.exe', 'pythonw.exe', 'node.exe', 'java.exe', 'javaw.exe',
    'powershell.exe', 'pwsh.exe', 'cmd.exe', 'conhost.exe',
    'svchost.exe', 'rundll32.exe', 'dllhost.exe', 'wscript.exe', 'cscript.exe',
})


def _clean_description(value: object) -> str | None:
    if not isinstance(value, str):
        return None
    # Do not forward control characters or excessively long version resources.
    cleaned = " ".join(re.sub(r"[\x00-\x1f\x7f]+", " ", value).split()).strip()
    return cleaned[:160] if cleaned else None


@lru_cache(maxsize=256)
def executable_description(path: str) -> str | None:
    """Read VERSIONINFO/FileDescription once per executable (Windows only).

    Missing/invalid resources are normal: return None, never fail a snapshot.
    """
    if os.name != "nt" or not path:
        return None
    try:
        import win32api
        versions = [(0x0409, 1200)]  # English neutral fallback
        try:
            translations = win32api.GetFileVersionInfo(path, r"\VarFileInfo\Translation")
            if isinstance(translations, (list, tuple)):
                for item in translations:
                    if isinstance(item, (list, tuple)) and len(item) == 2:
                        versions.insert(0, (int(item[0]), int(item[1])))
        except (OSError, ValueError, TypeError):
            pass
        for language, codepage in dict.fromkeys(versions):
            for field in ("FileDescription", "ProductName"):
                try:
                    value = win32api.GetFileVersionInfo(
                        path, f"\\StringFileInfo\\{language:04x}{codepage:04x}\\{field}"
                    )
                    name = _clean_description(value)
                    if name:
                        return name
                except (OSError, ValueError, TypeError):
                    continue
    except Exception:  # Optional version metadata must not break telemetry.
        pass
    return None


def process_session_id(pid: int) -> int | None:
    if os.name != "nt":
        return None
    try:
        import win32ts
        return int(win32ts.ProcessIdToSessionId(pid))
    except Exception:  # Native Windows API may fail on protected/vanished processes.
        return None


def display_identity(proc: object, pid: int, started_at: float, name: str) -> tuple[str, str]:
    """Conservatively group by executable path AND Windows session.

    If either identity is inaccessible, do not group by basename: independent
    Python/Java instances could otherwise be mistakenly merged.
    """
    try:
        executable = proc.exe()  # type: ignore[attr-defined]
    except Exception:  # psutil.AccessDenied and vanished processes are normal here.
        executable = None
    session = process_session_id(pid)
    if (isinstance(executable, str) and executable and session is not None
            and name.casefold() not in GENERIC_HOSTS):
        normalized = ntpath.normcase(ntpath.normpath(executable))
        key = hashlib.sha256(f"{session}\0{normalized}".encode("utf-8", "replace")).hexdigest()[:32]
        return executable_description(executable) or name, key
    # Unknown path/session: unique to this individual process; no false group.
    key = hashlib.sha256(f"pid:{pid}\0start:{started_at}".encode()).hexdigest()[:32]
    return (executable_description(executable) if isinstance(executable, str) else None) or name, key


def capture_processes(psutil_module: object, protected: Callable[[int, str], bool], *,
                      limit: int = MAX_CAPTURED_PROCESSES) -> tuple[list[dict], int]:
    """Inventory without expensive executable lookups until after top-N selection.

    The total is the number of observed non-idle processes, including processes
    whose details are inaccessible; it may exceed the number captured.
    """
    candidates: list[tuple[dict, object]] = []
    total = 0
    for proc in psutil_module.process_iter(["pid", "name", "memory_info", "create_time"]):
        try:
            info = proc.info
            pid = info.get("pid")
            if type(pid) is not int or pid <= 0:
                continue
            total += 1
            name = str(info.get("name") or "")[:160]
            memory = info.get("memory_info")
            started = info.get("create_time")
            if not name or memory is None or started is None:
                continue
            entry = {
                "pid": pid,
                "name": name,
                "memory_mb": round(max(0, memory.rss) / 1048576, 1),
                "started_at": float(started),
                "can_terminate": not protected(pid, name),
            }
            candidates.append((entry, proc))
        except (psutil_module.AccessDenied, psutil_module.NoSuchProcess,
                psutil_module.ZombieProcess, OSError, ValueError, TypeError, AttributeError):
            continue

    candidates.sort(key=lambda item: item[0]["memory_mb"], reverse=True)
    selected = []
    for entry, proc in candidates[:limit]:
        entry["display_name"], entry["group_key"] = display_identity(
            proc, entry["pid"], entry["started_at"], entry["name"]
        )
        selected.append(entry)
    return selected, total
