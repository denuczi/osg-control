# Docker Engine en Linux y Windows 10/11 (WSL2)

Esta guía despliega el servidor de OSG Nodo Control con Docker Engine y el
complemento Docker Compose. En Windows usa Debian dentro de WSL2. Docker
Desktop y XAMPP no son necesarios. macOS no está cubierto.

El agente se instala por separado en cada computadora administrada. Consulta
[agent/installer/README.md](agent/installer/README.md).

## 1. Requisitos y arquitectura

En Linux o dentro de Debian WSL2 necesitas:

- Docker Engine en ejecución y acceso al daemon con tu usuario.
- El complemento Compose actual, con soporte de `up --wait`, `mem_limit` y
  dependencias por salud/finalización; no `docker-compose` v1.
- Git, Python 3, curl, tar y sha256sum para instalación y administración.
- Internet para descargar imágenes y dependencias durante la compilación.
- Espacio para imágenes, caché de construcción, SQLite, informes y respaldos.

PHP, Composer, Node y WeasyPrint se ejecutan dentro de las imágenes; no necesitas
instalarlos en el anfitrión para desplegar el servidor.

| Servicio | Función | Límite de RAM |
| --- | --- | --- |
| `init` | Preparar volúmenes y migraciones; después termina | 768 MiB |
| `app` | Laravel con PHP-FPM 8.4 | 2.048 MiB |
| `web` | Nginx y Vue compilado con Node 24/Vite | 192 MiB |
| `worker` | Cola `reports` y PDF con WeasyPrint | 1.536 MiB |
| `scheduler` | Tareas programadas | 384 MiB |

Los límites permanentes suman 4.160 MiB. No representan consumo medido ni
aseguran que el anfitrión completo use menos de 5 GB: Docker, el kernel, la
compilación y el perfil TLS consumen memoria adicional.

## 2. Preparar Linux

