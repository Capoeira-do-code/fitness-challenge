#!/usr/bin/env python3
"""PHP runtime installer/configurator for Fitness Challenge Tracker.

Goals:
- Help install a fast local PHP runtime with required extensions.
- Provide an interactive terminal UI (wizard mode).
- Generate optimized php.ini profiles for this project.
- Optionally launch the local app with the generated profile.
"""

from __future__ import annotations

import argparse
import json
import os
import platform
import shutil
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, Optional

ROOT = Path(__file__).resolve().parents[1]
PHP_RUNTIME_DIR = ROOT / "storage" / "runtime" / "php"
DEFAULT_REQUIRED_EXTENSIONS = [
    "pdo",
    "pdo_sqlite",
    "sqlite3",
    "gd",
    "fileinfo",
    "mbstring",
    "json",
    "openssl",
]

PROFILE_ALIASES = ("dev", "balanced", "max")


@dataclass
class RuntimeAudit:
    os_name: str
    package_manager: Optional[str]
    php_bin: Optional[str]
    php_version: Optional[str]
    extensions: list[str]
    missing_extensions: list[str]

    def to_dict(self) -> dict:
        return {
            "os_name": self.os_name,
            "package_manager": self.package_manager,
            "php_bin": self.php_bin,
            "php_version": self.php_version,
            "extensions": self.extensions,
            "missing_extensions": self.missing_extensions,
        }


def print_banner(title: str) -> None:
    line = "=" * max(36, len(title) + 6)
    print(line)
    print(f"  {title}")
    print(line)


def run_cmd(cmd: list[str]) -> subprocess.CompletedProcess:
    return subprocess.run(
        cmd,
        cwd=str(ROOT),
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )


def detect_os_name() -> str:
    system = platform.system().lower()
    if system == "darwin":
        return "macos"
    if system == "linux":
        return "linux"
    if system == "windows":
        return "windows"
    return system


def detect_package_manager(os_name: str) -> Optional[str]:
    if os_name == "macos":
        if shutil.which("brew"):
            return "brew"
        return None
    if os_name == "windows":
        if shutil.which("winget"):
            return "winget"
        if shutil.which("choco"):
            return "choco"
        if shutil.which("scoop"):
            return "scoop"
        return None
    if os_name == "linux":
        if shutil.which("apt-get"):
            return "apt"
        if shutil.which("dnf"):
            return "dnf"
        if shutil.which("yum"):
            return "yum"
        if shutil.which("pacman"):
            return "pacman"
        if shutil.which("zypper"):
            return "zypper"
        return None
    return None


def find_php_bin() -> Optional[str]:
    php = shutil.which("php")
    if php:
        return php
    for candidate in ("/opt/homebrew/bin/php", "/usr/local/bin/php"):
        if Path(candidate).exists() and os.access(candidate, os.X_OK):
            return candidate
    return None


def detect_php_version(php_bin: str) -> Optional[str]:
    cmd = [php_bin, "-r", "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION;"]
    completed = run_cmd(cmd)
    if completed.returncode != 0:
        return None
    value = (completed.stdout or "").strip()
    return value or None


def detect_php_extensions(php_bin: str) -> list[str]:
    completed = run_cmd([php_bin, "-m"])
    if completed.returncode != 0:
        return []
    rows = [(line or "").strip().lower() for line in (completed.stdout or "").splitlines()]
    return sorted({row for row in rows if row and row[0].isalpha() and "[" not in row})


def build_audit(required_extensions: Iterable[str]) -> RuntimeAudit:
    os_name = detect_os_name()
    package_manager = detect_package_manager(os_name)
    php_bin = find_php_bin()
    php_version = None
    extensions: list[str] = []
    missing_extensions = sorted({ext.strip().lower() for ext in required_extensions if ext.strip()})

    if php_bin:
        php_version = detect_php_version(php_bin)
        extensions = detect_php_extensions(php_bin)
        installed = set(extensions)
        missing_extensions = [ext for ext in missing_extensions if ext not in installed]

    return RuntimeAudit(
        os_name=os_name,
        package_manager=package_manager,
        php_bin=php_bin,
        php_version=php_version,
        extensions=extensions,
        missing_extensions=missing_extensions,
    )


