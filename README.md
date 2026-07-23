# Fitness Challenge Tracker

App web para trackear retos fitness entre 2+ personas con `PHP + SQLite + Docker`.

## Incluye

- Login por usuario y sesiones con cookie.
- SQLite en servidor (sin `localStorage` para datos).
- Dashboard con estadísticas, comparativas y gráficas.
- UI responsive estilo fitness app, con navegación móvil y métricas claras.
- Idiomas: inglés, español e italiano (inglés por defecto).
- Sistema de strikes + reducción por semanas perfectas.
- Regla `workout + junk food = 0`.
- Override con `workout extra` (requiere aprobación humana).
- Excepciones de steps/workouts con flujo de aprobación formal.
- Cálculo en tiempo real y recálculo histórico (sin bloqueo semanal).
- Warning de disciplina por 2 días seguidos saltados (sin strike automático).
- Vista tipo Excel editable por día.
- Input rápido de datos (steps, workouts, peso, hábitos).
- Subida/captura de fotos para comidas/entrenos.
- Gestión de usuarios (crear, editar, cambiar reglas y contraseña).

## Stack

- `PHP 8.3` + `PDO SQLite`
- `Nginx`
- `Docker Compose`

## Arranque local o Unraid

```bash
docker compose up -d --build
```

App en: `http://IP_DEL_SERVIDOR:8080`

Puertos publicados por defecto:

- `HTTP_PORT=8080`
- `HTTPS_PORT=8443`

Para LIVE (80/443), usa `.env.live` y `bin/live_manager.py`.

## Variables importantes

En `docker-compose.yml`:

- `DB_PATH`
- `HTTP_PORT`
- `HTTPS_PORT`
- `CHALLENGE_START`
- `CHALLENGE_END`
- `SEED_PASSWORD`
- `APP_TIMEZONE`
- `APP_DEFAULT_LOCALE`
- `MEDIA_SEARCH_GOOGLE_API_KEY`
- `MEDIA_SEARCH_GOOGLE_CX`
- `MEDIA_SEARCH_YOUTUBE_API_KEY`

`DB_PATH` permite usar una base alterna (por ejemplo, para E2E local).
`APP_DEFAULT_LOCALE` acepta `en`, `es` o `it` (por defecto `en`).
`HTTP_PORT` y `HTTPS_PORT` controlan los puertos publicados por Docker.

La búsqueda multimedia del creador de ejercicios es opcional. Puede configurarse
desde **Admin → Entreno y ranked → Proveedores multimedia** o mediante las tres
variables `MEDIA_SEARCH_*`. Las claves solo se usan en PHP, se muestran
enmascaradas en administración y nunca se envían al navegador.

