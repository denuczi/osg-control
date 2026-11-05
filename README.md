# OSG Nodo Control

Servidor Laravel/Vue y agente Python para administrar computadoras en una red
local, consultar telemetría e inventario y generar informes PDF.

- [Instalación y administración con Docker Engine en Linux y Windows/WSL2](DOCKER-LINUX-WINDOWS.md).
- [Construcción del instalador del agente Windows](agent/installer/README.md).
- [Pruebas de la integración Docker](tests/docker/README.md).

El servidor y los agentes se despliegan por separado. Docker publica inicialmente
el panel en `http://127.0.0.1:8088/login`; consulta la guía para habilitar acceso
desde otras computadoras.

Para contribuir, describe el problema o cambio en un issue o pull request,
incluye los pasos para reproducirlo y las verificaciones ejecutadas. No adjuntes
claves de API, configuración privada, bases de datos ni respaldos.

Distribuido bajo la [licencia MIT](LICENSE).