def build_install_command(os_name: str, manager: Optional[str], php_series: str = "8.3") -> Optional[str]:
    if manager is None:
        return None

    if os_name == "macos" and manager == "brew":
        return (
            f"brew install php@{php_series} && "
            f"brew link --overwrite --force php@{php_series}"
        )

    if os_name == "windows":
        if manager == "winget":
            major_minor = php_series.replace(".", "")
            pkg = "PHP.PHP" if major_minor == "" else f"PHP.PHP.{php_series}"
            return (
                f"winget install --id {pkg} -e "
                "--accept-package-agreements --accept-source-agreements"
            )
        if manager == "choco":
            return "choco install php -y"
        if manager == "scoop":
            return "scoop install php"
        return None

    if os_name == "linux":
        if manager == "apt":
            return (
                "sudo apt-get update && "
                f"sudo apt-get install -y php{php_series}-cli php{php_series}-sqlite3 "
                f"php{php_series}-gd php{php_series}-mbstring php{php_series}-curl "
                f"php{php_series}-zip php{php_series}-opcache"
            )
        if manager == "dnf":
            return "sudo dnf install -y php-cli php-sqlite3 php-gd php-mbstring php-opcache php-zip"
        if manager == "yum":
            return "sudo yum install -y php-cli php-sqlite3 php-gd php-mbstring php-opcache php-zip"
        if manager == "pacman":
            return "sudo pacman -S --needed php php-sqlite php-gd"
        if manager == "zypper":
            return "sudo zypper install -y php8 php8-sqlite3 php8-gd php8-mbstring php8-opcache"
    return None


def render_php_ini(profile: str) -> str:
    profile_key = profile.strip().lower()
    if profile_key not in PROFILE_ALIASES:
        raise ValueError(f"Perfil no soportado: {profile}")

    base = {
        "expose_php": "Off",
        "max_execution_time": "120",
        "max_input_time": "120",
        "memory_limit": "512M",
        "post_max_size": "256M",
        "upload_max_filesize": "20M",
        "max_file_uploads": "200",
        "default_charset": '"UTF-8"',
        "date.timezone": '"UTC"',
        "realpath_cache_size": "4096K",
        "realpath_cache_ttl": "600",
        "output_buffering": "4096",
        "opcache.enable": "1",
        "opcache.enable_cli": "1",
        "opcache.memory_consumption": "192",
        "opcache.interned_strings_buffer": "24",
        "opcache.max_accelerated_files": "50000",
        "opcache.revalidate_freq": "2",
        "opcache.validate_timestamps": "1",
        "opcache.save_comments": "1",
        "opcache.jit_buffer_size": "96M",
        "opcache.jit": "tracing",
    }

    if profile_key == "dev":
        base["display_errors"] = "On"
        base["error_reporting"] = "E_ALL"
        base["opcache.validate_timestamps"] = "1"
        base["opcache.revalidate_freq"] = "1"
        base["opcache.jit_buffer_size"] = "64M"
    elif profile_key == "balanced":
        base["display_errors"] = "Off"
        base["log_errors"] = "On"
        base["error_reporting"] = "E_ALL & ~E_DEPRECATED & ~E_STRICT"
        base["opcache.validate_timestamps"] = "1"
        base["opcache.revalidate_freq"] = "2"
    else:
        base["display_errors"] = "Off"
        base["log_errors"] = "On"
        base["error_reporting"] = "E_ALL & ~E_DEPRECATED & ~E_STRICT"
        base["memory_limit"] = "768M"
        base["opcache.validate_timestamps"] = "0"
        base["opcache.revalidate_freq"] = "0"
        base["opcache.memory_consumption"] = "256"
        base["opcache.max_accelerated_files"] = "100000"
        base["opcache.jit_buffer_size"] = "128M"

    lines = [
        "; Generated by bin/php_runtime_manager.py",
        f"; Profile: {profile_key}",
        "",
    ]
    for key, value in base.items():
        lines.append(f"{key} = {value}")
    lines.append("")
    return "\n".join(lines)


