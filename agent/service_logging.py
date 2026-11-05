"""Bounded Windows service logs; credentials and request bodies are never logged."""
from __future__ import annotations
import logging
from logging.handlers import RotatingFileHandler
import nodo_agent


def setup_service_logging() -> None:
    target = nodo_agent.CONFIG_DIR / "logs"
    target.mkdir(parents=True, exist_ok=True)
    handler = RotatingFileHandler(target / "agent.log", maxBytes=2_000_000,
                                  backupCount=3, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    root = logging.getLogger()
    root.handlers.clear()
    root.addHandler(handler)
    root.setLevel(logging.INFO)
