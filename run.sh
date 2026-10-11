#!/usr/bin/env bash
# Local development only; production uses Nginx and PHP-FPM.
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-6}"
exec "${UBCC_PHP_BIN:-php}" -S "${HOST:-127.0.0.1}:${PORT:-8080}" index.php
