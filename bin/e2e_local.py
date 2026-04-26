#!/usr/bin/env python3
"""Local launcher for Fitness Challenge Tracker.

Release-oriented behavior:
- One database only (`storage/fitness.sqlite`)
- No automated checks/tests from this runner
- HTTPS available in Docker full mode

Modes:
- full: Docker + nginx (HTTP/HTTPS)
- basic: local `php -S` (HTTP only)
"""

from __future__ import annotations

import argparse
import os
import shutil
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.request
import webbrowser
from pathlib import Path
from typing import Optional
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parents[1]
STORAGE_DIR = ROOT / "storage"
TLS_CERT_DIR = ROOT / "nginx" / "certs"
TLS_CERT_FILE = TLS_CERT_DIR / "local.crt"
TLS_KEY_FILE = TLS_CERT_DIR / "local.key"


class RunnerError(RuntimeError):
    pass


def run_cmd(cmd: list[str], *, cwd: Optional[Path] = None, env: Optional[dict] = None, check: bool = True) -> subprocess.CompletedProcess:
    return subprocess.run(
        cmd,
        cwd=str(cwd or ROOT),
        env=env,
        text=True,
        check=check,
    )


def has_docker() -> bool:
    if shutil.which("docker") is None:
        return False

    try:
        run_cmd(["docker", "version"])
        return True
    except Exception:
        return False


def resolve_compose_cmd() -> list[str]:
    try:
        run_cmd(["docker", "compose", "version"])
        return ["docker", "compose"]
    except Exception:
        pass

    if shutil.which("docker-compose") is not None:
        return ["docker-compose"]

    raise RunnerError("No se encontró `docker compose` ni `docker-compose`.")


def find_php_bin() -> Optional[str]:
    php = shutil.which("php")
    if php:
        return php

    if os.name == "nt":
        windows_candidates = [
            Path("C:/php/php.exe"),
            Path("C:/xampp/php/php.exe"),
            Path("C:/wamp64/bin/php/php.exe"),
            Path("C:/Program Files/PHP/php.exe"),
            Path("C:/Program Files (x86)/PHP/php.exe"),
        ]
        for candidate in windows_candidates:
            if candidate.exists() and os.access(candidate, os.X_OK):
                return str(candidate)

        laragon_root = Path("C:/laragon/bin/php")
        if laragon_root.exists():
            # Try newest Laragon PHP first.
            laragon_bins = sorted(laragon_root.glob("php-*/php.exe"), reverse=True)
            for candidate in laragon_bins:
                if candidate.exists() and os.access(candidate, os.X_OK):
                    return str(candidate)

    for candidate in ("/opt/homebrew/bin/php", "/usr/local/bin/php"):
        if Path(candidate).exists() and os.access(candidate, os.X_OK):
            return candidate

    return None


def ensure_local_tls_cert() -> None:
    TLS_CERT_DIR.mkdir(parents=True, exist_ok=True)

    if TLS_CERT_FILE.exists() and TLS_KEY_FILE.exists():
        return

    openssl = shutil.which("openssl")
    if openssl is None:
        raise RunnerError(
            "No existe certificado TLS local y `openssl` no está disponible para crearlo."
        )

    print("[tls] Generando certificado local autofirmado...")
    run_cmd([
        openssl,
        "req",
        "-x509",
        "-nodes",
        "-newkey",
        "rsa:2048",
        "-keyout",
        str(TLS_KEY_FILE),
        "-out",
        str(TLS_CERT_FILE),
        "-days",
        "3650",
        "-subj",
        "/CN=localhost",
    ])


def wait_for_http(url: str, timeout_s: int = 90) -> None:
    start = time.time()
    parsed = urlparse(url)
    insecure_tls = parsed.scheme == "https"
    context = ssl._create_unverified_context() if insecure_tls else None

    while True:
        try:
            with urllib.request.urlopen(url, timeout=5, context=context) as response:
                if 200 <= response.status < 500:
                    return
        except urllib.error.URLError:
            pass

        if time.time() - start > timeout_s:
            raise RunnerError(f"Timeout esperando a que la app responda: {url}")
        time.sleep(1.5)


