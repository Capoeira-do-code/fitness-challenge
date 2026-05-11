#!/usr/bin/env python3
"""Local launcher and quick checker for Fitness Challenge Tracker.

Modes:
- full: Docker + nginx (HTTP/HTTPS)
- basic: local `php -S` (HTTP only)
"""

from __future__ import annotations

import argparse
import datetime as dt
import html
import os
import shutil
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.request
import webbrowser
import zipfile
from pathlib import Path
from typing import Optional
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parents[1]
STORAGE_DIR = ROOT / "storage"
REPORT_DIR = ROOT / "e2e-report"
TLS_CERT_DIR = ROOT / "nginx" / "certs"
TLS_CERT_FILE = TLS_CERT_DIR / "local.crt"
TLS_KEY_FILE = TLS_CERT_DIR / "local.key"
TOOLS_DIR = ROOT / ".tools"
WINDOWS_PORTABLE_PHP_DIR = TOOLS_DIR / "php-portable"
WINDOWS_DEFAULT_PORTABLE_PHP_URLS = [
    "https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip",
    "https://windows.php.net/downloads/releases/latest/php-8.4-nts-Win32-vs17-x64-latest.zip",
]


class RunnerError(RuntimeError):
    pass


def run_cmd(
    cmd: list[str],
    *,
    cwd: Optional[Path] = None,
    env: Optional[dict] = None,
    check: bool = True,
    capture_output: bool = False,
) -> subprocess.CompletedProcess:
    return subprocess.run(
        cmd,
        cwd=str(cwd or ROOT),
        env=env,
        text=True,
        check=check,
        stdout=subprocess.PIPE if capture_output else None,
        stderr=subprocess.STDOUT if capture_output else None,
    )


def has_docker() -> bool:
    if shutil.which("docker") is None:
        return False
    try:
        run_cmd(["docker", "version"], check=True)
        return True
    except Exception:
        return False


def resolve_compose_cmd() -> list[str]:
    try:
        run_cmd(["docker", "compose", "version"], check=True)
        return ["docker", "compose"]
    except Exception:
        pass
    if shutil.which("docker-compose") is not None:
        return ["docker-compose"]
    raise RunnerError("Could not find `docker compose` or `docker-compose`.")


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
            laragon_bins = sorted(laragon_root.glob("php-*/php.exe"), reverse=True)
            for candidate in laragon_bins:
                if candidate.exists() and os.access(candidate, os.X_OK):
                    return str(candidate)

    for candidate in ("/opt/homebrew/bin/php", "/usr/local/bin/php"):
        if Path(candidate).exists() and os.access(candidate, os.X_OK):
            return candidate

    return None


def verify_php_bin(php_path: Path | str) -> bool:
    candidate = str(php_path)
    try:
        completed = subprocess.run(
            [candidate, "-v"],
            cwd=str(ROOT),
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            check=False,
        )
    except Exception:
        return False
    return completed.returncode == 0


def php_has_sqlite_driver(php_bin: str, extra_args: Optional[list[str]] = None) -> bool:
    probe_script = (
        "if (!class_exists('PDO')) {fwrite(STDERR, 'pdo_missing\\n'); exit(2);} "
        "$drivers = PDO::getAvailableDrivers(); "
        "if (!in_array('sqlite', $drivers, true)) {fwrite(STDERR, 'pdo_sqlite_missing\\n'); exit(3);} "
        "echo 'ok';"
    )
    cmd = [php_bin]
    if extra_args:
        cmd.extend(extra_args)
    cmd.extend(["-r", probe_script])
    try:
        completed = subprocess.run(
            cmd,
            cwd=str(ROOT),
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            check=False,
        )
    except Exception:
        return False
    return completed.returncode == 0


def windows_sqlite_extension_flags(php_bin: str) -> list[str]:
    if os.name != "nt":
        return []

    php_dir = Path(php_bin).resolve().parent
    ext_candidates = [php_dir / "ext", php_dir.parent / "ext"]
    for ext_dir in ext_candidates:
        if not ext_dir.exists() or not ext_dir.is_dir():
            continue
        required_dlls = [ext_dir / "php_pdo_sqlite.dll", ext_dir / "php_sqlite3.dll"]
        if not all(dll.exists() for dll in required_dlls):
            continue
        flags = [
            "-d",
            f"extension_dir={ext_dir.as_posix()}",
            "-d",
            "extension=pdo_sqlite",
            "-d",
            "extension=sqlite3",
        ]
        if php_has_sqlite_driver(php_bin, flags):
            print(f"[deps] Enabled SQLite extensions from: {ext_dir}")
            return flags
    return []


