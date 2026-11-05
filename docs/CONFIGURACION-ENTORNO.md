# Configuración del servidor: archivos de entorno e IA

Esta guía se refiere al equipo donde instalaste **OSG Nodo Control (Laravel)**.
Los agentes Windows tienen su propia configuración de vinculación; no llevan
las claves de los proveedores de IA.

## Qué archivo se utiliza

| Archivo | Para qué sirve | Cómo se prepara |
| --- | --- | --- |
| `.env.example` | Plantilla pública, con valores iniciales y ejemplos comentados | Se distribuye con el código. No contiene credenciales reales. |
| `.env` con Docker | Configuración de Compose: dirección de publicación, puertos y proyecto | Lo genera `bash scripts/docker/setup.sh`. |
| `secrets/laravel.env` con Docker | Configuración privada que Laravel lee dentro de los contenedores | Lo genera el instalador a partir de la plantilla y lo adapta al despliegue. |
| `.env` sin Docker | Configuración privada de Laravel instalado directamente en el servidor | En una instalación nueva se copia la plantilla y se completan sus valores. |

**Modificar `.env.example` no actualiza una instalación existente.** No copies
la plantilla encima del `.env` de Compose ni reemplaces una configuración
privada existente: podrías perder APP_KEY, claves o ajustes de red.

La palabra «local» tiene significados distintos según la opción:

| Ajuste | Qué significa |
| --- | --- |
| `APP_ENV=local` | Entorno de desarrollo de Laravel; no habilita ni bloquea la LAN. |
| `REPORT_AI_PROVIDER=local` | Inferencia mediante un servidor como Ollama, accesible en el entorno de Laravel. |
| Dirección `127.0.0.1` | Acceso al propio equipo o contenedor desde el que se realiza la conexión. |

## Si utilizas Docker

En una instalación nueva ejecuta:

```bash
bash scripts/docker/setup.sh
```

No tienes que completar manualmente toda la plantilla. El instalador genera
APP_KEY y los dos archivos privados, configura SQLite dentro de su volumen y
ajusta dirección, entorno de producción y registros.

Desde la carpeta del proyecto, en la terminal Linux o Debian WSL2 del servidor:

```bash
python3 scripts/docker/configure.py name
python3 scripts/docker/configure.py api
python3 scripts/docker/configure.py network
```

Ejecuta solo el comando correspondiente al cambio deseado. `api` configura
proveedor **externo**, modelo y clave con confirmación y comprobación del catálogo.
El asistente no ofrece el modo `local` de Ollama.

Para una variable avanzada que no exponga el asistente, edita
`secrets/laravel.env` con un editor en el servidor. Por ejemplo, los límites de
telemetría o el ejecutable PDF pertenecen a ese archivo, no al `.env` de Compose.
El nombre y contraseña del administrador se guardan en SQLite y se administran
con `docker compose exec app php artisan nodo:admin`, no con variables de entorno.

Tras guardar cambios en la configuración de Laravel, y con Docker Engine activo:

```bash
docker compose up -d --force-recreate --no-build --wait --wait-timeout 180
```

Con HTTPS, incluye también `-f compose.yaml -f compose.https.yaml`, como explica
la [guía Docker](../DOCKER-LINUX-WINDOWS.md). Un simple reinicio puede conservar
el montaje de la versión anterior de un archivo reemplazado: utiliza la
recreación indicada.

## Si instalaste Laravel directamente, sin Docker

Solo para una instalación nueva y desde la raíz del proyecto:

```bash
cp .env.example .env
php artisan key:generate
```

Completa ese `.env` privado. Usa la dirección real del panel en APP_URL y una
ruta absoluta si defines DB_DATABASE; sin esa variable, la aplicación utiliza
`database/database.sqlite`. Este paso no instala PHP, dependencias, base de
datos ni servicios: supone un servidor Laravel ya preparado.

Conserva APP_KEY al actualizar. Tras editar el archivo:

```bash
php artisan config:clear
```

Reinicia los procesos persistentes de PHP, colas y scheduler mediante el gestor
de servicios que uses. Los informes necesitan WeasyPrint en el entorno del
worker y un proceso para la cola `database/reports`:

```bash
php artisan queue:work database --queue=reports --tries=1 --timeout=180
```

