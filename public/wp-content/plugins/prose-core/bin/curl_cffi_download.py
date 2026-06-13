#!/usr/bin/env python3
"""Browser-impersonating downloader for ProSe court form imports.

NY Courts (webfiles.nycourts.gov) sits behind Cloudflare, which blocks stock
curl and PHP HTTP clients by their TLS fingerprint (HTTP 403). curl_cffi
impersonates a real Chrome TLS/HTTP-2 fingerprint and is accepted.

Usage:
    curl_cffi_download.py <url> <dest_path>

Exit codes:
    0  success (file written, size > 0)
    1  usage error
    2  download failed
"""

from __future__ import annotations

import sys
import time


IMPERSONATE_TARGETS = ("chrome", "chrome124", "chrome110", "safari", "edge")
MAX_ATTEMPTS = 3
RETRY_SLEEP_SECONDS = 1.5
TIMEOUT_SECONDS = 120


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        sys.stderr.write("usage: curl_cffi_download.py <url> <dest_path>\n")
        return 1

    url = argv[1]
    dest = argv[2]

    try:
        from curl_cffi import requests  # noqa: PLC0415 - optional dependency
    except ImportError:
        sys.stderr.write(
            "curl_cffi is not installed. Run bin/setup-curl-impersonate.sh.\n"
        )
        return 2

    last_error = ""

    for attempt in range(1, MAX_ATTEMPTS + 1):
        target = IMPERSONATE_TARGETS[(attempt - 1) % len(IMPERSONATE_TARGETS)]

        try:
            response = requests.get(
                url,
                impersonate=target,
                timeout=TIMEOUT_SECONDS,
                allow_redirects=True,
            )
        except Exception as exc:  # noqa: BLE001 - report and retry
            last_error = f"{type(exc).__name__}: {exc}"
            time.sleep(RETRY_SLEEP_SECONDS)
            continue

        if response.status_code == 200 and response.content:
            try:
                with open(dest, "wb") as handle:
                    handle.write(response.content)
            except OSError as exc:
                sys.stderr.write(f"write failed: {exc}\n")
                return 2

            return 0

        last_error = f"HTTP {response.status_code}"
        time.sleep(RETRY_SLEEP_SECONDS)

    sys.stderr.write(f"curl_cffi download failed: {last_error}\n")
    return 2


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
