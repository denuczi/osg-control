"""Windows service wrapper (pywin32). Run with an approved service account.

Setup from an elevated terminal after enrollment and ProgramData ACL hardening:
    python windows_service.py --startup auto install
    python windows_service.py start
    python windows_service.py stop

HTTP LAN se autoriza explícitamente al registrar el agente y queda en agent.json.
Una instalación expuesta a redes no confiables debe utilizar HTTPS.
"""
import logging
import os
import sys
import threading

if os.name != "nt":
    raise RuntimeError("Este módulo solo se ejecuta en Windows")

import servicemanager
import win32event
import win32service
import win32serviceutil

from nodo_agent import run_agent


class NodoService(win32serviceutil.ServiceFramework):
    _svc_name_ = "NodoControlAgent"
    _svc_display_name_ = "OSG Agent"
    _svc_description_ = "OSG Agent - monitoreo autorizado de equipos"

    def __init__(self, args):
        super().__init__(args)
        self.stop_event = threading.Event()
        self.stop_handle = win32event.CreateEvent(None, 0, 0, None)

    def SvcStop(self):
        self.ReportServiceStatus(win32service.SERVICE_STOP_PENDING)
        self.stop_event.set()
        win32event.SetEvent(self.stop_handle)

    def SvcDoRun(self):
        logging.basicConfig(level=logging.INFO)
        servicemanager.LogInfoMsg("Nodo Control iniciando")
        try:
            run_agent(self.stop_event, allow_http=os.environ.get("NODO_ALLOW_HTTP") == "1")
        except Exception as exc:
            servicemanager.LogErrorMsg("Nodo Control detenido por error: " + str(exc)[:200])
            raise


if __name__ == "__main__":
    win32serviceutil.HandleCommandLine(NodoService)