def download_file(url: str, destination: Path) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    with urllib.request.urlopen(url, timeout=120) as response:
        if getattr(response, "status", 200) >= 400:
            raise RunnerError(f"Download failed ({response.status}) for {url}")
        with destination.open("wb") as output:
            while True:
                chunk = response.read(1024 * 64)
                if not chunk:
                    break
                output.write(chunk)


def extract_php_zip(zip_path: Path, target_dir: Path) -> Optional[Path]:
    if target_dir.exists():
        shutil.rmtree(target_dir)
    target_dir.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(zip_path, "r") as zip_file:
        zip_file.extractall(target_dir)
    direct_candidate = target_dir / "php.exe"
    if direct_candidate.exists():
        return direct_candidate
    nested_candidates = list(target_dir.glob("**/php.exe"))
    return nested_candidates[0] if nested_candidates else None


def attempt_portable_php_windows() -> Optional[str]:
    if os.name != "nt":
        return None

    existing_php = WINDOWS_PORTABLE_PHP_DIR / "php.exe"
    if existing_php.exists() and verify_php_bin(existing_php):
        print(f"[deps] Using existing portable PHP: {existing_php}")
        return str(existing_php)

    env_url = os.environ.get("PHP_WINDOWS_ZIP_URL", "").strip()
    candidate_urls = [env_url] if env_url else []
    candidate_urls.extend(WINDOWS_DEFAULT_PORTABLE_PHP_URLS)

    archive_path = WINDOWS_PORTABLE_PHP_DIR / "php-portable.zip"
    extract_dir = WINDOWS_PORTABLE_PHP_DIR / "_extracted"
    WINDOWS_PORTABLE_PHP_DIR.mkdir(parents=True, exist_ok=True)

    for url in candidate_urls:
        if not url:
            continue
        print(f"[deps] Trying portable PHP fallback from: {url}")
        try:
            download_file(url, archive_path)
            php_candidate = extract_php_zip(archive_path, extract_dir)
            if php_candidate is None:
                print("[deps] Downloaded ZIP does not contain php.exe.")
                continue

            for item in WINDOWS_PORTABLE_PHP_DIR.iterdir():
                if item.name in {"php-portable.zip", "_extracted"}:
                    continue
                if item.is_dir():
                    shutil.rmtree(item, ignore_errors=True)
                else:
                    item.unlink(missing_ok=True)

            if php_candidate.parent != WINDOWS_PORTABLE_PHP_DIR:
                for extracted_item in php_candidate.parent.iterdir():
                    destination = WINDOWS_PORTABLE_PHP_DIR / extracted_item.name
                    if destination.exists():
                        if destination.is_dir():
                            shutil.rmtree(destination, ignore_errors=True)
                        else:
                            destination.unlink(missing_ok=True)
                    shutil.move(str(extracted_item), str(destination))

            final_php = WINDOWS_PORTABLE_PHP_DIR / "php.exe"
            if final_php.exists() and verify_php_bin(final_php):
                print(f"[deps] Portable PHP ready: {final_php}")
                return str(final_php)
            print("[deps] Portable PHP downloaded but verification failed.")
        except Exception as exc:
            print(f"[deps] Portable fallback failed for {url}: {exc}")

    return None


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
        print("[deps] winget/choco/scoop not found for auto-install.")
        return None

    for label, cmd in installers:
        print(f"[deps] Trying PHP install with {label}...")
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
            print(f"[deps] Error running {label}: {exc}")
            continue

        if completed.returncode != 0:
            snippet = (completed.stdout or "").strip().splitlines()
            preview = "\n".join(snippet[-8:]) if snippet else "(no output)"
            print(f"[deps] {label} failed ({completed.returncode}).")
            print(preview)
            continue

        time.sleep(1.2)
        php_bin = find_php_bin()
        if php_bin:
            print(f"[deps] PHP installed: {php_bin}")
            return php_bin
        print(f"[deps] {label} finished but php is still not in PATH.")

    return None


def ensure_php_dependency(auto_install_deps: bool) -> Optional[str]:
    php_bin = find_php_bin()
    if php_bin is not None:
        return php_bin
    if not auto_install_deps:
        return None
    print("[deps] `php` not found. Trying auto-install...")
    if os.name == "nt":
        php_bin = attempt_auto_install_php_windows()
        if php_bin is None:
            php_bin = attempt_portable_php_windows()
    else:
        php_bin = None
    return php_bin


