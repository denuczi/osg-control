"""Native Windows Service Control Manager integration (no Python on client).

Uses Windows' documented service dispatcher via ctypes rather than pywin32's
PythonService.exe, making PyInstaller onedir packaging more predictable.
"""
from __future__ import annotations

import ctypes
from ctypes import wintypes
import os
import subprocess
import sys
import threading
import time

SERVICE_NAME = "NodoControlAgent"
DISPLAY_NAME = "OSG Agent"


def _windows_only():
    if os.name != "nt":
        raise RuntimeError("El servicio Nodo Control solo puede instalarse en Windows 10/11.")


def _sc(*args: str, check: bool = True) -> subprocess.CompletedProcess:
    _windows_only()
    creationflags = getattr(subprocess, "CREATE_NO_WINDOW", 0)
    result = subprocess.run(["sc.exe", *args], capture_output=True, text=True,
                            encoding="utf-8", errors="replace", timeout=30,
                            creationflags=creationflags)
    if check and result.returncode != 0:
        output = (result.stdout + " " + result.stderr).strip()
        raise RuntimeError(f"Windows SCM rechazó {args[0]} (código {result.returncode}): {output[:300]}")
    return result


def installed() -> bool:
    result = _sc("query", SERVICE_NAME, check=False)
    return result.returncode == 0


def running() -> bool:
    """Query numeric SCM state (independent of Spanish/English Windows locale)."""
    _windows_only()
    advapi = ctypes.WinDLL("advapi32", use_last_error=True)
    advapi.OpenSCManagerW.argtypes = [wintypes.LPCWSTR, wintypes.LPCWSTR, wintypes.DWORD]
    advapi.OpenSCManagerW.restype = wintypes.HANDLE
    advapi.OpenServiceW.argtypes = [wintypes.HANDLE, wintypes.LPCWSTR, wintypes.DWORD]
    advapi.OpenServiceW.restype = wintypes.HANDLE
    advapi.QueryServiceStatusEx.argtypes = [wintypes.HANDLE, wintypes.DWORD, ctypes.c_void_p,
                                           wintypes.DWORD, ctypes.POINTER(wintypes.DWORD)]
    advapi.QueryServiceStatusEx.restype = wintypes.BOOL
    advapi.CloseServiceHandle.argtypes = [wintypes.HANDLE]
    advapi.CloseServiceHandle.restype = wintypes.BOOL

    class SERVICE_STATUS_PROCESS(ctypes.Structure):
        _fields_ = [("dwServiceType", wintypes.DWORD), ("dwCurrentState", wintypes.DWORD),
                    ("dwControlsAccepted", wintypes.DWORD), ("dwWin32ExitCode", wintypes.DWORD),
                    ("dwServiceSpecificExitCode", wintypes.DWORD), ("dwCheckPoint", wintypes.DWORD),
                    ("dwWaitHint", wintypes.DWORD), ("dwProcessId", wintypes.DWORD),
                    ("dwServiceFlags", wintypes.DWORD)]

    manager = advapi.OpenSCManagerW(None, None, 0x0001)  # SC_MANAGER_CONNECT
    if not manager:
        raise OSError(ctypes.get_last_error(), "OpenSCManagerW")
    try:
        service = advapi.OpenServiceW(manager, SERVICE_NAME, 0x0004)  # SERVICE_QUERY_STATUS
        if not service:
            code = ctypes.get_last_error()
            if code == 1060:  # ERROR_SERVICE_DOES_NOT_EXIST
                return False
            raise OSError(code, "OpenServiceW")
        try:
            current = SERVICE_STATUS_PROCESS()
            needed = wintypes.DWORD()
            if not advapi.QueryServiceStatusEx(service, 0, ctypes.byref(current),
                                               ctypes.sizeof(current), ctypes.byref(needed)):
                raise OSError(ctypes.get_last_error(), "QueryServiceStatusEx")
            return current.dwCurrentState == 4  # SERVICE_RUNNING
        finally:
            advapi.CloseServiceHandle(service)
    finally:
        advapi.CloseServiceHandle(manager)


def install(executable_path=None):
    _windows_only()
    # An installer places the onedir executable under Program Files.
    exe = os.path.abspath(executable_path or (sys.executable if getattr(sys, "frozen", False) else __file__))
    if executable_path is None and not getattr(sys, "frozen", False):
        raise RuntimeError("La instalación requiere NodoControlAgent.exe compilado; no instalar desde código Python.")
    binary = f'"{exe}" service'
    if installed():
        # Upgrade a legacy PythonService.exe registration to the frozen binary.
        _sc("config", SERVICE_NAME, "binPath=", binary, "start=", "auto", "DisplayName=", DISPLAY_NAME)
    else:
        _sc("create", SERVICE_NAME, "binPath=", binary, "start=", "auto",
            "obj=", "LocalSystem", "DisplayName=", DISPLAY_NAME)
    try:
        _sc("description", SERVICE_NAME, "OSG Agent - monitoreo autorizado de equipos")
        _sc("failure", SERVICE_NAME, "reset=", "86400", "actions=",
            "restart/5000/restart/15000/restart/30000")
    except Exception:
        # Installed but not fully configured; fail rather than silently proceed.
        raise


