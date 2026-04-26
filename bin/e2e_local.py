#!/usr/bin/env python3
"""Local E2E/basic runner for Fitness Challenge Tracker.

Modes:
- Full mode (Docker + Playwright)
- Basic mode (no Docker): static checks + optional local PHP server + HTTP smoke tests

Default behavior:
- If Docker is available and profile is auto: full mode
- If Docker is not available and profile is auto: basic mode
"""

from __future__ import annotations

import argparse
import datetime as dt
import importlib.util
import os
import shutil
import subprocess
import sys
import textwrap
import time
import traceback
import urllib.error
import urllib.request
import webbrowser
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, List, Optional
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parents[1]
STORAGE_DIR = ROOT / "storage"
REPORT_DIR = ROOT / "e2e-report"


@dataclass
class StepResult:
    name: str
    status: str
    duration_s: float
    error: str = ""


class RunnerError(RuntimeError):
    pass


class StepSkipped(RuntimeError):
    pass


def find_php_bin() -> Optional[str]:
    php = shutil.which("php")
    if php:
        return php

    # Common locations after Homebrew install on macOS.
    for candidate in ("/opt/homebrew/bin/php", "/usr/local/bin/php"):
        if Path(candidate).exists() and os.access(candidate, os.X_OK):
            return candidate

    return None


def run_cmd(
    cmd: List[str],
    *,
    cwd: Path | None = None,
    env: dict | None = None,
    check: bool = True,
    capture_output: bool = False,
) -> subprocess.CompletedProcess:
    kwargs = {
        "cwd": str(cwd or ROOT),
        "env": env,
        "text": True,
        "check": check,
    }
    if capture_output:
        kwargs["stdout"] = subprocess.PIPE
        kwargs["stderr"] = subprocess.PIPE

    return subprocess.run(cmd, **kwargs)


def run_step(name: str, fn: Callable[[], None]) -> StepResult:
    started = time.time()
    try:
        fn()
        return StepResult(name=name, status="pass", duration_s=time.time() - started)
    except StepSkipped as exc:
        return StepResult(name=name, status="skip", duration_s=time.time() - started, error=str(exc))
    except Exception as exc:  # noqa: BLE001
        error = f"{exc}\n\n{traceback.format_exc()}"
        return StepResult(name=name, status="fail", duration_s=time.time() - started, error=error)


def wait_for_http(url: str, timeout_s: int = 60) -> None:
    start = time.time()
    while True:
        try:
            with urllib.request.urlopen(url, timeout=5) as response:
                if 200 <= response.status < 500:
                    return
        except urllib.error.URLError:
            pass

        if time.time() - start > timeout_s:
            raise RunnerError(f"Timeout esperando a que la app responda: {url}")
        time.sleep(1.5)


def http_get(url: str) -> str:
    with urllib.request.urlopen(url, timeout=10) as response:
        body = response.read()
        return body.decode("utf-8", errors="replace")


def app_url(base_url: str, path_query: str) -> str:
    return base_url.rstrip("/") + path_query


def parse_base_url(base_url: str) -> tuple[str, int]:
    parsed = urlparse(base_url)
    if not parsed.scheme or not parsed.hostname:
        raise RunnerError(f"URL inválida: {base_url}")
    if parsed.scheme != "http":
        raise RunnerError("Para servidor local usa una URL http://...")
    host = parsed.hostname
    port = parsed.port if parsed.port is not None else 80
    return host, port


def maybe_open_browser(url: str) -> None:
    try:
        webbrowser.open(url, new=1)
    except Exception:
        pass


def ensure_python_package(package: str) -> None:
    if importlib.util.find_spec(package) is not None:
        return

    print(f"[setup] Instalando paquete Python faltante: {package}")
    run_cmd([sys.executable, "-m", "pip", "install", "--user", package], check=True)