def write_ini(profile: str) -> Path:
    PHP_RUNTIME_DIR.mkdir(parents=True, exist_ok=True)
    ini_path = PHP_RUNTIME_DIR / f"php-{profile}.ini"
    ini_path.write_text(render_php_ini(profile), encoding="utf-8")
    return ini_path


def build_serve_command(php_bin: str, ini_path: Path, host: str, port: int) -> str:
    return (
        f"{php_bin} -c {ini_path} "
        f"-S {host}:{port} -t public"
    )


def run_serve(php_bin: str, ini_path: Path, host: str, port: int) -> int:
    cmd = [
        php_bin,
        "-c",
        str(ini_path),
        "-S",
        f"{host}:{port}",
        "-t",
        "public",
    ]
    print(f"[serve] {' '.join(cmd)}")
    proc = subprocess.Popen(cmd, cwd=str(ROOT))
    try:
        proc.wait()
    except KeyboardInterrupt:
        print("\n[serve] Deteniendo servidor...")
    finally:
        if proc.poll() is None:
            proc.terminate()
            try:
                proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                proc.kill()
    return 0


def print_audit(audit: RuntimeAudit) -> None:
    print_banner("PHP Runtime Audit")
    print(f"OS:               {audit.os_name}")
    print(f"Package manager:  {audit.package_manager or 'n/a'}")
    print(f"PHP binary:       {audit.php_bin or 'not found'}")
    print(f"PHP version:      {audit.php_version or 'unknown'}")
    if audit.php_bin:
        print(f"Extensions:       {len(audit.extensions)} loaded")
        if audit.missing_extensions:
            print(f"Missing ext:      {', '.join(audit.missing_extensions)}")
        else:
            print("Missing ext:      none")


def choose_option(prompt: str, options: list[str], default_index: int = 0) -> int:
    print(prompt)
    for idx, opt in enumerate(options, start=1):
        marker = " (default)" if idx - 1 == default_index else ""
        print(f"  {idx}. {opt}{marker}")
    raw = input("> ").strip()
    if raw == "":
        return default_index
    if raw.isdigit():
        value = int(raw) - 1
        if 0 <= value < len(options):
            return value
    print("Entrada no valida, usando opcion por defecto.")
    return default_index