def app_url(base_url: str, path_query: str) -> str:
    return base_url.rstrip("/") + path_query


def parse_base_url(base_url: str) -> tuple[str, int, str]:
    parsed = urlparse(base_url)
    if not parsed.scheme or not parsed.hostname:
        raise RunnerError(f"URL inválida: {base_url}")

    scheme = parsed.scheme
    host = parsed.hostname
    if scheme not in {"http", "https"}:
        raise RunnerError("La URL base debe usar http:// o https://")

    if parsed.port is not None:
        port = parsed.port
    else:
        port = 443 if scheme == "https" else 80

    return host, port, scheme


def maybe_open_browser(url: str) -> None:
    try:
        webbrowser.open(url, new=1)
    except Exception:
        pass


def maybe_hold_console(hold_open: bool, exit_code: int) -> None:
    if not hold_open:
        return
    if sys.stdin is None or not sys.stdin.isatty():
        return

    state = "correctamente" if exit_code == 0 else "con errores"
    try:
        input(f"\n[exit] Runner finalizó {state}. Pulsa Enter para cerrar...")
    except (EOFError, KeyboardInterrupt):
        pass


def attempt_auto_install_php_windows() -> Optional[str]:
    if os.name != "nt":
        return None

    installers: list[tuple[str, list[str]]] = []
    if shutil.which("winget"):
        installers.extend(
            [
                (
                    "winget (PHP.PHP)",
                    [
                        "winget",
                        "install",
                        "--id",
                        "PHP.PHP",
                        "-e",
                        "--accept-package-agreements",
                        "--accept-source-agreements",
                    ],
                ),
                (
                    "winget (PHP.PHP.8.3)",
                    [
                        "winget",
                        "install",
                        "--id",
                        "PHP.PHP.8.3",
                        "-e",
                        "--accept-package-agreements",
                        "--accept-source-agreements",
                    ],
                ),
            ]
        )
    if shutil.which("choco"):
        installers.append(("choco", ["choco", "install", "php", "-y"]))
    if shutil.which("scoop"):
        installers.append(("scoop", ["scoop", "install", "php"]))

    if not installers:
        print("[deps] No se encontró winget/choco/scoop para auto-instalar PHP.")
        return None

    for label, cmd in installers:
        print(f"[deps] Intentando instalar PHP con {label}...")
        try:
            completed = subprocess.run(
                cmd,
                cwd=str(ROOT),
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                check=False,
            )
        except Exception as exc:
            print(f"[deps] Error ejecutando {label}: {exc}")
            continue

        if completed.returncode != 0:
            snippet = (completed.stdout or "").strip().splitlines()
            preview = "\n".join(snippet[-8:]) if snippet else "(sin salida)"
            print(f"[deps] {label} falló ({completed.returncode}).")
            print(preview)
            continue

        # Give installer a moment to flush files before probing.
        time.sleep(1.2)
        php_bin = find_php_bin()
        if php_bin:
            print(f"[deps] PHP instalado correctamente: {php_bin}")
            return php_bin

        print(f"[deps] {label} terminó, pero `php` aún no aparece en PATH.")

    return None


def ensure_php_dependency(auto_install_deps: bool) -> Optional[str]:
    php_bin = find_php_bin()
    if php_bin is not None:
        return php_bin

    if not auto_install_deps:
        return None

    print("[deps] `php` no encontrado. Intentando auto-instalar dependencia...")
    if os.name == "nt":
        php_bin = attempt_auto_install_php_windows()
    else:
        php_bin = None

    return php_bin