def ensure_php_available(auto_install_deps: bool) -> Optional[str]:
    php_bin = find_php_bin()
    if php_bin:
        return php_bin

    if not auto_install_deps:
        return None

    print("[deps] `php` no encontrado. Intentando auto-instalación...")

    try:
        if sys.platform == "darwin":
            brew = shutil.which("brew")
            if brew is None:
                print("[deps] Homebrew no está disponible; no se puede auto-instalar `php` en macOS.")
                return None

            run_cmd([brew, "install", "php"], check=True)
            php_bin = find_php_bin()
            if php_bin:
                print(f"[deps] `php` instalado correctamente en {php_bin}")
                return php_bin

            print("[deps] `php` se instaló pero no aparece en PATH; prueba abrir una terminal nueva.")
            return None

        if sys.platform.startswith("linux"):
            apt = shutil.which("apt-get")
            if apt is None:
                print("[deps] Auto-instalación de `php` no soportada para esta distro Linux.")
                return None

            if hasattr(os, "geteuid") and os.geteuid() == 0:
                run_cmd([apt, "update"], check=True)
                run_cmd([apt, "install", "-y", "php-cli", "php-sqlite3"], check=True)
            elif shutil.which("sudo") is not None:
                run_cmd(["sudo", apt, "update"], check=True)
                run_cmd(["sudo", apt, "install", "-y", "php-cli", "php-sqlite3"], check=True)
            else:
                print("[deps] Se requiere `sudo` para instalar `php` en Linux.")
                return None

            php_bin = find_php_bin()
            if php_bin:
                print(f"[deps] `php` instalado correctamente en {php_bin}")
                return php_bin

            return None

        print("[deps] Auto-instalación de `php` no soportada en este sistema operativo.")
        return None

    except subprocess.CalledProcessError as exc:
        print(f"[deps] Falló la auto-instalación de `php` (exit {exc.returncode}).")
        return None


def has_docker() -> bool:
    if shutil.which("docker") is None:
        return False

    try:
        run_cmd(["docker", "version"], check=True, capture_output=True)
        return True
    except Exception:
        return False


def resolve_compose_cmd() -> List[str]:
    try:
        run_cmd(["docker", "compose", "version"], check=True, capture_output=True)
        return ["docker", "compose"]
    except Exception:
        pass

    if shutil.which("docker-compose") is not None:
        return ["docker-compose"]

    raise RunnerError("No se encontró `docker compose` ni `docker-compose`.")


def ensure_playwright() -> None:
    ensure_python_package("playwright")
    print("[setup] Instalando navegador Chromium de Playwright (si falta)...")
    run_cmd([sys.executable, "-m", "playwright", "install", "chromium"], check=True)


def setup_db_mode(db_mode: str, force: bool) -> str:
    STORAGE_DIR.mkdir(parents=True, exist_ok=True)

    if db_mode == "e2e":
        host_db = STORAGE_DIR / "fitness_e2e.sqlite"
        if host_db.exists():
            host_db.unlink()
        return "/var/www/storage/fitness_e2e.sqlite"

    if db_mode == "live":
        return "/var/www/storage/fitness.sqlite"

    if db_mode == "reset":
        if not force:
            raise RunnerError("Modo `reset` bloqueado. Usa --force para confirmar borrado de DB real.")

        host_db = STORAGE_DIR / "fitness.sqlite"
        if host_db.exists():
            host_db.unlink()
        return "/var/www/storage/fitness.sqlite"

    raise RunnerError(f"db-mode inválido: {db_mode}")


def credentials_from_env() -> dict:
    seed_password = os.getenv("SEED_PASSWORD", "ChangeMe123!")

    return {
        "user": os.getenv("E2E_USER", "roberto"),
        "password": os.getenv("E2E_PASS", seed_password),
        "second_user": os.getenv("E2E_SECOND_USER", "catalina"),
        "second_password": os.getenv("E2E_SECOND_PASS", seed_password),
    }