def start():
    if running():
        return
    _sc("start", SERVICE_NAME)
    for _ in range(20):
        if running():
            return
        time.sleep(0.5)
    raise RuntimeError("El servicio no alcanzó estado RUNNING. Revisá los registros.")


def stop():
    if not installed() or not running():
        return
    _sc("stop", SERVICE_NAME)
    for _ in range(30):
        if not running():
            return
        time.sleep(0.5)
    raise RuntimeError("El servicio no se detuvo; no se cambiará su configuración.")


def uninstall():
    stop()
    if installed():
        _sc("delete", SERVICE_NAME)


class ServiceLifecycle:
    def __init__(self, executable_path=None):
        self.executable_path = executable_path
    def exists(self): return installed()
    def stop(self): return stop()
    def install(self): return install(self.executable_path)
    def start(self): return start()
    def running(self): return running()


def run_dispatcher():
    """SCM callback and status, registered when invoked with 'service'."""
    _windows_only()
    import nodo_agent
    from service_logging import setup_service_logging
    import logging

    advapi = ctypes.WinDLL("Advapi32", use_last_error=True)
    STOP = threading.Event()
    SERVICE_WIN32_OWN_PROCESS = 0x10
    SERVICE_START_PENDING = 2
    SERVICE_RUNNING = 4
    SERVICE_STOP_PENDING = 3
    SERVICE_STOPPED = 1
    SERVICE_ACCEPT_STOP = 1
    SERVICE_ACCEPT_SHUTDOWN = 4
    SERVICE_CONTROL_STOP = 1
    SERVICE_CONTROL_SHUTDOWN = 5
    NO_ERROR = 0

    class SERVICE_STATUS(ctypes.Structure):
        _fields_ = [("dwServiceType", wintypes.DWORD),
                    ("dwCurrentState", wintypes.DWORD),
                    ("dwControlsAccepted", wintypes.DWORD),
                    ("dwWin32ExitCode", wintypes.DWORD),
                    ("dwServiceSpecificExitCode", wintypes.DWORD),
                    ("dwCheckPoint", wintypes.DWORD),
                    ("dwWaitHint", wintypes.DWORD)]

    HANDLER = ctypes.WINFUNCTYPE(wintypes.DWORD, wintypes.DWORD, wintypes.DWORD,
                                 wintypes.LPVOID, wintypes.LPVOID)
    MAIN = ctypes.WINFUNCTYPE(None, wintypes.DWORD, ctypes.POINTER(wintypes.LPWSTR))

    class SERVICE_TABLE_ENTRY(ctypes.Structure):
        _fields_ = [("lpServiceName", wintypes.LPWSTR), ("lpServiceProc", MAIN)]

    advapi.RegisterServiceCtrlHandlerExW.argtypes = [wintypes.LPCWSTR, HANDLER, wintypes.LPVOID]
    advapi.RegisterServiceCtrlHandlerExW.restype = wintypes.HANDLE
    advapi.SetServiceStatus.argtypes = [wintypes.HANDLE, ctypes.POINTER(SERVICE_STATUS)]
    advapi.SetServiceStatus.restype = wintypes.BOOL
    advapi.StartServiceCtrlDispatcherW.argtypes = [ctypes.POINTER(SERVICE_TABLE_ENTRY)]
    advapi.StartServiceCtrlDispatcherW.restype = wintypes.BOOL

    def report(handle, state: int, exit_code: int = 0):
        status = SERVICE_STATUS(SERVICE_WIN32_OWN_PROCESS, state,
                                (SERVICE_ACCEPT_STOP | SERVICE_ACCEPT_SHUTDOWN) if state == SERVICE_RUNNING else 0,
                                exit_code, 0, 0, 15000 if state in (SERVICE_START_PENDING, SERVICE_STOP_PENDING) else 0)
        if not advapi.SetServiceStatus(handle, ctypes.byref(status)):
            raise OSError(ctypes.get_last_error(), "SetServiceStatus")

    handle_state = {"value": None}

    @HANDLER
    def on_control(code, event_type, event_data, context):
        if code in (SERVICE_CONTROL_STOP, SERVICE_CONTROL_SHUTDOWN):
            STOP.set()
            if handle_state["value"] is not None:
                report(handle_state["value"], SERVICE_STOP_PENDING)
        return NO_ERROR

    @MAIN
    def main(argc, argv):
        handle = advapi.RegisterServiceCtrlHandlerExW(SERVICE_NAME, on_control, None)
        if not handle:
            return
        handle_state["value"] = handle
        try:
            report(handle, SERVICE_START_PENDING)
            setup_service_logging()
            report(handle, SERVICE_RUNNING)
            nodo_agent.run_agent(STOP)
        except Exception:
            logging.getLogger("nodo-agent").exception("El servicio terminó inesperadamente")
            report(handle, SERVICE_STOPPED, exit_code=1)
        else:
            report(handle, SERVICE_STOPPED)

    entry = (SERVICE_TABLE_ENTRY * 2)()
    entry[0] = SERVICE_TABLE_ENTRY(SERVICE_NAME, main)
    entry[1] = SERVICE_TABLE_ENTRY(None, MAIN())
    # Keep callback objects alive until the dispatcher returns.
    if not advapi.StartServiceCtrlDispatcherW(entry):
        raise OSError(ctypes.get_last_error(), "StartServiceCtrlDispatcherW")