## Ejemplo de IA local con Ollama, sin Docker

Este ejemplo presupone **Laravel y Ollama ejecutados directamente en el mismo
servidor**, de modo que Laravel y su worker puedan llegar a `127.0.0.1:11434`.
Instala Ollama siguiendo su [documentación oficial](https://docs.ollama.com/quickstart)
y comprueba que esté en ejecución. Descarga un modelo como
[qwen3:8b](https://ollama.com/library/qwen3:8b); este nombre es un ejemplo
con soporte de herramientas, no una garantía de rendimiento en tu equipo:

```bash
ollama pull qwen3:8b
ollama list
curl --fail http://127.0.0.1:11434/v1/models
```

Usa el nombre exacto mostrado por `ollama list`. En el `.env` privado de Laravel,
sustituye los valores existentes de proveedor y modelo por:

```dotenv
REPORT_AI_PROVIDER=local
REPORT_AI_MODEL=qwen3:8b
REPORT_AI_LOCAL_URL=http://127.0.0.1:11434/v1
NODO_ASSISTANT_ENABLED=true
```

No agregues una segunda definición de las mismas variables. El adaptador local
de esta aplicación no envía una clave API a Ollama, de modo que no necesitas
poner `ollama` como clave de OpenAI. Las claves de Groq, OpenAI o proveedor
compatible no se utilizan mientras `REPORT_AI_PROVIDER=local`.

OSG utiliza la API [compatible con OpenAI de Ollama](https://docs.ollama.com/api/openai-compatibility)
con base `/v1`, no la base nativa `/api`. El asistente necesita
[tool calling](https://docs.ollama.com/capabilities/tool-calling); las conclusiones
PDF necesitan salida JSON. Comprueba ambas funciones con el modelo seleccionado.
El modelo puede requerir recursos y tiempos de respuesta adicionales; su consumo
no está incluido en los límites de los servicios Docker de OSG.

Después de editar, limpia la caché y reinicia los procesos como se indicó para
Laravel nativo. El asistente se habilita con NODO_ASSISTANT_ENABLED. Para los PDF,
activa además **Configuración → Informes PDF → Conclusión y recomendaciones
mediante IA** y guarda. Esta opción se almacena en SQLite de forma independiente.

## Qué ocurre con Ollama y la composición Docker actual

El ejemplo anterior **no conecta automáticamente Laravel en Docker con Ollama
del anfitrión**:

- Dentro de `app`, `127.0.0.1` corresponde al contenedor `app`.
- Dentro de `worker`, corresponde al contenedor `worker`.
- El Compose actual no incluye un servicio Ollama ni una conexión local
  compartida con esos dos procesos.
- La validación actual de `REPORT_AI_LOCAL_URL` acepta solo HTTP de loopback
  con ruta `/v1`. Rechaza `host.docker.internal`, una IP de LAN o `http://ollama:11434/v1`.

Por tanto, cambiar la URL del archivo privado no basta para utilizar Ollama del
anfitrión. Esa topología requiere una integración adicional de red y validación
para que ambos servicios alcancen el modelo. Esta guía no habilita ni publica
el puerto de Ollama en la LAN. El [FAQ de Ollama](https://docs.ollama.com/faq)
explica sus opciones de escucha, pero cambiar OLLAMA_HOST por sí solo no modifica
las restricciones del adaptador de OSG.

## Activación, retención y prioridad de ajustes

- Las conclusiones de PDF y el asistente comparten proveedor/modelo, pero sus
  controles de habilitación son independientes.
- La zona REPORT_TIMEZONE es el valor inicial de presentación; una zona guardada
  desde el panel tiene prioridad. Los instantes originales se almacenan en UTC.
- Los intervalos y la retención efectiva de CPU/RAM se ajustan en el panel.
  METRICS_RETENTION_DAYS se conserva en la plantilla por compatibilidad y no
  reemplaza esa preferencia del mantenimiento actual.
- Las claves vacías en la plantilla no son credenciales utilizables. Los nombres
  de modelo de ejemplo deben existir en el proveedor o instalación elegidos.
- Conserva la configuración privada y APP_KEY en respaldos protegidos. No los
  adjuntes a issues ni los subas al repositorio.