def ensure_local_tls_cert() -> None:
    TLS_CERT_DIR.mkdir(parents=True, exist_ok=True)
    if TLS_CERT_FILE.exists() and TLS_KEY_FILE.exists():
        return
    openssl = shutil.which("openssl")
    if openssl is None:
        raise RunnerError("Missing local TLS cert and `openssl` is not available.")
    print("[tls] Generating local self-signed cert...")
    run_cmd(
        [
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
        ],
        check=True,
    )


def app_url(base_url: str, path_query: str) -> str:
    return base_url.rstrip("/") + path_query


def base_url_from_absolute(url: str) -> str:
    parsed = urlparse(url)
    if not parsed.scheme or not parsed.netloc:
        raise RunnerError(f"Invalid absolute URL: {url}")
    return f"{parsed.scheme}://{parsed.netloc}"


def parse_base_url(base_url: str) -> tuple[str, int, str]:
    parsed = urlparse(base_url)
    if not parsed.scheme or not parsed.hostname:
        raise RunnerError(f"Invalid URL: {base_url}")
    scheme = parsed.scheme
    host = parsed.hostname
    if scheme not in {"http", "https"}:
        raise RunnerError("Base URL must use http:// or https://")
    if parsed.port is not None:
        port = parsed.port
    else:
        port = 443 if scheme == "https" else 80
    return host, port, scheme


def wait_for_http(url: str, timeout_s: int = 90, interval_s: float = 1.5) -> None:
    parsed = urlparse(url)
    insecure_tls = parsed.scheme == "https"
    context = ssl._create_unverified_context() if insecure_tls else None
    start = time.time()
    while True:
        try:
            with urllib.request.urlopen(url, timeout=5, context=context) as response:
                if 200 <= response.status < 500:
                    return
        except urllib.error.URLError:
            pass
        if time.time() - start > timeout_s:
            raise RunnerError(f"Timeout waiting for app response: {url}")
        time.sleep(interval_s)


def basic_probe_urls(base_url: str, path_query: str) -> list[str]:
    primary = app_url(base_url, path_query)
    parsed = urlparse(primary)
    host = (parsed.hostname or "").strip().lower()
    urls = [primary]
    if host not in {"0.0.0.0", "::"}:
        return urls
    for fallback_host in ("127.0.0.1", "localhost"):
        port_part = f":{parsed.port}" if parsed.port is not None else ""
        fallback_netloc = f"{fallback_host}{port_part}"
        fallback_url = parsed._replace(netloc=fallback_netloc).geturl()
        if fallback_url not in urls:
            urls.append(fallback_url)
    return urls


def wait_for_http_any(
    urls: list[str],
    timeout_s: int = 90,
    proc: Optional[subprocess.Popen] = None,
    interval_s: float = 1.5,
) -> str:
    start = time.time()
    targets: list[str] = []
    for url in urls:
        if url not in targets:
            targets.append(url)
    while True:
        for url in targets:
            parsed = urlparse(url)
            insecure_tls = parsed.scheme == "https"
            context = ssl._create_unverified_context() if insecure_tls else None
            try:
                with urllib.request.urlopen(url, timeout=5, context=context) as response:
                    if 200 <= response.status < 500:
                        return url
            except Exception:
                pass
        if proc is not None and proc.poll() is not None:
            raise RunnerError(
                "Local PHP server exited before responding "
                f"(exit code {proc.returncode})."
            )
        if time.time() - start > timeout_s:
            raise RunnerError("Timeout waiting for app response. Tried: " + ", ".join(targets))
        time.sleep(interval_s)


def parse_window_size(raw: str) -> Optional[tuple[int, int]]:
    value = raw.strip().lower()
    if value == "":
        return None
    if "x" not in value:
        raise RunnerError("Invalid --window-size format. Use WIDTHxHEIGHT (example: 1280x860).")
    left, right = value.split("x", 1)
    width = int(left.strip())
    height = int(right.strip())
    if width <= 0 or height <= 0:
        raise RunnerError("Invalid --window-size values.")
    return width, height


def parse_window_pos(raw: str) -> Optional[tuple[int, int]]:
    value = raw.strip()
    if value == "":
        return None
    if "," not in value:
        raise RunnerError("Invalid --window-pos format. Use X,Y (example: 40,40).")
    left, right = value.split(",", 1)
    x = int(left.strip())
    y = int(right.strip())
    return x, y


