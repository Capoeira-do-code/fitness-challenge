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

## Variables importantes

En `docker-compose.yml`:

- `DB_PATH`
- `CHALLENGE_START`
- `CHALLENGE_END`
- `SEED_PASSWORD`
- `APP_TIMEZONE`
- `APP_DEFAULT_LOCALE`

`DB_PATH` permite usar una base alterna (por ejemplo, para E2E local).
`APP_DEFAULT_LOCALE` acepta `en`, `es` o `it` (por defecto `en`).

## Usuarios iniciales

Se crean automáticamente si la DB está vacía:

- `roberto`
- `catalina`

Contraseña inicial: variable `SEED_PASSWORD` (por defecto `ChangeMe123!`).
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

1. `git pull --rebase`
2. `docker compose up -d --build`

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
./bin/e2e_local.py --no-auto-install-deps
./bin/e2e_local.py --down
```

Detalles:

- `profile=auto` (default): usa `full` si Docker existe; si no, usa `basic`.
- `profile=basic`: no usa Docker. Inicia la UI con `php -S` si no hay servidor ya corriendo en `base-url`.
- `profile=full`: usa Docker para levantar la UI.
- `run-checks`: activa modo runner y genera reporte HTML (en vez de modo serve UI).
- `auto-install-deps` (default `true`): intenta instalar dependencias faltantes (ej. `php`) automáticamente.
- `db-mode` aplica solo en `profile=full`; por defecto `e2e` (usa `storage/fitness_e2e.sqlite`).
- `reset` es destructivo y exige `--force`.
- En `run-checks` + `profile=full`, auto-instala Playwright/chromium si faltan.
- Genera reporte HTML en `e2e-report/latest.html`.
- Devuelve código distinto de cero si fallan tests.

Variables opcionales para credenciales de test:

- `E2E_USER`, `E2E_PASS`
- `E2E_SECOND_USER`, `E2E_SECOND_PASS`

Si no se definen, usa seed users y `SEED_PASSWORD` (o `ChangeMe123!`).

## Notas

- Para exponer en internet, ponlo detrás de reverse proxy con HTTPS.
- Límite upload en Nginx: `15M` por imagen.
