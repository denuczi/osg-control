"""CLI/service executable. The 'service' mode has no desktop window or tray icon."""
import argparse
import sys
import os


def main() -> int:
    import windows_scm as scm
    parser = argparse.ArgumentParser(prog="NodoControlAgent", description="OSG Agent · administración del servicio")
    parser.add_argument("command", choices=("service", "install", "start", "stop", "status", "uninstall", "diagnose"))
    args = parser.parse_args()
    try:
        if args.command == "service": scm.run_dispatcher()
        elif args.command == "install": scm.install()
        elif args.command == "start": scm.start()
        elif args.command == "stop": scm.stop()
        elif args.command == "uninstall": scm.uninstall()
        elif args.command == "status":
            import nodo_agent
            print("Servicio:", "Activo" if scm.running() else ("Detenido" if scm.installed() else "No instalado"))
            try:
                nodo_agent.show_status()
            except RuntimeError as exc:
                print(str(exc))
        elif args.command == "diagnose":
            import nodo_agent
            cfg = nodo_agent.load_config()
            server = nodo_agent.validate_server(cfg["server"], cfg.get("allow_http", False))
            result = nodo_agent.call_api(server, "/api/v1/agents/heartbeat", "POST",
                          token=cfg["token"], payload={"hostname": nodo_agent.platform.node()[:120],
                          "platform": f"{nodo_agent.platform.system()} {nodo_agent.platform.release()}"[:120]})
            if not result.get("ok"):
                raise RuntimeError("Heartbeat no confirmado")
            print("Conexión y autenticación correctas.")
        return 0
    except Exception as exc:
        print(f"ERROR: {type(exc).__name__}: {str(exc)[:350]}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