def serve_basic_ui(base_url: str, auto_install_deps: bool) -> int:
    php_bin = ensure_php_available(auto_install_deps)
    login_url = app_url(base_url, "/?page=login")

    try:
        wait_for_http(login_url, timeout_s=3)
        print(f"[serve] App ya está activa en {base_url}")
        maybe_open_browser(login_url)
        return 0
    except Exception:
        pass

    if php_bin is None:
        raise RunnerError(
            "No hay servidor HTTP activo y `php` no está instalado. "
            "Lanza la app en otro equipo y usa --base-url, o instala PHP para local."
        )

    host, port = parse_base_url(base_url)
    STORAGE_DIR.mkdir(parents=True, exist_ok=True)

    env = os.environ.copy()
    env.setdefault("DB_PATH", str(STORAGE_DIR / "fitness_basic.sqlite"))

    cmd = [php_bin, "-S", f"{host}:{port}", "-t", "public"]
    print(f"[serve] Iniciando servidor local: {' '.join(cmd)}")
    proc = subprocess.Popen(cmd, cwd=str(ROOT), env=env)

    try:
        wait_for_http(login_url, timeout_s=25)
        print(f"[serve] UI lista en {login_url}")
        print("[serve] Pulsa Ctrl+C para detener.")
        maybe_open_browser(login_url)
        proc.wait()
    except KeyboardInterrupt:
        print("\n[serve] Deteniendo servidor local...")
    finally:
        if proc.poll() is None:
            proc.terminate()
            try:
                proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                proc.kill()

    return 0


def serve_full_ui(base_url: str, db_mode: str, force: bool, compose_env: dict) -> None:
    compose_cmd = resolve_compose_cmd()
    db_path_container = setup_db_mode(db_mode, force)
    compose_env["DB_PATH"] = db_path_container

    print(f"[docker] DB_PATH={db_path_container}")
    print("[docker] Levantando stack...")
    run_cmd(compose_cmd + ["up", "-d", "--build"], env=compose_env)

    login_url = app_url(base_url, "/?page=login")
    print(f"[wait] Esperando app en {login_url} ...")
    wait_for_http(login_url, timeout_s=120)
    print(f"[serve] UI lista en {login_url}")
    maybe_open_browser(login_url)


def run_browser_suite(base_url: str, creds: dict) -> List[StepResult]:
    from playwright.sync_api import sync_playwright

    results: List[StepResult] = []

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context()
        page = context.new_page()

        def login(user: str, password: str) -> None:
            page.goto(app_url(base_url, "/?page=login"), wait_until="domcontentloaded")
            page.fill('input[name="username"]', user)
            page.fill('input[name="password"]', password)
            page.click('button[type="submit"]')
            page.wait_for_selector('[data-testid="dashboard-user-select"]', timeout=15000)

        def logout() -> None:
            page.click('a[href="/?page=logout"]')
            page.wait_for_selector('input[name="username"]', timeout=10000)

        today = dt.date.today().isoformat()

        def step_login() -> None:
            login(creds["user"], creds["password"])

        def step_input_daily() -> None:
            page.goto(app_url(base_url, "/?page=entries"), wait_until="domcontentloaded")
            page.fill('[data-testid="entry-date"]', today)
            page.fill('[data-testid="entry-steps"]', "500")
            page.check('[data-testid="entry-workout-done"]')
            page.check('[data-testid="entry-junk-food"]')
            page.check('[data-testid="entry-extra-workout"]')
            page.fill('[data-testid="entry-step-exception"]', "Viaje de trabajo")
            page.fill('[data-testid="entry-workout-exception"]', "Molestia muscular")
            page.click('[data-testid="entry-save"]')
            page.wait_for_selector('.flash-success', timeout=10000)

        def step_table_edit() -> None:
            monday = (dt.date.today() - dt.timedelta(days=dt.date.today().weekday())).isoformat()
            page.goto(app_url(base_url, f"/?page=table&week_start={monday}"), wait_until="domcontentloaded")
            page.wait_for_selector('[data-testid="spreadsheet-table"]')
            first_row = page.locator('#spreadsheet tbody tr').first
            first_row.locator('input[name="steps"]').fill("7777")
            first_row.locator('.js-save-row').click()
            first_row.locator('.save-status.ok').wait_for(timeout=12000)

        def step_dashboard_pending_visible() -> None:
            page.goto(app_url(base_url, "/?page=dashboard"), wait_until="domcontentloaded")
            page.wait_for_selector('[data-testid="pending-approvals"]')

        def step_owner_cannot_self_approve() -> None:
            page.goto(app_url(base_url, "/?page=dashboard"), wait_until="domcontentloaded")
            if page.locator('[data-testid="pending-approval-item"]').count() != 0:
                raise AssertionError("El solicitante no debería poder revisar/autoaprobar sus propias solicitudes.")

        def step_second_user_approves() -> None:
            logout()
            login(creds["second_user"], creds["second_password"])
            page.goto(app_url(base_url, "/?page=dashboard"), wait_until="domcontentloaded")
            page.wait_for_selector('[data-testid="pending-approvals"]')

            items = page.locator('[data-testid="pending-approval-item"]')
            total = items.count()
            if total == 0:
                raise AssertionError("Se esperaban aprobaciones pendientes para el segundo usuario.")

            for _ in range(total):
                page.locator('[data-testid="approval-approve"]').first.click()
                page.wait_for_selector('.flash-success, .flash-error', timeout=12000)

            page.goto(app_url(base_url, "/?page=dashboard"), wait_until="domcontentloaded")
            if page.locator('[data-testid="pending-approval-item"]').count() != 0:
                raise AssertionError("Deben quedar 0 pendientes tras aprobar todo.")

        def step_logout() -> None:
            logout()

        results.append(run_step("Login", step_login))
        results.append(run_step("Input diario (excepciones + junk + extra)", step_input_daily))
        results.append(run_step("Tabla editable", step_table_edit))
        results.append(run_step("Dashboard carga y panel pendientes", step_dashboard_pending_visible))
        results.append(run_step("Permisos: owner no autoaprueba", step_owner_cannot_self_approve))
        results.append(run_step("Aprobaciones por segundo usuario", step_second_user_approves))
        results.append(run_step("Logout", step_logout))

        browser.close()

    return results