YouTube requiere habilitar [YouTube Data API v3](https://developers.google.com/youtube/v3/getting-started).
Google Imágenes usa [Custom Search JSON API](https://developers.google.com/custom-search/v1/overview)
y el ID `CX` de un Programmable Search Engine con búsqueda de imágenes. Google
cerró esta última API a nuevos clientes y exige que los clientes existentes
migren antes del 1 de enero de 2027, por lo que actualmente solo funciona con
proyectos existentes compatibles. Restringe siempre las claves a sus APIs según
la [guía oficial de seguridad](https://docs.cloud.google.com/api-keys/docs/add-restrictions-api-keys).

## Usuarios iniciales

Solo se crean cuando la DB está vacía y `SEED_PASSWORD` se ha definido expresamente:

- `roberto`
- `catalina`

Contraseña inicial: define expresamente `SEED_PASSWORD`. Sin esa variable no se crean cuentas seed en una base nueva.
Recomendación: entrar con cada usuario y cambiar contraseña desde `Perfil`.

## Reglas implementadas

- Goal de steps por usuario y por días de semana.
- Goal de workouts semanal o por días fijos (`workout_strict`).
- `workout + junk food = 0`.
- Para compensar junk food el mismo día, solo vale `workout extra` **aprobado**.
- Excepciones (`steps`/`workout`) cuentan solo si están **aprobadas**.
- Estado `pending` cuenta como fallo temporal.
- Fallos suman strikes y penalización escalada:
  - strike 1-4: €10
  - strike 5-6: €50
  - strike 7-8: €100
  - strike 9: €200
  - strike >=10: €300
- Reducción de strike: cada 2 semanas completas perfectas resta 1 strike para futuro.
- Warning de disciplina por racha de 2 días saltados (métrica visible, no strike automático).

## Persistencia

Se persisten en host:

- `./storage` (SQLite)
- `./public/uploads` (fotos)

## Update desde GitHub

```bash
./bin/update.sh
```

El script hace:

1. Si existen `bin/live_manager.py` y `.env.live`, delega a `python bin/live_manager.py deploy --env-file .env.live`
2. Fallback legacy: `git pull --rebase` + `docker compose up -d --build`

## Live Manager (provision + deploy + verify)

Script para operacion live estandar con Docker Nginx + PHP-FPM:

```bash
python bin/live_manager.py provision
python bin/live_manager.py deploy
python bin/live_manager.py verify
python bin/live_manager.py runtime
python bin/live_manager.py runtime-status
```

Flujo recomendado en live:

1. `python bin/live_manager.py provision` (genera `.env.live`, valida Docker/Compose)
2. `python bin/live_manager.py deploy` (pull + build + up + healthcheck + verify)

Ejemplo de `.env.live`:

```bash
HTTP_PORT=80
HTTPS_PORT=443
DB_PATH=/var/www/storage/fitness.sqlite
```

## Script local (arranque UI + checks)

Script único:

```bash
./bin/e2e_local.py
```

Por defecto inicia la Web UI.

Opciones:

```bash
./bin/e2e_local.py --profile auto
./bin/e2e_local.py --profile basic
./bin/e2e_local.py --profile basic --base-url http://IP_SERVIDOR:8080
./bin/e2e_local.py --profile full --db-mode e2e
./bin/e2e_local.py --profile full --db-mode live
./bin/e2e_local.py --profile full --db-mode reset --force
./bin/e2e_local.py --run-checks
./bin/e2e_local.py --run-checks --profile full --db-mode e2e
./bin/e2e_local.py --open-mode app --browser chrome --window-size 1280x860 --window-pos 40,40
./bin/e2e_local.py --open-mode popup
./bin/e2e_local.py --open-mode tab
./bin/e2e_local.py --open-mode none
./bin/e2e_local.py --no-auto-install-deps
./bin/e2e_local.py --down
./bin/e2e_local.py --runtime-manager
```

Detalles:

- `profile=auto` (default): usa `full` si Docker existe; si no, usa `basic`.
- `profile=basic`: no usa Docker. Inicia la UI con `php -S` si no hay servidor ya corriendo en `base-url`.
- `profile=full`: usa Docker para levantar la UI.
- `open-mode` (default `app`): abre en ventana app/popup/tab o desactiva apertura automatica.
- `browser`: preferencia de navegador para `app/popup` (`auto|chrome|chromium|edge`).
- `window-size` y `window-pos`: tamano/posicion de ventana en `app/popup`.
- `run-checks`: ejecuta checks rapidos (lint PHP + smoke HTTP + assets) y genera reporte HTML.
- `auto-install-deps` (default `true`): intenta instalar dependencias faltantes (ej. `php`) automáticamente.
- `db-mode` aplica solo en `profile=full`; por defecto `e2e` (usa `storage/fitness_e2e.sqlite`).
- `reset` es destructivo y exige `--force`.
- `down`: apaga stack Docker en full (`docker compose down`).
- `runtime-manager`: abre el wizard de `php_runtime_manager.py`.
- Genera reporte HTML en `e2e-report/latest.html` y `e2e-report/report_YYYYMMDD_HHMMSS.html`.
- Devuelve código distinto de cero si fallan tests.

Variables opcionales para credenciales de test:

- `E2E_USER`, `E2E_PASS`
- `E2E_SECOND_USER`, `E2E_SECOND_PASS`

Las cuentas seed solo se crean cuando `SEED_PASSWORD` está definida expresamente.

## PHP Runtime Manager (instalador + UI dedicada)

Script nuevo para instalar/configurar un runtime PHP mas rapido y generar perfiles `php.ini` optimizados:

```bash
./bin/php_runtime_manager.py
```

Comandos utiles:

```bash
# Wizard interactivo (default)
./bin/php_runtime_manager.py wizard

# Auditar runtime actual
./bin/php_runtime_manager.py audit
./bin/php_runtime_manager.py audit --json

# Imprimir comando recomendado de instalacion de PHP
./bin/php_runtime_manager.py print-install

# Generar php.ini optimizado
./bin/php_runtime_manager.py write-ini --profile balanced

# Arrancar app con perfil optimizado
./bin/php_runtime_manager.py serve --profile balanced --host 0.0.0.0 --port 8080
```

Perfiles disponibles:

- `dev`: debug rapido con recarga frecuente.
- `balanced` (recomendado): equilibrio entre DX y rendimiento.
- `max`: mayor rendimiento local con menor revalidacion de archivos.

## Notas

- Para exponer en internet, ponlo detrás de reverse proxy con HTTPS.
- Límite upload en Nginx: `15M` por imagen.