def run_wizard(required_extensions: Iterable[str], php_series: str, allow_exec: bool) -> int:
    audit = build_audit(required_extensions)
    print_audit(audit)
    print("")

    if audit.php_bin is None:
        install_cmd = build_install_command(audit.os_name, audit.package_manager, php_series=php_series)
        if install_cmd:
            print("[suggest] Comando recomendado para instalar PHP:")
            print(install_cmd)
            if allow_exec:
                idx = choose_option(
                    "Quieres ejecutarlo ahora?",
                    ["No", "Si, ejecutar instalacion"],
                    default_index=0,
                )
                if idx == 1:
                    completed = subprocess.run(install_cmd, shell=True, cwd=str(ROOT), check=False)
                    if completed.returncode != 0:
                        print(f"[error] Instalacion fallo con exit code {completed.returncode}")
                        return completed.returncode
                    print("[ok] Instalacion finalizada. Revisa `php -v` y vuelve a correr el wizard.")
            print("")
        else:
            print("[warn] No hay comando de instalacion automatico para este entorno.")
            print("")

    profile_idx = choose_option(
        "Selecciona perfil php.ini optimizado:",
        ["dev (debug rapido)", "balanced (recomendado)", "max (rendimiento)"],
        default_index=1,
    )
    profile = PROFILE_ALIASES[profile_idx]
    ini_path = write_ini(profile)
    print(f"[ok] php.ini generado en: {ini_path}")

    if audit.php_bin:
        host = os.environ.get("PHP_RUNTIME_HOST", "0.0.0.0")
        port_raw = os.environ.get("PHP_RUNTIME_PORT", "8080")
        try:
            port = int(port_raw)
        except ValueError:
            port = 8080
        cmd = build_serve_command(audit.php_bin, ini_path, host, port)
        print("[serve] Comando recomendado:")
        print(cmd)
        serve_idx = choose_option(
            "Quieres arrancar la app con este perfil ahora?",
            ["No", "Si, arrancar servidor"],
            default_index=0,
        )
        if serve_idx == 1:
            return run_serve(audit.php_bin, ini_path, host, port)
    else:
        print("[info] Generado solo el perfil. Instala PHP y usa `serve` despues.")

    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Installer/configurator para un runtime PHP rapido del proyecto."
    )
    parser.add_argument(
        "--required-ext",
        action="append",
        default=[],
        help="Extension requerida adicional (puede repetirse).",
    )
    parser.add_argument(
        "--php-series",
        default="8.3",
        help="Serie sugerida para instalacion (default: 8.3).",
    )
    parser.add_argument(
        "--allow-exec-install",
        action="store_true",
        help="Permite al wizard ejecutar comando de instalacion.",
    )

    subparsers = parser.add_subparsers(dest="command")

    subparsers.add_parser("wizard", help="UI interactiva dedicada (default).")

    audit_parser = subparsers.add_parser("audit", help="Audita runtime actual.")
    audit_parser.add_argument("--json", action="store_true", help="Salida JSON.")

    ini_parser = subparsers.add_parser("write-ini", help="Genera php.ini optimizado.")
    ini_parser.add_argument(
        "--profile",
        choices=PROFILE_ALIASES,
        default="balanced",
        help="Perfil de optimizacion.",
    )

    install_parser = subparsers.add_parser("print-install", help="Imprime comando de instalacion sugerido.")
    install_parser.add_argument(
        "--os",
        choices=["macos", "linux", "windows"],
        default=None,
        help="Override de sistema operativo.",
    )
    install_parser.add_argument(
        "--manager",
        default=None,
        help="Override de gestor de paquetes.",
    )

    serve_parser = subparsers.add_parser("serve", help="Arranca app con php.ini generado.")
    serve_parser.add_argument("--profile", choices=PROFILE_ALIASES, default="balanced")
    serve_parser.add_argument("--host", default="0.0.0.0")
    serve_parser.add_argument("--port", type=int, default=8080)

    return parser.parse_args()


def main() -> int:
    args = parse_args()
    required_extensions = DEFAULT_REQUIRED_EXTENSIONS + [ext.lower() for ext in args.required_ext if ext.strip()]
    command = args.command or "wizard"

    if command == "wizard":
        return run_wizard(required_extensions, php_series=args.php_series, allow_exec=args.allow_exec_install)

    if command == "audit":
        audit = build_audit(required_extensions)
        if args.json:
            print(json.dumps(audit.to_dict(), indent=2, ensure_ascii=False))
        else:
            print_audit(audit)
        return 0

    if command == "write-ini":
        ini_path = write_ini(args.profile)
        print(f"[ok] php.ini generado: {ini_path}")
        return 0

    if command == "print-install":
        os_name = args.os or detect_os_name()
        manager = args.manager or detect_package_manager(os_name)
        command_text = build_install_command(os_name, manager, php_series=args.php_series)
        if not command_text:
            print("[warn] No se pudo construir comando para este entorno.")
            return 1
        print(command_text)
        return 0

    if command == "serve":
        audit = build_audit(required_extensions)
        if not audit.php_bin:
            print("[error] No se encontro `php` en PATH.")
            return 1
        ini_path = write_ini(args.profile)
        print(f"[ok] Usando ini: {ini_path}")
        return run_serve(audit.php_bin, ini_path, args.host, args.port)

    print(f"[error] Comando no soportado: {command}")
    return 2


if __name__ == "__main__":
    sys.exit(main())