def run_basic_suite(base_url: str, auto_install_deps: bool) -> List[StepResult]:
    results: List[StepResult] = []

    php_bin = ensure_php_available(auto_install_deps)
    php_server: Optional[subprocess.Popen] = None
    http_ready = False

    def step_repo_files() -> None:
        required = [
            ROOT / "public/index.php",
            ROOT / "app/bootstrap.php",
            ROOT / "docker-compose.yml",
            ROOT / "README.md",
        ]
        missing = [str(p) for p in required if not p.exists()]
        if missing:
            raise RunnerError("Faltan archivos requeridos: " + ", ".join(missing))

    def step_php_lint() -> None:
        if php_bin is None:
            raise StepSkipped("PHP no está instalado; se omite lint PHP en este equipo.")

        php_files = sorted(ROOT.glob("**/*.php"))
        for file in php_files:
            if "/vendor/" in str(file):
                continue
            run_cmd([php_bin, "-l", str(file)], check=True, capture_output=True)

    def step_start_local_php_if_needed() -> None:
        nonlocal php_server, http_ready

        try:
            wait_for_http(app_url(base_url, "/?page=login"), timeout_s=3)
            http_ready = True
            return
        except Exception:
            pass

        if php_bin is None:
            raise StepSkipped(
                "No hay app HTTP accesible y no hay `php` instalado para levantar servidor local. "
                "Instala PHP o lanza la app remota y usa --base-url."
            )

        env = os.environ.copy()
        env.setdefault("DB_PATH", str(STORAGE_DIR / "fitness_basic.sqlite"))
        (STORAGE_DIR / "fitness_basic.sqlite").unlink(missing_ok=True)

        php_server = subprocess.Popen(
            [php_bin, "-S", "127.0.0.1:8080", "-t", "public"],
            cwd=str(ROOT),
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )

        wait_for_http(app_url(base_url, "/?page=login"), timeout_s=25)
        http_ready = True

    def step_http_login_page() -> None:
        if not http_ready:
            raise StepSkipped("Sin servidor HTTP disponible; se omite smoke test de login.")
        body = http_get(app_url(base_url, "/?page=login"))
        if "Fitness Challenge" not in body and "Inicia sesión" not in body:
            raise RunnerError("La página de login no contiene el contenido esperado.")

    def step_http_assets() -> None:
        if not http_ready:
            raise StepSkipped("Sin servidor HTTP disponible; se omite smoke test de assets.")
        body = http_get(app_url(base_url, "/assets/styles.css"))
        if "--bg:" not in body:
            raise RunnerError("No se pudo validar styles.css")

    def step_http_dashboard_redirect() -> None:
        if not http_ready:
            raise StepSkipped("Sin servidor HTTP disponible; se omite smoke test de dashboard.")
        body = http_get(app_url(base_url, "/?page=dashboard"))
        if "Inicia sesión" not in body and "Fitness Challenge" not in body:
            raise RunnerError("Dashboard no redirige/carga como se esperaba en modo básico.")

    results.append(run_step("Repo files esenciales", step_repo_files))
    results.append(run_step("PHP lint (si PHP disponible)", step_php_lint))
    results.append(run_step("Boot HTTP app (existente o local php -S)", step_start_local_php_if_needed))
    results.append(run_step("HTTP smoke: login", step_http_login_page))
    results.append(run_step("HTTP smoke: assets", step_http_assets))
    results.append(run_step("HTTP smoke: dashboard", step_http_dashboard_redirect))

    if php_server is not None:
        php_server.terminate()
        try:
            php_server.wait(timeout=3)
        except subprocess.TimeoutExpired:
            php_server.kill()

    return results


