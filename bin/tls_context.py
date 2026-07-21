"""Trusted outbound TLS helpers for the local Python workers.

Some python.org macOS builds expose an OpenSSL CA path before the bundled
certificate installer has created it.  curl and PHP still work because they
use the operating-system bundle at /etc/ssl/cert.pem.  These helpers select an
existing trusted bundle without ever disabling certificate verification.
"""

from __future__ import annotations

import os
import ssl
from pathlib import Path
from typing import MutableMapping, Optional


def trusted_ca_bundle_path() -> Optional[Path]:
    defaults = ssl.get_default_verify_paths()
    candidates = [
        os.environ.get("SSL_CERT_FILE", ""),
        defaults.cafile or "",
        defaults.openssl_cafile or "",
        "/etc/ssl/cert.pem",
        "/etc/ssl/certs/ca-certificates.crt",
        "/etc/pki/tls/certs/ca-bundle.crt",
        "/opt/homebrew/etc/openssl@3/cert.pem",
        "/opt/homebrew/etc/ca-certificates/cert.pem",
        "/usr/local/etc/openssl@3/cert.pem",
        "/usr/local/etc/ca-certificates/cert.pem",
    ]
    seen: set[str] = set()
    for candidate in candidates:
        value = str(candidate or "").strip()
        if not value or value in seen:
            continue
        seen.add(value)
        path = Path(value).expanduser()
        if path.is_file() and path.stat().st_size > 0:
            return path
    return None


def trusted_ssl_context() -> ssl.SSLContext:
    bundle = trusted_ca_bundle_path()
    if bundle is not None:
        try:
            return ssl.create_default_context(cafile=str(bundle))
        except (OSError, ssl.SSLError):
            pass
    return ssl.create_default_context()


def configure_tls_environment(
    environment: Optional[MutableMapping[str, str]] = None,
) -> MutableMapping[str, str]:
    target = environment if environment is not None else os.environ
    bundle = trusted_ca_bundle_path()
    if bundle is not None:
        for variable in ("SSL_CERT_FILE", "REQUESTS_CA_BUNDLE", "CURL_CA_BUNDLE"):
            configured = str(target.get(variable, "") or "").strip()
            if not configured or not Path(configured).expanduser().is_file():
                target[variable] = str(bundle)
    return target