def browser_binaries(browser: str) -> list[str]:
    if browser not in {"auto", "chrome", "chromium", "edge"}:
        return []

    chrome = [
        shutil.which("google-chrome"),
        shutil.which("google-chrome-stable"),
        shutil.which("chrome"),
        "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
        "C:/Program Files/Google/Chrome/Application/chrome.exe",
        "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe",
    ]
    chromium = [
        shutil.which("chromium-browser"),
        shutil.which("chromium"),
        "/Applications/Chromium.app/Contents/MacOS/Chromium",
        "C:/Program Files/Chromium/Application/chrome.exe",
    ]
    edge = [
        shutil.which("microsoft-edge"),
        shutil.which("msedge"),
        "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
        "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
        "C:/Program Files/Microsoft/Edge/Application/msedge.exe",
    ]

    pools = {
        "chrome": chrome,
        "chromium": chromium,
        "edge": edge,
    }
    order = ["chrome", "chromium", "edge"] if browser == "auto" else [browser]
    resolved: list[str] = []
    for key in order:
        for candidate in pools.get(key, []):
            if not candidate:
                continue
            path = str(candidate)
            if path in resolved:
                continue
            if os.path.isabs(path):
                if Path(path).exists():
                    resolved.append(path)
                continue
            resolved.append(path)
    return resolved