def write_html_report(results: List[StepResult]) -> Path:
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    timestamp = dt.datetime.now().strftime("%Y%m%d_%H%M%S")
    report_path = REPORT_DIR / f"report_{timestamp}.html"
    latest_path = REPORT_DIR / "latest.html"

    passed = sum(1 for r in results if r.status == "pass")
    skipped = sum(1 for r in results if r.status == "skip")
    total = len(results)

    rows = []
    for r in results:
        status = r.status.upper()
        cls = "ok" if r.status == "pass" else "skip" if r.status == "skip" else "fail"
        err_html = ""
        if r.error:
            escaped = (
                r.error.replace("&", "&amp;")
                .replace("<", "&lt;")
                .replace(">", "&gt;")
            )
            err_html = f"<pre>{escaped}</pre>"

        rows.append(
            f"<tr class='{cls}'><td>{r.name}</td><td>{status}</td><td>{r.duration_s:.2f}s</td><td>{err_html}</td></tr>"
        )

    html = textwrap.dedent(
        f"""
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8" />
          <meta name="viewport" content="width=device-width, initial-scale=1" />
          <title>Fitness E2E Report</title>
          <style>
            body {{ font-family: Arial, sans-serif; margin: 24px; background: #fafafa; color: #222; }}
            .summary {{ padding: 12px; border-radius: 8px; background: #fff; border: 1px solid #ddd; margin-bottom: 16px; }}
            table {{ width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #ddd; }}
            th, td {{ padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; }}
            tr.ok td {{ background: #f2fff7; }}
            tr.skip td {{ background: #f7f8ff; }}
            tr.fail td {{ background: #fff2f2; }}
            pre {{ white-space: pre-wrap; margin: 0; font-size: 12px; }}
          </style>
        </head>
        <body>
          <div class="summary">
            <h2>Fitness Runner Report</h2>
            <p>Passed: <strong>{passed}/{total}</strong></p>
            <p>Skipped: <strong>{skipped}</strong></p>
            <p>Generated: {dt.datetime.now().isoformat()}</p>
          </div>
          <table>
            <thead>
              <tr><th>Step</th><th>Status</th><th>Duration</th><th>Error</th></tr>
            </thead>
            <tbody>
              {''.join(rows)}
            </tbody>
          </table>
        </body>
        </html>
        """
    ).strip()

    report_path.write_text(html, encoding="utf-8")
    latest_path.write_text(html, encoding="utf-8")
    return report_path