Instala Docker Engine, Buildx y Compose con la
[guía oficial de Debian](https://docs.docker.com/engine/install/debian/).
Para otras distribuciones, usa sus instrucciones correspondientes de Docker.

En Debian, instala las herramientas auxiliares y comprueba el servidor:

```bash
sudo apt-get update
sudo apt-get install git python3 curl tar coreutils
docker compose version
docker info
```

Si aparece `permission denied` en `/var/run/docker.sock`, puedes habilitar tu usuario:

```bash
sudo groupadd --force docker
sudo usermod -aG docker "$USER"
newgrp docker
docker info
```

El grupo `docker` concede privilegios equivalentes a administrador. `newgrp`
abre una shell con el grupo activo; también puedes cerrar completamente la
sesión y volver a entrar. Consulta los
[pasos posteriores a la instalación](https://docs.docker.com/engine/install/linux-postinstall/).
Si el daemon está detenido, comprueba `sudo systemctl status docker` y arráncalo
con `sudo systemctl start docker`.

## 3. Preparar Windows con WSL2

Desde PowerShell como administrador:

```powershell
wsl --install -d Debian
wsl --update
wsl --list --verbose
```

Debian debe figurar con versión `2`. Completa la creación del usuario Linux.
Si tu versión de Windows no admite estos comandos, consulta la
[instalación oficial de WSL](https://learn.microsoft.com/en-us/windows/wsl/install).

Combina estas opciones con el contenido existente de
`%USERPROFILE%\.wslconfig`, sin sobrescribir otras preferencias:

```ini
[wsl2]
memory=5GB
processors=2
swap=1GB
```

Hay una plantilla en [scripts/docker/wslconfig.example](scripts/docker/wslconfig.example).
El límite se comparte entre las distribuciones WSL2 y debe probarse bajo la
carga prevista. Aplica los cambios desde PowerShell:

```powershell
wsl --shutdown
wsl -d Debian
```

Dentro de Debian, comprueba que systemd esté disponible. Si necesitas activarlo,
agrega o combina estas opciones en `/etc/wsl.conf`:

```ini
[boot]
systemd=true
```

Reinicia WSL con `wsl --shutdown` desde PowerShell y vuelve a abrir Debian.
Consulta los [requisitos de systemd en WSL](https://learn.microsoft.com/en-us/windows/wsl/systemd).
Después sigue la preparación de Linux dentro de Debian. Guarda el proyecto en
`/home/tu-usuario`, evitando `/mnt/c` por los permisos y el rendimiento.

## 4. Descargar e instalar

En Linux o en la terminal Debian WSL2:

```bash
git clone https://github.com/denuczi/osg-control.git
cd osg-control
bash scripts/docker/setup.sh
```

Ejecuta los demás comandos desde esta carpeta. Si ya descargaste el repositorio,
entra en la carpeta existente en lugar de clonarlo de nuevo.

En una instalación nueva, el asistente solicita el nombre de la aplicación,
genera APP_KEY y crea la configuración. Construye las imágenes, ejecuta las
migraciones y espera la disponibilidad de los servicios antes de solicitar
nombre, correo y contraseña del administrador. La contraseña necesita al menos
12 caracteres.

Abre **http://127.0.0.1:8088/login** en el servidor. En Windows, prueba esa misma
dirección desde el navegador del anfitrión. El acceso inicial solo se publica
en loopback; los agentes de otras computadoras necesitan LAN o HTTPS.

`docker compose ps -a` debe mostrar `app`, `web`, `worker` y `scheduler` en
ejecución, con `app` y `web` saludables. Es normal que `init` termine con
`Exited (0)`. Si interrumpiste la creación del administrador, repite solo ese paso:

```bash
docker compose exec app php artisan nodo:admin
```

No se importan datos automáticamente desde otra instalación. Si existe `.env`
pero no `secrets/laravel.env`, el instalador se detiene para evitar sobrescribir
configuración. Conserva los datos y APP_KEY originales y prepara la migración
en una copia separada; no borres `.env` para saltarte la comprobación.

## 5. Configuración y administración

| Archivo o volumen lógico | Contenido |
| --- | --- |
| `.env` | Dirección y puertos de Compose |
| `secrets/laravel.env` | Configuración Laravel, APP_KEY y claves de IA |
| `osg-db` | SQLite y sus archivos WAL |
| `osg-storage` | PDF, sesiones, cachés y registros de Laravel |

Compose agrega el prefijo del proyecto a los nombres físicos de los volúmenes.
No cambies el nombre del proyecto en una instalación existente sin planificar
el traslado de esos volúmenes.

Ejecuta únicamente el comando correspondiente al cambio que necesitas en la
**terminal del equipo donde instalaste el servidor Laravel, fuera de los
contenedores**: Linux o Debian WSL2,
desde la carpeta que contiene `compose.yaml`. Los scripts Python necesitan
Python 3 en ese anfitrión, pero no necesitan Docker Engine en marcha. El modo
`api` necesita conexión HTTPS al proveedor para comprobar el modelo:

```bash
python3 scripts/docker/configure.py name
python3 scripts/docker/configure.py api
python3 scripts/docker/configure.py network
```

`api` permite configurar Groq, OpenAI o un endpoint compatible. Los pasos
detallados y el alcance de la opción `ninguna` se explican más abajo.

Después de cambiar configuración, recrea los servicios para actualizar montajes
y variables:

```bash
docker compose up -d --force-recreate --no-build --wait --wait-timeout 180
```

APP_KEY se conserva. Perderla puede impedir descifrar información o sesiones.
No publiques `secrets/`, archivos `.env` ni respaldos. Git y el contexto de
construcción los excluyen: conserva esas exclusiones.

### Proveedor, modelo y clave de IA

**Lugar de ejecución:** terminal del equipo que aloja el servidor Laravel,
en Linux o Debian WSL2,
fuera de los contenedores y desde la raíz del proyecto. Ejecuta el script
directamente con Python 3 del anfitrión. La configuración inicial debe existir;
los contenedores pueden estar encendidos o apagados. Este paso no requiere
Docker Engine en marcha:

```bash
python3 scripts/docker/configure.py api
```

El asistente solicita:

1. **Proveedor:** `groq`, `openai`, `compatible` o `ninguna`.
2. **Modelo:** el identificador exacto de un modelo de tu proveedor. Los valores
   propuestos se comprueban con el catálogo antes de guardar, igual que los
   introducidos manualmente.
3. **Endpoint HTTPS:** solo para `compatible`, con el formato que indica el
   asistente. Debe ser una dirección pública admitida por la validación.
4. **Clave API:** se introduce sin eco en la terminal.

Después muestra un resumen con la clave oculta y pregunta si deseas verificar
y guardar. **Enter o una respuesta negativa cancelan sin modificar archivos ni
contactar al proveedor**. Al confirmar consulta el catálogo `GET /models` mediante
HTTPS con esa clave; no envía una consulta de generación. Si el identificador no
está disponible o la comprobación falla por autenticación, red, TLS, redirección
o respuesta inválida, se conservan los archivos anteriores. Corrige los datos y
vuelve a ejecutar el asistente.

Algunos proveedores compatibles no exponen ese catálogo. Solo si devuelven HTTP
404/405 se ofrece guardar sin verificar, escribiendo `GUARDAR_SIN_VERIFICAR`.
Esta excepción no demuestra que el modelo o la generación funcionen. Encontrar
un modelo en el catálogo tampoco acredita cuotas, saldo o acceso a todas sus
funciones. Las API consultadas están documentadas por
[Groq](https://console.groq.com/docs/models) y
[OpenAI](https://developers.openai.com/api/reference/resources/models/methods/list).

La configuración se guarda en `secrets/laravel.env`:

| Dato | Variable |
| --- | --- |
| Proveedor | `REPORT_AI_PROVIDER` |
| Modelo | `REPORT_AI_MODEL` |
| Clave de Groq | `GROQ_API_KEY` |
| Clave de OpenAI | `OPENAI_API_KEY` |
| Clave y URL compatibles | `REPORT_AI_COMPATIBLE_KEY`, `REPORT_AI_COMPATIBLE_URL` |
| Habilitación del asistente conversacional | `NODO_ASSISTANT_ENABLED` |

Utiliza el asistente para cambiar estos valores. `.env` en la raíz está destinado
a Compose; el panel web no tiene un formulario para guardar las claves API.
Después de configurar proveedor, modelo o clave, **inicia Docker Engine si está
detenido**. Desde esa misma terminal del anfitrión y carpeta del proyecto,
recrea los servicios para que lean el archivo actualizado:

```bash
docker compose up -d --force-recreate --no-build --wait --wait-timeout 180
```

Con HTTPS, incluye ambos archivos Compose como en la sección de TLS.

Hay dos funciones con controles diferentes:

- **Asistente conversacional:** el script lo habilita al configurar un proveedor.
  El panel permite ajustar sus límites en **Configuración → Rendimiento del
  asistente IA**, pero ese formulario no cambia proveedor, modelo ni clave.
- **Conclusiones IA de los PDF:** actívalas en **Configuración → Informes PDF →
  Conclusión y recomendaciones mediante IA** y pulsa **Guardar configuración**.
  Esta preferencia se guarda en la base de datos y requiere credenciales
  configuradas. No necesita recrear contenedores al cambiarla desde el panel.

Elegir `ninguna` en el script cambia `NODO_ASSISTANT_ENABLED` a `false` después
de una confirmación con No por defecto y conserva las credenciales anteriores.
Para dejar de usar IA también en los PDF,
desactiva la opción de conclusiones desde el panel. Un estado de «Credenciales
configuradas» indica que existen los valores requeridos; no acredita generación
correcta ni cuotas disponibles. Comprueba la integración con una consulta o informe.

### Cuentas, nombre visible y contraseña del panel

La cuenta del panel es independiente del usuario Linux, de la cuenta Windows
y de las credenciales del proveedor de IA. Inicia sesión con correo electrónico
y contraseña, no con el nombre visible del administrador.

Para crear una cuenta o cambiar el nombre y contraseña de una existente,
escribe el siguiente comando en la terminal del anfitrión, desde la carpeta
del proyecto. Docker Engine y `app` deben estar en ejecución; `docker compose
exec` se encarga de ejecutar PHP dentro del contenedor:

```bash
docker compose exec app php artisan nodo:admin
```

El comando solicita nombre, correo y contraseña. Avisa si creará una cuenta
nueva o actualizará una existente. La contraseña debe tener al menos 12 caracteres
y hasta 72 bytes (límite de bcrypt); debe repetirse y no se muestra al introducirla.
Muestra un resumen de acción, nombre y correo, y pide confirmación con **No por
defecto** antes de escribir. Si cancelas o las contraseñas no coinciden, no cambia
la cuenta. Rechaza la ejecución no interactiva. Si otra operación modificó la
cuenta mientras respondías, se detiene para que revises los datos nuevamente.

- **Mismo correo:** actualiza el nombre y la contraseña de esa cuenta.
- **Correo diferente:** crea otra cuenta administradora; la cuenta anterior
  sigue activa. No sirve para cambiar el correo de una cuenta existente ni
  para revocar su acceso.
- **Cambiar de cuenta al navegar:** cierra sesión y entra con las credenciales
  de la otra cuenta.

Las cuentas y los hashes de contraseña se guardan en SQLite. Actualmente no hay
un formulario web de gestión de usuarios, un comando específico para cambiar
el correo o eliminar una cuenta, ni recuperación por correo. Para recuperar
acceso a una cuenta existente, repite `nodo:admin` con su mismo correo. Cambiar
sus credenciales no requiere reconstruir imágenes ni recrear servicios.

`configure.py name` cambia el nombre de la aplicación, no el de la cuenta.

## 6. Acceso desde la LAN y HTTPS

### HTTP para laboratorio

Ejecuta `python3 scripts/docker/configure.py network`. El menú distingue:

- **1 — Solo desde este mismo servidor:** permite abrir el panel en esa
  computadora; los agentes de otros equipos no pueden conectarse.
- **2 — Desde otras computadoras de tu red (LAN):** permite acceder al panel
  y conectar agentes desde otros equipos de la red, sujetos al firewall.

Para conectar tus agentes Windows, elige **2**. La opción propuesta al pulsar
Enter conserva el tipo de acceso configurado actualmente. Las respuestas
anteriores `local` y `lan` siguen aceptándose como equivalentes de 1 y 2.

Confirma `ACEPTO_HTTP_LAN` solo si aceptas transmitir credenciales y datos sin
cifrado en esa red. Indica una IP o nombre del servidor que los agentes puedan
alcanzar: `127.0.0.1` en un agente apunta al propio agente.

En Linux, permite el puerto elegido únicamente desde la red prevista. Revisa
las [consideraciones de firewall de Docker](https://docs.docker.com/engine/install/debian/#firewall-limitations):
publicar un puerto puede eludir reglas de herramientas como ufw.

En Windows con WSL2/NAT, después de habilitar LAN dentro de Debian, ejecuta el
script desde PowerShell como administrador. Puedes copiar este archivo a una
carpeta de Windows y ejecutar allí el siguiente comando:

```powershell
.\windows-lan.ps1 -Port 8088 -AllowInsecureHttp
```

El archivo está en [scripts/docker/windows-lan.ps1](scripts/docker/windows-lan.ps1).
Usa la distribución llamada `Debian`, crea una redirección `portproxy` y una
regla de firewall para perfil privado y subred local. Requiere el servicio
IP Helper de Windows. La IP de WSL puede cambiar al reiniciarlo: vuelve a
correr el script. `-ReplaceExisting` permite reemplazar una redirección después
de revisarla. Si cambiaste el puerto, usa ese valor. Para otras configuraciones,
consulta la [red de WSL](https://learn.microsoft.com/en-us/windows/wsl/networking).

### HTTPS con certificado propio

`compose.https.yaml` agrega Caddy en el puerto 8443. No obtiene ni renueva
certificados automáticamente. Coloca un certificado válido para el nombre o
IP de los clientes en `secrets/tls/fullchain.pem` y su clave privada en
`secrets/tls/privkey.pem`.

```bash
python3 scripts/docker/configure.py tls
docker compose -f compose.yaml -f compose.https.yaml up -d --no-build --force-recreate --wait --wait-timeout 180
```

El asistente mantiene HTTP en loopback y activa cookies seguras. Utiliza ambos
archivos Compose en las operaciones posteriores que deban incluir TLS. En WSL2,
la exposición del puerto TLS a la LAN requiere una regla propia:
`windows-lan.ps1` está limitado al laboratorio HTTP.

## 7. Actualizaciones, datos y respaldos

Antes de actualizar, respalda los datos y conserva la versión anterior del código:

```bash
bash scripts/docker/backup.sh
git pull --ff-only
bash scripts/docker/build-check.sh
docker compose up -d --no-build --force-recreate --wait --wait-timeout 180
bash scripts/docker/doctor.sh
```

Con TLS, incluye `-f compose.yaml -f compose.https.yaml` en el arranque. `init`
ejecuta las migraciones. Volver a una imagen anterior no revierte una migración:
la recuperación puede requerir restaurar también la base de datos.

`docker compose stop` detiene los servicios. `docker compose down` elimina
contenedores y red, conservando volúmenes. **`docker compose down -v` elimina
los datos persistentes**; no lo uses para actualizar.

El respaldo se guarda en una carpeta privada de `backups/` con `database.sqlite`,
`storage.tar.gz`, `laravel.env`, `compose.env` y `SHA256SUMS`. Usa la API de
respaldo de SQLite para obtener una instantánea consistente con WAL activo.
La copia de informes no es atómica respecto de la base. Para evitar escrituras
concurrentes, respalda durante una ventana de mantenimiento, sin solicitudes
de usuarios y con worker y scheduler detenidos. Copia el respaldo a otro
almacenamiento protegido: contiene credenciales.

No hay restauración automatizada. Restaura primero en una instalación separada,
con el mismo código y volúmenes vacíos: verifica los checksums, recupera ambos
archivos de configuración conservando APP_KEY, coloca `database.sqlite` en el
volumen SQLite y extrae `storage.tar.gz` en el de almacenamiento. Los procesos
PHP usan UID/GID `33:33`; verifica propietarios y permisos antes de arrancar.
No copies solo el archivo principal de una base activa ni mezcles un respaldo
con journals WAL de otra ejecución. Valida migraciones, inicio de sesión e
informes antes de sustituir la instalación original.

## 8. Diagnóstico, dependencias y contribuciones

```bash
docker compose ps -a
docker compose logs --tail=100 init app web worker scheduler
bash scripts/docker/doctor.sh
docker stats --no-stream
```

Para guardar el registro completo conservando el código de salida en Bash:

```bash
set -o pipefail
bash scripts/docker/setup.sh 2>&1 | tee /tmp/osg-setup.log
```

Si el puerto está ocupado, cámbialo con `configure.py network`. Ante un error,
conserva la etapa que falló y las últimas líneas; no elimines volúmenes para
resolver una compilación. Quita credenciales y datos personales antes de
compartir registros.

El repositorio incluye `composer.lock` y `package-lock.json`. La imagen instala
las versiones fijadas y usa `npm ci`. Las etiquetas de las imágenes base pueden
cambiar: los locks no garantizan una imagen idéntica byte por byte. Actualiza
los locks junto con las dependencias y repite las pruebas. `freeze-locks.sh`
recupera locks de imágenes construidas a partir de fuentes que carecen de ambos
archivos; se niega a sobrescribir los existentes.

Para exportar las imágenes locales a otra máquina con arquitectura compatible:

```bash
bash scripts/docker/export-images.sh
```

En el destino verifica el archivo `.sha256` y carga el TAR con `docker load`.
La exportación no incluye datos ni configuración: también necesitas el
repositorio, sus archivos Compose y una configuración propia. Arranca con
`docker compose up -d --no-build` para utilizar las imágenes cargadas.

Las [pruebas Docker](tests/docker/README.md) cubren configuración e integración
de API, cola y PDF con SQLite en memoria. Una prueba Linux local no certifica
Windows, LAN, TLS, proveedores de IA ni memoria bajo carga. Al contribuir,
indica el entorno y el commit comprobados en el issue o pull request.
