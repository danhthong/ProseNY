#!/usr/bin/env bash
# Set up a local curl_cffi virtualenv used by curl-impersonate-cffi.
#
# curl_cffi impersonates a browser TLS fingerprint so the ProSe importer can
# download court documents from Cloudflare-protected hosts (nycourts.gov).
#
# Usage:
#   bash bin/setup-curl-impersonate.sh
#
# After this completes, enable the downloader by activating the bundled
# mu-plugin (prose-curl-impersonate.php) or registering the
# prose_core_curl_binary filter to point at bin/curl-impersonate-cffi.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VENV_DIR="${SCRIPT_DIR}/.venv-curl-impersonate"

PYTHON_BIN="$(command -v python3 || true)"

if [ -z "${PYTHON_BIN}" ]; then
  echo "python3 is required but was not found on PATH." >&2
  exit 1
fi

if [ ! -d "${VENV_DIR}" ]; then
  echo "Creating virtualenv at ${VENV_DIR}"
  "${PYTHON_BIN}" -m venv "${VENV_DIR}"
fi

echo "Installing curl_cffi..."
"${VENV_DIR}/bin/pip" install --quiet --upgrade pip
"${VENV_DIR}/bin/pip" install --quiet curl_cffi

chmod +x "${SCRIPT_DIR}/curl-impersonate-cffi"

echo "Done. curl-impersonate-cffi is ready at:"
echo "  ${SCRIPT_DIR}/curl-impersonate-cffi"
echo
echo "Verifying against a court URL..."
if "${SCRIPT_DIR}/curl-impersonate-cffi" -sS -L -f --max-time 60 \
  -o /tmp/prose-curl-impersonate-test.bin \
  "https://webfiles.nycourts.gov/public/2025-12/sc-1.wpd"; then
  echo "OK: downloaded $(wc -c < /tmp/prose-curl-impersonate-test.bin) bytes."
  rm -f /tmp/prose-curl-impersonate-test.bin
else
  echo "WARNING: test download failed. Check network / Cloudflare status." >&2
fi
