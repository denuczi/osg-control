# Pruebas del empaquetado Docker

Ejecuta estos comandos desde la raíz del repositorio. Las pruebas estáticas y
las de integración comprueban aspectos distintos; una compilación correcta por
sí sola no demuestra que el despliegue funcione.

## Comprobaciones sin Docker Engine

En Debian, instala PyYAML para las pruebas de configuración:

```bash
sudo apt-get install python3-yaml
python3 -m unittest discover -s tests/docker -v
for script in scripts/docker/*.sh; do bash -n "$script" || exit 1; done
php -l docker/runtime/sqlite-backup.php
php -l tests/docker/runtime-smoke.php
node --check docker/frontend/ensure-rolldown.mjs
```

PHP y Node solo son necesarios en el anfitrión para las comprobaciones de
sintaxis anteriores. Las pruebas Python necesitan Python 3 y PyYAML; crean
configuraciones temporales y no modifican las credenciales de la instalación.
Comprueban la configuración, los límites de memoria declarados, los montajes,
el escape de variables de Compose y los permisos de inicialización.
Las pruebas de configuración IA simulan el catálogo y los errores de los
proveedores: no usan claves reales ni hacen solicitudes de red. Comprueban
confirmación, cancelación y conservación de archivos ante errores.

El comando de administrador tiene pruebas específicas con SQLite en memoria.
Con las dependencias de desarrollo de Composer instaladas y PHP disponible:

```bash
vendor/bin/phpunit tests/Feature/CreateAdminCommandTest.php
```

Comprueban creación, actualización, cancelación, contraseñas diferentes,
validación y cambios concurrentes sin utilizar cuentas de la instalación real.

## Integración con Docker

Requiere una instalación configurada según
[DOCKER-LINUX-WINDOWS.md](../../DOCKER-LINUX-WINDOWS.md).

```bash
docker compose config --quiet
bash scripts/docker/build-check.sh
docker compose up -d --no-build --wait --wait-timeout 180
bash scripts/docker/doctor.sh
docker compose exec -T worker php < tests/docker/runtime-smoke.php
```

`doctor.sh` verifica las migraciones de la base persistente, el manifiesto de
Vite, WeasyPrint, la creación de un PDF temporal y las respuestas de `/up` y
`/login`. No crea usuarios ni dispositivos.

`runtime-smoke.php` cambia la conexión a SQLite en memoria antes de ejecutar
migraciones y escribir datos de prueba. Comprueba:

- Todas las migraciones con la versión PHP/SQLite de la imagen.
- El heartbeat de un agente mediante el kernel HTTP y autenticación reales.
- Un trabajo de la cola `database/reports` y su PDF de dispositivo.
- La generación del PDF de inventario consolidado.

La prueba utiliza las bibliotecas y las plantillas de la aplicación. Los PDF
temporales se escriben en el volumen de informes y se eliminan al finalizar;
las vistas compiladas pueden quedar en la caché normal de Laravel. No llama a
proveedores de IA ni registra datos en la base persistente. Una interrupción
forzada del proceso puede dejar archivos temporales.

## Recreación del servicio PHP

En una instalación de prueba, comprueba que Nginx pueda volver a resolver el
servicio PHP después de recrearlo:

```bash
docker compose up -d --no-build --no-deps --force-recreate --wait --wait-timeout 180 app
bash scripts/docker/doctor.sh
```

El DNS tiene una caché de hasta diez segundos. Puede haber respuestas 502
durante el reemplazo; el servicio debe recuperarse sin reiniciar Nginx.

## Alcance y resultados para reportar

Al abrir un issue o un pull request, indica el sistema operativo, la
arquitectura, las versiones de Docker y Compose, el commit probado y los
comandos ejecutados. Adjunta registros relevantes sin credenciales ni datos
personales. No guardes registros locales ni mediciones de una sesión como
resultados permanentes del repositorio.

Las pruebas anteriores no sustituyen la suite Laravel ni las pruebas del
agente. El heartbeat se procesa dentro del contenedor: para validar la LAN es
necesario vincular un agente desde otra computadora. WSL2, TLS, proveedores de
IA y memoria bajo carga requieren comprobaciones específicas en sus entornos.
