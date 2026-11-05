# OSG Nodo Agent — compilación e instalación en Windows 10/11 x64

El agente se instala en cada computadora Windows que enviará información al
servidor Laravel. Puedes descargar el instalador desde las
[publicaciones del proyecto](https://github.com/denuczi/osg-control/releases)
o compilarlo en Windows siguiendo esta guía. La instalación empaquetada no
requiere Python en la computadora de destino. Consulta el
[README principal](../../README.md) para preparar el servidor y conocer la
arquitectura completa. Las comprobaciones pendientes en Windows se detallan
más abajo; disponer de un binario publicado no demuestra que estén completadas.

## Requisitos de la computadora de compilación

- Windows 10/11 **x64** con actualizaciones de seguridad, PowerShell 5.1 o superior.
- Python **3.15 de 64 bits** (ejecutable `py -3.15`). Solo es necesario en la computadora de compilación.
- [Inno Setup](https://jrsoftware.org/isinfo.php), versión 7 preferida o 6 admitida por el script; comando `ISCC.exe` instalado.
- Acceso al registro PyPI para obtener paquetes de compilación.

## Construir

Desde PowerShell en la carpeta `agent`:

```powershell
.\installer\build-windows.ps1
```

Primero crea un entorno **solo para compilar**, instala dependencias, ejecuta las pruebas del agente y genera el servicio, el asistente y el notificador de sesión con PyInstaller. Por último, Inno Setup empaqueta los tres en **un solo instalador**:

`agent\dist-installer\NodoControl-Agent-Setup.exe`

No copies a Windows final un ZIP de scripts Python: llevá únicamente el instalador producido.

> Los binarios generados no están firmados. Para distribución administrada es recomendable firmar el instalador y los EXE con un certificado de firma de código de la organización.

## Instalación y vinculación

1. Prepara el servidor y habilita una dirección accesible desde la LAN según la
   [guía Docker](../../DOCKER-LINUX-WINDOWS.md#6-acceso-desde-la-lan-y-https).
2. En cada Windows x64, ejecuta `NodoControl-Agent-Setup.exe` como administrador.
3. En **Configurar OSG Agent**, indica la IPv4 del servidor Laravel, el puerto
   configurado y el protocolo HTTP o HTTPS. El puerto inicial de Docker es 8088;
   utiliza el puerto real si lo cambiaste.
4. Pulsa **Solicitar vinculación**. El asistente muestra un código de seis dígitos.
5. En el panel, abre **Equipos → Agregar o administrar equipos**, identifica la
   solicitud e introduce ese código para aprobarla.
6. El agente consulta la aprobación y configura el servicio `NodoControlAgent`
   con inicio automático. Comprueba que el equipo envíe información al panel.

### Código clásico y reparación

El método **Usar código clásico** sigue disponible. Puedes generar el código
desde el panel o, en un despliegue Docker, desde la carpeta del servidor:

```bash
docker compose exec app php artisan nodo:enroll-code --minutes=60
```

Introduce el código de 48 caracteres con la dirección, puerto y protocolo del
servidor. Si la validación falla, se conserva la configuración anterior. Si
el servicio no arranca después de guardar una identidad válida, utiliza
**Reparar inicio** sin consumir otro código.

La ID de vinculación no es la UUID permanente. Laravel emite el código de un solo uso; el agente crea una UUID aleatoria que el servidor confirma. Esto evita que el operador deba copiar UUID, token o contraseñas de la API.

### Cambiar servidor/IP/ID

Abre como administrador `Configurar OSG Agent` en el menú Inicio. Introduce la
nueva dirección y puerto y solicita una vinculación para aprobar desde el panel,
o utiliza un código clásico válido. La validación ocurre antes de detener el
servicio o reemplazar credenciales. Las muestras de la identidad antigua quedan
en un archivo SQLite local separado y no se reenvían con la nueva identidad.

**Importante:** una nueva vinculación puede crear otra entrada de dispositivo en Laravel. El administrador debe revocar la identidad antigua desde el servidor cuando ya no se necesite; no borrar su historial sin autorización.

### Administración sin interfaz residente

Abrir PowerShell como administrador:

```powershell
& 'C:\Program Files\NodoControl\Agent\NodoControlAgent.exe' status
& 'C:\Program Files\NodoControl\Agent\NodoControlAgent.exe' stop
& 'C:\Program Files\NodoControl\Agent\NodoControlAgent.exe' start
& 'C:\Program Files\NodoControl\Agent\NodoControlAgent.exe' diagnose
```

La operación `diagnose` hace **solo un heartbeat de autenticación**; no descarga ni ejecuta órdenes remotas. El panel de servicios Windows también permite administrar el servicio, sujeto a los permisos del administrador.

Al desinstalar el software se detiene y elimina el servicio; por seguridad y para evitar pérdida de datos, el directorio `C:\ProgramData\NodoControl` se conserva. El administrador decide si lo elimina luego de respaldarlo y revocar el token en Laravel.

## Archivos instalados

```
C:\Program Files\NodoControl\
  Agent\NodoControlAgent.exe       (servicio + consola de control)
  Agent\Notifier\OSGNotifier.exe  (ventana de mensajes, inicia bajo la sesión del usuario)
  Config\NodoControlConfig.exe     (asistente de vinculación, solo admin)
C:\ProgramData\NodoControl\
  agent.json                         (token, UUID, IP/puerto, permisos NTFS)
  telemetry-<UUID>.sqlite            (cola local de la identidad actual)
  telemetry.sqlite                   (historial legado si existía)
  logs\agent.log                    (logs rotativos)
```

## Seguridad y límites

- Modo HTTP: solo IPv4 privada RFC1918 y consentimiento explícito del operador al elegir HTTP. **HTTP no cifra tokens ni órdenes**. Limitar el acceso LAN mediante reglas del firewall y redes confiables. HTTPS recomendado en redes no controladas.
- No se abren puertos entrantes en Windows; el agente consulta solamente su servidor configurado.
- Token protegido en carpeta de datos con DACL NTFS para SYSTEM y Administradores. No se guarda la ID de un solo uso en disco.
- Se ejecuta bajo `LocalSystem`; el cierre de procesos requiere una orden autenticada, PID/nombre/fecha de inicio coincidentes y respeta la lista de procesos críticos protegidos. El sistema operativo determina si autoriza la operación. No se ejecutan comandos arbitrarios.
- La selección de ID nueva no permite sobrescribir credenciales sin aprobación del servidor. Una ID consumida puede agotarse si el equipo sufre un fallo local después de que el servidor la acepta; el asistente lo reporta sin sacrificar las credenciales anteriores.
- El agente no realiza speedtests, barridos masivos ni escaneos automáticos de la LAN.

## Validaciones pendientes en Windows

La compilación y el comportamiento con servicios reales requieren QA en Windows 10 y 11 x64. Es imprescindible verificar, **en una LAN de pruebas**, instalación limpia, UAC, inicio automático al reiniciar sin abrir sesión, cambios con código inválido, cambios con código válido, ACLs de ProgramData, recuperación de conexión y apagado/cierre de procesos autorizados. **No ejecutar dos agentes simultáneamente** hasta investigar el incidente anterior del router.


## Cambios operativos v5.3

- Se conserva la ID existente y se actualiza el servicio mediante el mismo `AppId` de Inno Setup; para actualizar **no hace falta generar otra ID**.
- La GUI del asistente se denomina **OSG Agent** y presenta discretamente «Creado por Ignacio Sanguina».
- El notificador `OSGNotifier.exe` no es un servicio ni permanece abierto: se inicia en sesiones Windows activas solo cuando hay un mensaje, con botón **Cerrar**.
- Para mensajes, usar la función del panel Laravel **Mostrar mensaje**. El servidor confirma la **solicitud de apertura**, no que el usuario leyó o cerró el mensaje.
- No se coloca ningún icono en la bandeja del sistema para el servicio.
- Para probar cierre de procesos, iniciar sesión con un usuario normal, esperar la siguiente muestra de procesos (hasta 60 segundos) y comprobar `can_terminate`. El envío de órdenes no depende de una estimación anticipada del SID o sesión. Se comprueba PID/nombre/hora de inicio y Windows decide los permisos al finalizar. Revisar `C:\ProgramData\NodoControl\logs\agent.log`.
- El sondeo de USB/CD se ejecuta cada 20 segundos; no se examinan archivos del usuario. Los eventos se guardan en SQLite y se envían al servidor en la próxima conexión. No se garantiza detectar conexiones fugaces menores que el sondeo.


## Actualización v5.4: confirmación y medios

La v5.4 mantiene `NodoControlAgent` y el `AppId`; se actualiza sobre la
instalación previa sin regenerar la ID. Requiere **recompilar** los tres EXE
utilizando `installer/build-windows.ps1` y ejecutar el instalador v5.4. El
notificador solo devuelve código de salida 0 después de pulsar **Cerrar**:
el servicio espera la finalización para registrar el resultado. Un cierre
inesperado, una sesión sin acceso o una interrupción del servicio no cuentan
como lectura confirmada. La confirmación es de interacción, no una garantía
de que una persona haya leído el texto.

Se incorpora `windows_media_events.py` para recibir eventos de volumen de
Windows en un hilo, con cola SQLite y sondeo de reconciliación. Los dispositivos
sin letra de unidad y los eventos que ocurren mientras el servicio no funciona
pueden no registrarse. **Validar en Windows 10 y 11 reales** los mensajes, la
extracción USB rápida, DVD y las sesiones interactivas antes de utilizarlo para
auditorías empresariales. Las pruebas unitarias no sustituyen esas verificaciones.

## Diagnósticos v6.0.1

La actualización conserva la vinculación y activa el hilo `NodoDiagnostics` independiente. Recoge hardware, batería, indicadores de discos, Defender, firewall, catálogo de Windows Update y eventos críticos seleccionados. Usa consultas PowerShell/WMI con límites de tiempo y SQLite local para los eventos mientras Laravel está desconectado. No monitoriza descargas personales, ventanas activas ni capturas de pantalla. La documentación completa está en `docs/DIAGNOSTICOS_V6.md` del proyecto Laravel.


### Actualización segura v6.0.1

Antes de reemplazar archivos, Inno Setup solicita detener `NodoControlAgent` y verifica el estado real mediante PowerShell. Si no se detiene en 30 segundos, el instalador cancela la instalación. Mantener disponible el instalador anterior y respaldar la carpeta ProgramData con el servicio detenido. No se ha probado el instalador ni la reversión automática en Windows.

## Vinculación desde el panel (v6.1.0)

Tras instalar OSG Nodo Agent, abrir el asistente **Configurar OSG Agent**, ingresar la IP privada del servidor y pulsar **Solicitar vinculación**. Windows mostrará un código de 6 dígitos. El administrador deberá ingresar ese código desde **Equipos → Agregar o administrar equipos** en Laravel. El agente consulta la aprobación y configura automáticamente el servicio, sin copiar un código de 48 caracteres.

Se conserva el método tradicional «Usar código clásico» para instalaciones anteriores. No borrar `C:\ProgramData\NodoControl` durante la actualización.