def summarize(results: List[StepResult], report_path: Path) -> int:
    passed = sum(1 for r in results if r.status == "pass")
    skipped = sum(1 for r in results if r.status == "skip")
    total = len(results)
    failed = sum(1 for r in results if r.status == "fail")

    print("\n=== Runner Summary ===")
    for r in results:
        status = r.status.upper()
        print(f"- {status:4} {r.name} ({r.duration_s:.2f}s)")
    print(f"Report: {report_path}")

    if failed > 0:
        print(f"\nResultado: FAIL ({failed}/{total} fallaron)")
        return 1

    print(f"\nResultado: PASS ({passed}/{total}) | SKIP: {skipped}")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Run or serve Fitness Challenge Tracker locally.")
    parser.add_argument("--base-url", default="http://127.0.0.1:8080", help="Base URL for the web app.")
    parser.add_argument(
        "--profile",
        default="auto",
        choices=["auto", "full", "basic"],
        help="auto: full if Docker exists else basic. full: Docker+Playwright. basic: no Docker.",
    )
    parser.add_argument(
        "--db-mode",
        default="e2e",
        choices=["e2e", "live", "reset"],
        help="In full mode: e2e (isolated), live (real DB), reset (wipe live DB, requires --force).",
    )
    parser.add_argument(
        "--run-checks",
        action="store_true",
        help="Ejecuta checks/tests y genera reporte (modo runner). Por defecto inicia la Web UI.",
    )
    parser.add_argument(
        "--auto-install-deps",
        action=argparse.BooleanOptionalAction,
        default=True,
        help="Instala automáticamente dependencias faltantes cuando sea posible (default: true).",
    )
    parser.add_argument("--force", action="store_true", help="Required for destructive modes (reset).")
    parser.add_argument("--down", action="store_true", help="Stop docker compose stack after finishing full mode.")
    args = parser.parse_args()

    compose_env = os.environ.copy()
    compose_cmd: Optional[List[str]] = None

    try:
        docker_ready = has_docker()

        if args.profile == "full":
            run_mode = "full"
        elif args.profile == "basic":
            run_mode = "basic"
        else:
            run_mode = "full" if docker_ready else "basic"

        print(f"[mode] {run_mode}")

        if not args.run_checks:
            if run_mode == "basic":
                return serve_basic_ui(args.base_url, args.auto_install_deps)

            if not docker_ready:
                raise RunnerError("Docker no disponible para modo full. Usa --profile basic o instala Docker.")

            compose_cmd = resolve_compose_cmd()
            serve_full_ui(args.base_url, args.db_mode, args.force, compose_env)
            return 0

        if run_mode == "basic":
            results = run_basic_suite(args.base_url, args.auto_install_deps)
            report = write_html_report(results)
            return summarize(results, report)

        if not docker_ready:
            raise RunnerError("Docker no disponible para modo full. Usa --profile basic o instala Docker.")

        compose_cmd = resolve_compose_cmd()
        ensure_playwright()

        db_path_container = setup_db_mode(args.db_mode, args.force)
        compose_env["DB_PATH"] = db_path_container

        print(f"[docker] DB_PATH={db_path_container}")
        print("[docker] Levantando stack...")
        run_cmd(compose_cmd + ["up", "-d", "--build"], env=compose_env)

        health_url = app_url(args.base_url, "/?page=login")
        print(f"[wait] Esperando app en {health_url} ...")
        wait_for_http(health_url, timeout_s=120)

        creds = credentials_from_env()
        print(f"[e2e] Usuario principal: {creds['user']} | segundo usuario: {creds['second_user']}")
        results = run_browser_suite(args.base_url, creds)
        report = write_html_report(results)
        return summarize(results, report)

    except RunnerError as exc:
        print(f"[error] {exc}")
        return 2
    except subprocess.CalledProcessError as exc:
        print(f"[error] Comando falló ({exc.returncode}): {' '.join(exc.cmd)}")
        if exc.stdout:
            print(exc.stdout)
        if exc.stderr:
            print(exc.stderr)
        return 3
    finally:
        if args.down and compose_cmd is not None:
            try:
                print("[docker] Apagando stack (--down)")
                run_cmd(compose_cmd + ["down"], env=compose_env, check=False)
            except Exception:
                pass


if __name__ == "__main__":
    raise SystemExit(main())