def serve_basic_ui(base_url: str, *, auto_install_deps: bool) -> int:
    host, port, scheme = parse_base_url(base_url)
    if scheme != "http":
        raise RunnerError("El modo basic solo soporta HTTP (ejemplo: http://0.0.0.0:8080).")

    php_bin = ensure_php_dependency(auto_install_deps)
    if php_bin is None:
        if os.name == "nt":
            raise RunnerError(
                "No se encontró `php` para ejecutar el modo basic. "
                "Instálalo con `winget install PHP.PHP` o lanza con `--auto-install-deps`."
            )
        raise RunnerError("No se encontró `php` para ejecutar el modo basic.")

    login_url = app_url(base_url, "/?page=login")

    try:
        wait_for_http(login_url, timeout_s=3)
        print(f"[serve] App ya está activa en {base_url}")
        maybe_open_browser(login_url)
        return 0
    except Exception:
        pass

    STORAGE_DIR.mkdir(parents=True, exist_ok=True)
    env = os.environ.copy()
    env["DB_PATH"] = str(STORAGE_DIR / "fitness.sqlite")

    cmd = [
        php_bin,
        "-d",
        "upload_max_filesize=20M",
        "-d",
        "post_max_size=20M",
        "-d",
        "max_file_uploads=50",
        "-S",
        f"{host}:{port}",
        "-t",
        "public",
    ]
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


def serve_full_ui(base_url: str) -> int:
    if not has_docker():
        raise RunnerError("Docker no está disponible para modo full.")

    compose_cmd = resolve_compose_cmd()
    ensure_local_tls_cert()

    compose_env = os.environ.copy()
    compose_env["DB_PATH"] = "/var/www/storage/fitness.sqlite"

    print("[docker] Levantando stack (DB única: storage/fitness.sqlite)...")
    run_cmd(compose_cmd + ["up", "-d", "--build"], env=compose_env)

    login_url = app_url(base_url, "/?page=login")
    print(f"[wait] Esperando app en {login_url} ...")
    wait_for_http(login_url, timeout_s=120)
    print(f"[serve] UI lista en {login_url}")
    print("[serve] HTTPS disponible en Docker full.")
    maybe_open_browser(login_url)

    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Launch Fitness Challenge Tracker locally.")
    parser.add_argument(
        "--profile",
        default="auto",
        choices=["auto", "full", "basic"],
        help="auto: full si Docker está disponible, si no basic.",
    )
    parser.add_argument(
        "--base-url",
        default="",
        help="URL base de la app. Default: full=https://127.0.0.1:8443, basic=http://0.0.0.0:8080",
    )
    parser.add_argument(
        "--hold",
        action=argparse.BooleanOptionalAction,
        default=None,
        help="Mantiene la consola abierta al terminar (útil en Windows/doble clic).",
    )
    parser.add_argument(
        "--auto-install-deps",
        action=argparse.BooleanOptionalAction,
        default=True,
        help="Auto-instala dependencias faltantes cuando sea posible (Windows: PHP via winget/choco/scoop).",
    )
    args = parser.parse_args()

    hold_open = args.hold
    if hold_open is None:
        # If launched by double-click on Windows, there are usually no args.
        hold_open = os.name == "nt" and len(sys.argv) == 1

    exit_code = 0
    try:
        docker_ready = has_docker()

        if args.profile == "full":
            run_mode = "full"
        elif args.profile == "basic":
            run_mode = "basic"
        else:
            run_mode = "full" if docker_ready else "basic"

        base_url = args.base_url.strip()
        if base_url == "":
            base_url = "https://127.0.0.1:8443" if run_mode == "full" else "http://0.0.0.0:8080"

        print(f"[mode] {run_mode}")
        print(f"[url] {base_url}")

        if run_mode == "full":
            exit_code = serve_full_ui(base_url)
        else:
            exit_code = serve_basic_ui(base_url, auto_install_deps=bool(args.auto_install_deps))

    except RunnerError as exc:
        print(f"[error] {exc}")
        exit_code = 2
    except subprocess.CalledProcessError as exc:
        print(f"[error] Comando falló ({exc.returncode}): {' '.join(exc.cmd)}")
        exit_code = 3
    finally:
        maybe_hold_console(bool(hold_open), exit_code)

    return exit_code


if __name__ == "__main__":
    sys.exit(main())