def maybe_open_browser(
    url: str,
    *,
    open_mode: str,
    browser: str,
    window_size: Optional[tuple[int, int]],
    window_pos: Optional[tuple[int, int]],
) -> None:
    if open_mode == "none":
        return

    if open_mode == "tab":
        try:
            webbrowser.open(url, new=1)
        except Exception:
            pass
        return

    args_suffix: list[str] = []
    if window_size is not None:
        args_suffix.append(f"--window-size={window_size[0]},{window_size[1]}")
    if window_pos is not None:
        args_suffix.append(f"--window-position={window_pos[0]},{window_pos[1]}")

    for binary in browser_binaries(browser):
        try:
            if open_mode == "app":
                cmd = [binary, f"--app={url}", *args_suffix]
            else:
                cmd = [binary, "--new-window", url, *args_suffix]
            subprocess.Popen(
                cmd,
                cwd=str(ROOT),
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            return
        except Exception:
            continue

    try:
        webbrowser.open(url, new=1)
    except Exception:
        pass


def maybe_hold_console(hold_open: bool, exit_code: int) -> None:
    if not hold_open:
        return
    if sys.stdin is None or not sys.stdin.isatty():
        return
    state = "ok" if exit_code == 0 else "with errors"
    try:
        input(f"\n[exit] Runner finished {state}. Press Enter to close...")
    except (EOFError, KeyboardInterrupt):
        pass


def host_db_path_for_mode(db_mode: str) -> Path:
    if db_mode == "live":
        return STORAGE_DIR / "fitness.sqlite"
    return STORAGE_DIR / "fitness_e2e.sqlite"


def container_db_path_for_mode(db_mode: str) -> str:
    host_path = host_db_path_for_mode(db_mode)
    return f"/var/www/storage/{host_path.name}"


def ensure_db_mode(db_mode: str, force: bool) -> None:
    STORAGE_DIR.mkdir(parents=True, exist_ok=True)
    if db_mode != "reset":
        return
    if not force:
        raise RunnerError("db-mode=reset is destructive. Pass --force to continue.")
    reset_path = host_db_path_for_mode("reset")
    if reset_path.exists():
        reset_path.unlink()
        print(f"[db] Reset database file: {reset_path}")


def build_php_server_command(php_bin: str, host: str, port: int) -> tuple[list[str], dict]:
    STORAGE_DIR.mkdir(parents=True, exist_ok=True)
    env = os.environ.copy()
    env["DB_PATH"] = str(STORAGE_DIR / "fitness.sqlite")
    upload_max_filesize = os.environ.get("PHP_UPLOAD_MAX_FILESIZE", "").strip() or "20M"
    post_max_size = os.environ.get("PHP_POST_MAX_SIZE", "").strip() or "256M"
    max_file_uploads = os.environ.get("PHP_MAX_FILE_UPLOADS", "").strip() or "200"
    try:
        if int(max_file_uploads) < 1:
            raise ValueError
    except ValueError:
        print(f"[deps] Invalid PHP_MAX_FILE_UPLOADS ({max_file_uploads}). Using 200.")
        max_file_uploads = "200"

    runtime_flags: list[str] = []
    if php_has_sqlite_driver(php_bin):
        print("[deps] SQLite driver available in PHP.")
    elif os.name == "nt":
        runtime_flags = windows_sqlite_extension_flags(php_bin)
        if not runtime_flags:
            raise RunnerError(
                "PHP found but `pdo_sqlite` is unavailable. "
                "Use a complete Windows PHP build (with ext dir) or set PHP_WINDOWS_ZIP_URL."
            )
    else:
        raise RunnerError(
            "PHP found but `pdo_sqlite` is unavailable. Enable `pdo_sqlite` and `sqlite3`."
        )

    cmd = [
        php_bin,
        *runtime_flags,
        "-d",
        f"upload_max_filesize={upload_max_filesize}",
        "-d",
        f"post_max_size={post_max_size}",
        "-d",
        f"max_file_uploads={max_file_uploads}",
        "-S",
        f"{host}:{port}",
        "-t",
        "public",
    ]
    print(
        "[deps] PHP upload limits: "
        f"upload_max_filesize={upload_max_filesize}, "
        f"post_max_size={post_max_size}, "
        f"max_file_uploads={max_file_uploads}"
    )
    return cmd, env


def start_basic_server(
    base_url: str,
    *,
    auto_install_deps: bool,
    wait_timeout_s: int,
    wait_interval_ms: int,
) -> tuple[str, Optional[subprocess.Popen]]:
    host, port, scheme = parse_base_url(base_url)
    if scheme != "http":
        raise RunnerError("basic mode supports HTTP only (example: http://0.0.0.0:8080).")

    php_bin = ensure_php_dependency(auto_install_deps)
    if php_bin is None:
        if os.name == "nt":
            raise RunnerError(
                "Could not find `php` for basic mode. Try --auto-install-deps, "
                "install with winget/choco/scoop, or set PHP_WINDOWS_ZIP_URL."
            )
        raise RunnerError("Could not find `php` for basic mode.")

    login_probe_urls = basic_probe_urls(base_url, "/?page=login")
    interval_s = max(0.05, wait_interval_ms / 1000.0)
    try:
        live_url = wait_for_http_any(login_probe_urls, timeout_s=2, interval_s=interval_s)
        print(f"[serve] App is already running at {live_url}")
        return live_url, None
    except Exception:
        pass

    cmd, env = build_php_server_command(php_bin, host, port)
    print(f"[serve] Starting local server: {' '.join(cmd)}")
    proc = subprocess.Popen(cmd, cwd=str(ROOT), env=env)
    live_url = wait_for_http_any(
        login_probe_urls,
        timeout_s=wait_timeout_s,
        proc=proc,
        interval_s=interval_s,
    )
    return live_url, proc


def stop_process(proc: Optional[subprocess.Popen]) -> None:
    if proc is None:
        return
    if proc.poll() is not None:
        return
    proc.terminate()
    try:
        proc.wait(timeout=3)
    except subprocess.TimeoutExpired:
        proc.kill()


def start_full_server(
    base_url: str,
    *,
    db_mode: str,
    force: bool,
    wait_timeout_s: int,
    wait_interval_ms: int,
) -> str:
    if not has_docker():
        raise RunnerError("Docker is not available for full mode.")
    ensure_db_mode(db_mode, force)
    compose_cmd = resolve_compose_cmd()
    ensure_local_tls_cert()
    compose_env = os.environ.copy()
    compose_env["DB_PATH"] = container_db_path_for_mode(db_mode)
    print(f"[docker] Bringing up stack (DB: {compose_env['DB_PATH']})...")
    run_cmd(compose_cmd + ["up", "-d", "--build"], env=compose_env, check=True)
    login_url = app_url(base_url, "/?page=login")
    interval_s = max(0.05, wait_interval_ms / 1000.0)
    print(f"[wait] Waiting for app at {login_url} ...")
    wait_for_http(login_url, timeout_s=wait_timeout_s, interval_s=interval_s)
    return login_url


def down_full_stack() -> int:
    if not has_docker():
        print("[down] Docker not available. Nothing to stop.")
        return 0
    compose_cmd = resolve_compose_cmd()
    print("[down] Stopping Docker stack...")
    run_cmd(compose_cmd + ["down"], check=True)
    print("[down] Stack stopped.")
    return 0


def php_files() -> list[Path]:
    files = sorted(ROOT.joinpath("app").rglob("*.php")) + sorted(ROOT.joinpath("public").rglob("*.php"))
    unique: list[Path] = []
    seen: set[str] = set()
    for file in files:
        key = str(file.resolve())
        if key in seen:
            continue
        seen.add(key)
        unique.append(file)
    return unique


def check_php_lint(auto_install_deps: bool) -> tuple[bool, str]:
    php_bin = ensure_php_dependency(auto_install_deps)
    if php_bin is None:
        return False, "php not found for lint"
    failures: list[str] = []
    for file in php_files():
        completed = subprocess.run(
            [php_bin, "-l", str(file)],
            cwd=str(ROOT),
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            check=False,
        )
        if completed.returncode != 0:
            failures.append(f"{file}: {(completed.stdout or '').strip()}")
            if len(failures) >= 10:
                break
    if failures:
        return False, "; ".join(failures)
    return True, f"lint ok ({len(php_files())} files)"


def check_http_status(url: str) -> tuple[bool, str]:
    parsed = urlparse(url)
    insecure_tls = parsed.scheme == "https"
    context = ssl._create_unverified_context() if insecure_tls else None
    try:
        with urllib.request.urlopen(url, timeout=10, context=context) as response:
            ok = 200 <= response.status < 500
            return ok, f"status {response.status}"
    except Exception as exc:
        return False, str(exc)


def write_report(rows: list[dict]) -> Path:
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    ts = dt.datetime.now().strftime("%Y%m%d_%H%M%S")
    report_path = REPORT_DIR / f"report_{ts}.html"
    latest_path = REPORT_DIR / "latest.html"

    passed = sum(1 for row in rows if row["ok"])
    total = len(rows)
    status = "PASS" if passed == total else "FAIL"
    body_rows = []
    for row in rows:
        cls = "ok" if row["ok"] else "fail"
        body_rows.append(
            "<tr>"
            f"<td>{html.escape(str(row['name']))}</td>"
            f"<td class='{cls}'>{'ok' if row['ok'] else 'fail'}</td>"
            f"<td>{html.escape(str(row['detail']))}</td>"
            f"<td>{row['duration_ms']}</td>"
            "</tr>"
        )

    html_doc = (
        "<!doctype html><html><head><meta charset='utf-8'>"
        "<title>e2e_local report</title>"
        "<style>"
        "body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:24px;background:#0f172a;color:#e2e8f0;}"
        "table{border-collapse:collapse;width:100%;background:#111827;}"
        "th,td{padding:10px;border:1px solid #1f2937;text-align:left;vertical-align:top;}"
        "th{background:#1e293b;}"
        ".ok{color:#34d399;font-weight:700;}.fail{color:#f87171;font-weight:700;}"
        ".meta{margin-bottom:14px;color:#94a3b8;}"
        "</style></head><body>"
        f"<h1>e2e_local quick checks: {status}</h1>"
        f"<p class='meta'>{passed}/{total} passed · generated {html.escape(dt.datetime.now().isoformat())}</p>"
        "<table><thead><tr><th>Check</th><th>Status</th><th>Detail</th><th>ms</th></tr></thead>"
        f"<tbody>{''.join(body_rows)}</tbody></table>"
        "</body></html>"
    )
    report_path.write_text(html_doc, encoding="utf-8")
    shutil.copyfile(report_path, latest_path)
    return report_path


def run_quick_checks(
    *,
    run_mode: str,
    base_url: str,
    auto_install_deps: bool,
    db_mode: str,
    force: bool,
    wait_timeout_s: int,
    wait_interval_ms: int,
) -> int:
    started_basic_proc: Optional[subprocess.Popen] = None
    live_url = ""
    checks_base_url = ""
    rows: list[dict] = []

    def add_row(name: str, ok: bool, detail: str, start_ms: float) -> None:
        rows.append(
            {
                "name": name,
                "ok": ok,
                "detail": detail,
                "duration_ms": int((time.perf_counter() - start_ms) * 1000),
            }
        )

    try:
        t0 = time.perf_counter()
        if run_mode == "full":
            live_url = start_full_server(
                base_url,
                db_mode=db_mode,
                force=force,
                wait_timeout_s=wait_timeout_s,
                wait_interval_ms=wait_interval_ms,
            )
        else:
            live_url, started_basic_proc = start_basic_server(
                base_url,
                auto_install_deps=auto_install_deps,
                wait_timeout_s=wait_timeout_s,
                wait_interval_ms=wait_interval_ms,
            )
        add_row("server_start", True, f"live at {live_url}", t0)
        checks_base_url = base_url_from_absolute(live_url)

        t0 = time.perf_counter()
        lint_ok, lint_detail = check_php_lint(auto_install_deps)
        add_row("php_lint", lint_ok, lint_detail, t0)

        t0 = time.perf_counter()
        ok, detail = check_http_status(app_url(checks_base_url, "/?page=login"))
        add_row("http_login", ok, detail, t0)

        t0 = time.perf_counter()
        ok, detail = check_http_status(app_url(checks_base_url, "/?page=dashboard"))
        add_row("http_dashboard", ok, detail, t0)

        t0 = time.perf_counter()
        ok_css, detail_css = check_http_status(app_url(checks_base_url, "/assets/styles.css"))
        ok_js, detail_js = check_http_status(app_url(checks_base_url, "/assets/main.js"))
        add_row(
            "http_assets",
            ok_css and ok_js,
            f"styles: {detail_css}; main.js: {detail_js}",
            t0,
        )
    except RunnerError as exc:
        rows.append({"name": "runner", "ok": False, "detail": str(exc), "duration_ms": 0})
    finally:
        stop_process(started_basic_proc)

    report_path = write_report(rows)
    print(f"[report] {report_path}")
    print(f"[report] {REPORT_DIR / 'latest.html'}")
    failed = any(not row["ok"] for row in rows)
    return 1 if failed else 0


def serve_basic_ui(
    base_url: str,
    *,
    auto_install_deps: bool,
    open_mode: str,
    browser: str,
    window_size: Optional[tuple[int, int]],
    window_pos: Optional[tuple[int, int]],
    wait_timeout_s: int,
    wait_interval_ms: int,
) -> int:
    live_url, proc = start_basic_server(
        base_url,
        auto_install_deps=auto_install_deps,
        wait_timeout_s=wait_timeout_s,
        wait_interval_ms=wait_interval_ms,
    )
    print(f"[serve] UI ready at {live_url}")
    maybe_open_browser(
        live_url,
        open_mode=open_mode,
        browser=browser,
        window_size=window_size,
        window_pos=window_pos,
    )
    if proc is None:
        return 0
    print("[serve] Press Ctrl+C to stop.")
    try:
        proc.wait()
    except KeyboardInterrupt:
        print("\n[serve] Stopping local PHP server...")
    finally:
        stop_process(proc)
    return 0


def serve_full_ui(
    base_url: str,
    *,
    open_mode: str,
    browser: str,
    window_size: Optional[tuple[int, int]],
    window_pos: Optional[tuple[int, int]],
    db_mode: str,
    force: bool,
    wait_timeout_s: int,
    wait_interval_ms: int,
) -> int:
    login_url = start_full_server(
        base_url,
        db_mode=db_mode,
        force=force,
        wait_timeout_s=wait_timeout_s,
        wait_interval_ms=wait_interval_ms,
    )
    print(f"[serve] UI ready at {login_url}")
    print("[serve] HTTPS is available in full mode.")
    maybe_open_browser(
        login_url,
        open_mode=open_mode,
        browser=browser,
        window_size=window_size,
        window_pos=window_pos,
    )
    return 0


def launch_runtime_manager() -> int:
    manager = ROOT / "bin" / "php_runtime_manager.py"
    if not manager.exists():
        raise RunnerError("php_runtime_manager.py was not found.")
    cmd = [sys.executable, str(manager), "wizard"]
    print(f"[runtime] Launching: {' '.join(cmd)}")
    completed = subprocess.run(cmd, cwd=str(ROOT), check=False)
    return completed.returncode


def resolve_run_mode(profile: str) -> str:
    if profile == "full":
        return "full"
    if profile == "basic":
        return "basic"
    return "full" if has_docker() else "basic"


def default_base_url(run_mode: str) -> str:
    return "https://127.0.0.1:8443" if run_mode == "full" else "http://0.0.0.0:8080"


def main() -> int:
    parser = argparse.ArgumentParser(description="Launch Fitness Challenge Tracker locally.")
    parser.add_argument(
        "--profile",
        default="auto",
        choices=["auto", "full", "basic"],
        help="auto: full when Docker is available, otherwise basic.",
    )
    parser.add_argument(
        "--base-url",
        default="",
        help="Base app URL. Default: full=https://127.0.0.1:8443, basic=http://0.0.0.0:8080",
    )
    parser.add_argument(
        "--hold",
        action=argparse.BooleanOptionalAction,
        default=None,
        help="Keep console open when finished (useful on Windows double-click launch).",
    )
    parser.add_argument(
        "--auto-install-deps",
        action=argparse.BooleanOptionalAction,
        default=True,
        help="Auto-install missing dependencies when possible.",
    )
    parser.add_argument(
        "--open-mode",
        default="app",
        choices=["app", "popup", "tab", "none"],
        help="Browser opening mode. Default: app window.",
    )
    parser.add_argument(
        "--browser",
        default="auto",
        choices=["auto", "chrome", "chromium", "edge"],
        help="Preferred browser binary for app/popup modes.",
    )
    parser.add_argument(
        "--window-size",
        default="1280x860",
        help="Window size for app/popup mode as WIDTHxHEIGHT.",
    )
    parser.add_argument(
        "--window-pos",
        default="",
        help="Window position for app/popup mode as X,Y.",
    )
    parser.add_argument(
        "--wait-timeout-s",
        type=int,
        default=120,
        help="Max seconds to wait for the app to become reachable.",
    )
    parser.add_argument(
        "--wait-interval-ms",
        type=int,
        default=350,
        help="Polling interval in milliseconds while waiting for app response.",
    )
    parser.add_argument(
        "--run-checks",
        action="store_true",
        help="Run quick local checks and write an HTML report.",
    )
    parser.add_argument(
        "--down",
        action="store_true",
        help="Stop Docker full stack (`docker compose down`).",
    )
    parser.add_argument(
        "--db-mode",
        default="e2e",
        choices=["e2e", "live", "reset"],
        help="Database mode for full profile compatibility.",
    )
    parser.add_argument(
        "--force",
        action="store_true",
        help="Required for destructive operations (db-mode=reset).",
    )
    parser.add_argument(
        "--runtime-manager",
        action="store_true",
        help="Launch php_runtime_manager wizard.",
    )

    args = parser.parse_args()

    hold_open = args.hold
    if hold_open is None:
        hold_open = os.name == "nt" and len(sys.argv) == 1

    exit_code = 0
    try:
        if args.runtime_manager:
            return launch_runtime_manager()

        if args.down:
            return down_full_stack()

        run_mode = resolve_run_mode(args.profile)
        base_url = args.base_url.strip() or default_base_url(run_mode)
        window_size = parse_window_size(args.window_size)
        window_pos = parse_window_pos(args.window_pos)

        print(f"[mode] {run_mode}")
        print(f"[url] {base_url}")
        print(f"[open] mode={args.open_mode} browser={args.browser}")

        if args.db_mode != "e2e" and run_mode != "full":
            print("[warn] --db-mode is only used in full mode. Ignoring in basic mode.")

        if args.run_checks:
            exit_code = run_quick_checks(
                run_mode=run_mode,
                base_url=base_url,
                auto_install_deps=bool(args.auto_install_deps),
                db_mode=args.db_mode,
                force=bool(args.force),
                wait_timeout_s=max(10, int(args.wait_timeout_s)),
                wait_interval_ms=max(50, int(args.wait_interval_ms)),
            )
            return exit_code

        if run_mode == "full":
            exit_code = serve_full_ui(
                base_url,
                open_mode=args.open_mode,
                browser=args.browser,
                window_size=window_size,
                window_pos=window_pos,
                db_mode=args.db_mode,
                force=bool(args.force),
                wait_timeout_s=max(10, int(args.wait_timeout_s)),
                wait_interval_ms=max(50, int(args.wait_interval_ms)),
            )
        else:
            exit_code = serve_basic_ui(
                base_url,
                auto_install_deps=bool(args.auto_install_deps),
                open_mode=args.open_mode,
                browser=args.browser,
                window_size=window_size,
                window_pos=window_pos,
                wait_timeout_s=max(10, int(args.wait_timeout_s)),
                wait_interval_ms=max(50, int(args.wait_interval_ms)),
            )

    except RunnerError as exc:
        print(f"[error] {exc}")
        exit_code = 2
    except subprocess.CalledProcessError as exc:
        print(f"[error] Command failed ({exc.returncode}): {' '.join(exc.cmd)}")
        exit_code = 3
    finally:
        maybe_hold_console(bool(hold_open), exit_code)

    return exit_code


if __name__ == "__main__":
    sys.exit(main())
