"""Machine-wide private data: only LocalSystem and Builtin Administrators.

Native Windows ACLs; SID-based SDDL avoids localized Administrators names.
No ACLs outside C:\\ProgramData\\NodoControl are changed.
"""
from __future__ import annotations
import ctypes
from pathlib import Path
import os
import nodo_agent

SDDL_PRIVATE = "D:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)"


def ensure_admin() -> None:
    if os.name != "nt" or not ctypes.windll.shell32.IsUserAnAdmin():
        raise PermissionError("Ejecutá el asistente como administrador.")


def _set_private_acl(path: Path) -> None:
    """Replace entire DACL rather than merely appending ACEs to old permissions."""
    if os.name != "nt":
        raise RuntimeError("ACL de Windows no disponible.")
    from ctypes import wintypes
    advapi = ctypes.WinDLL("advapi32", use_last_error=True)
    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    descriptor = ctypes.c_void_p()
    dacl = ctypes.c_void_p()
    present = wintypes.BOOL()
    defaulted = wintypes.BOOL()
    advapi.ConvertStringSecurityDescriptorToSecurityDescriptorW.argtypes = [
        wintypes.LPCWSTR, wintypes.DWORD, ctypes.POINTER(ctypes.c_void_p), ctypes.c_void_p]
    advapi.ConvertStringSecurityDescriptorToSecurityDescriptorW.restype = wintypes.BOOL
    advapi.GetSecurityDescriptorDacl.argtypes = [ctypes.c_void_p, ctypes.POINTER(wintypes.BOOL),
                                                ctypes.POINTER(ctypes.c_void_p), ctypes.POINTER(wintypes.BOOL)]
    advapi.GetSecurityDescriptorDacl.restype = wintypes.BOOL
    advapi.SetNamedSecurityInfoW.argtypes = [wintypes.LPWSTR, wintypes.DWORD, wintypes.DWORD,
                                           ctypes.c_void_p, ctypes.c_void_p, ctypes.c_void_p, ctypes.c_void_p]
    advapi.SetNamedSecurityInfoW.restype = wintypes.DWORD
    kernel.LocalFree.argtypes = [ctypes.c_void_p]
    kernel.LocalFree.restype = ctypes.c_void_p
    if not advapi.ConvertStringSecurityDescriptorToSecurityDescriptorW(SDDL_PRIVATE, 1,
                                                                       ctypes.byref(descriptor), None):
        raise OSError(ctypes.get_last_error(), "Invalid SDDL security descriptor")
    try:
        if not advapi.GetSecurityDescriptorDacl(descriptor, ctypes.byref(present),
                                                ctypes.byref(dacl), ctypes.byref(defaulted)) or not present.value:
            raise OSError(ctypes.get_last_error(), "No DACL available")
        SE_FILE_OBJECT = 1
        DACL_SECURITY_INFORMATION = 0x00000004
        PROTECTED_DACL_SECURITY_INFORMATION = 0x80000000
        result = advapi.SetNamedSecurityInfoW(str(path), SE_FILE_OBJECT,
                                               DACL_SECURITY_INFORMATION | PROTECTED_DACL_SECURITY_INFORMATION,
                                               None, None, dacl, None)
        if result != 0:
            raise OSError(result, f"Cannot protect {path.name}")
    finally:
        kernel.LocalFree(descriptor)


def protect_storage() -> None:
    ensure_admin()
    directory = nodo_agent.CONFIG_DIR
    if directory.is_symlink():
        raise RuntimeError("La carpeta de datos es un vínculo; operación rechazada.")
    directory.mkdir(parents=True, exist_ok=True)
    _set_private_acl(directory)
    # Apply to previously created secret, SQLite WAL, old backups, logs.
    for base, dirs, files in os.walk(directory, followlinks=False):
        for name in dirs + files:
            item = Path(base) / name
            if item.is_symlink():
                raise RuntimeError("Hay un vínculo dentro de la carpeta de datos; operación rechazada.")
            _set_private_acl(item)
