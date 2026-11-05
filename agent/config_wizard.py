"""Administrative one-time binding wizard. Not a resident UI or tray program."""
from __future__ import annotations
import os
import sys
import threading
from pathlib import Path
import tkinter as tk
from tkinter import ttk, messagebox
from urllib.parse import urlparse

from provisioning import link_device, quick_link_device, retry_service
from storage_acl import ensure_admin, protect_storage
from windows_scm import ServiceLifecycle
import nodo_agent as agent


class Wizard(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("OSG Agent · Vinculación del equipo")
        self.geometry("600x510")
        self.resizable(False, False)
        self.configure(padx=28, pady=22)
        self.old = None
        try:
            self.old = agent.load_config()
        except RuntimeError as exc:
            if not isinstance(exc.__cause__, FileNotFoundError):
                messagebox.showerror("Permisos de configuración", str(exc))
                self.destroy()
                return
        self.ip = tk.StringVar(value=urlparse(self.old["server"]).hostname if self.old else "")
        self.port = tk.StringVar(value=str(urlparse(self.old["server"]).port or 8001) if self.old else "8001")
        self.scheme = tk.StringVar(value=urlparse(self.old["server"]).scheme if self.old else "http")
        self.code = tk.StringVar()
        self.feedback = tk.StringVar(value="Sin cambios en el equipo.")
        self._build()

    def _build(self):
        ttk.Label(self, text="OSG Agent", font=("Segoe UI", 19, "bold")).pack(anchor="w", pady=(0, 3))
        ttk.Label(self, text="Vincular computadora", font=("Segoe UI", 11)).pack(anchor="w", pady=(0, 8))
        if self.old:
            desc = f"Identidad actual: {self.old.get('uuid', 'desconocida')}\nServidor: {self.old.get('server', 'desconocido')}"
        else:
            desc = "Primera instalación. La vinculación creará una identidad para esta computadora."
        self.identity_label = ttk.Label(self, text=desc, wraplength=500)
        self.identity_label.pack(anchor="w", pady=(0, 15))
        form = ttk.Frame(self)
        form.pack(fill="x")
        ttk.Label(form, text="IP del servidor").grid(row=0, column=0, sticky="w", pady=7)
        ttk.Entry(form, textvariable=self.ip, width=31).grid(row=0, column=1, sticky="w", padx=(14, 0))
        ttk.Label(form, text="Puerto").grid(row=1, column=0, sticky="w", pady=7)
        ttk.Entry(form, textvariable=self.port, width=10).grid(row=1, column=1, sticky="w", padx=(14, 0))
        ttk.Label(form, text="Conexión").grid(row=2, column=0, sticky="w", pady=7)
        ttk.Combobox(form, textvariable=self.scheme, values=["http", "https"], width=9, state="readonly").grid(row=2, column=1, sticky="w", padx=(14, 0))
        ttk.Label(form, text="Código clásico (opcional)").grid(row=3, column=0, sticky="w", pady=7)
        ttk.Entry(form, textvariable=self.code, width=41, show="•").grid(row=3, column=1, sticky="w", padx=(14, 0))
        ttk.Label(self, text="Recomendado: solicitá vinculación y aprobala desde el panel de OSG Nodo Control.\nEl código clásico de 48 caracteres sigue disponible como alternativa.",
                  wraplength=490).pack(anchor="w", pady=12)
        self.status_label = ttk.Label(self, textvariable=self.feedback, wraplength=500, foreground="#555555")
        self.status_label.pack(anchor="w", pady=(0, 8))
        buttons = ttk.Frame(self)
        buttons.pack(fill="x", pady=7)
        self.apply_btn = ttk.Button(buttons, text="Usar código clásico", command=self._submit)
        self.apply_btn.pack(side="left")
        self.pair_btn = ttk.Button(buttons, text="Solicitar vinculación", command=self._pair)
        self.pair_btn.pack(side="left", padx=7)
        self.repair_btn = ttk.Button(buttons, text="Reparar inicio", command=self._repair)
        self.repair_btn.pack(side="left", padx=9)
        if self.old is None:
            self.repair_btn.configure(state="disabled")
        ttk.Button(buttons, text="Cerrar", command=self.destroy).pack(side="right")
        ttk.Label(self, text="Creado por Ignacio Sanguina · © 2026 · osgsecurity.com.ar", foreground="#777777", font=("Segoe UI", 8)).pack(side="bottom", anchor="w", pady=(12, 0))

    def _lifecycle(self):
        root = Path(sys.executable).resolve().parent.parent
        exe = root / "Agent" / "NodoControlAgent.exe"
        if not exe.is_file():
            raise FileNotFoundError("Falta NodoControlAgent.exe en la instalación. Volvé a ejecutar el instalador.")
        return ServiceLifecycle(executable_path=exe)

    def _work(self, fn):
        self.apply_btn.configure(state="disabled")
        self.pair_btn.configure(state="disabled")
        self.repair_btn.configure(state="disabled")
        self.feedback.set("Validando y configurando servicio…")
        def run():
            try:
                result = fn()
            except Exception as exc:
                # Do not leak credentials nor arbitrary server response bodies.
                msg = str(exc)[:350]
                self.after(0, lambda: self._finish(False, msg))
            else:
                self.after(0, lambda: self._finish(True, result))
        threading.Thread(target=run, daemon=True).start()

    def _finish(self, ok: bool, result: str):
        self.apply_btn.configure(state="normal")
        self.pair_btn.configure(state="normal")
        self.repair_btn.configure(state="normal")
        self.code.set("")
        self.feedback.set(result)
        if ok:
            try:
                self.old = agent.load_config()
                self.identity_label.configure(text=f"Identidad actual: {self.old.get('uuid', 'desconocida')}\nServidor: {self.old.get('server', 'desconocido')}")
            except RuntimeError:
                pass
            messagebox.showinfo("Nodo Control", result)
        else:
            messagebox.showerror("No se aplicó el cambio", result)

    def _submit(self):
        ip, port, code, scheme = self.ip.get().strip(), self.port.get().strip(), self.code.get().strip(), self.scheme.get()
        def task():
            linked = link_device(ip, port, code, scheme=scheme,
                                 lifecycle=self._lifecycle(), protect_storage=protect_storage)
            return f"Equipo vinculado: {linked.uuid}\nServidor: {linked.server}\nServicio: {'activo' if linked.service_running else 'revisar estado'}"
        self._work(task)

    def _pair(self):
        ip, port, scheme = self.ip.get().strip(), self.port.get().strip(), self.scheme.get()
        def show_code(code: str):
            self.after(0, lambda: self.feedback.set(
                f"Código de verificación: {code}\n"
                "Ingresalo en Equipos > Administrar vinculación en Laravel. "
                "La solicitud vence en cinco minutos."))
        def task():
            linked = quick_link_device(ip, port, scheme=scheme, on_code=show_code,
                lifecycle=self._lifecycle(), protect_storage=protect_storage)
            return f"Equipo autorizado: {linked.uuid}\nServicio: {'activo' if linked.service_running else 'revisar estado'}"
        self._work(task)

    def _repair(self):
        def task():
            active = retry_service(lifecycle=self._lifecycle(), protect_storage=protect_storage)
            return "El servicio está activo." if active else "No se confirmó el inicio del servicio."
        self._work(task)


def main():
    if os.name != "nt":
        raise SystemExit("El asistente solo funciona en Windows 10/11.")
    ensure_admin()
    Wizard().mainloop()


if __name__ == "__main__":
    main()
