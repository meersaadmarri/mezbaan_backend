#!/usr/bin/env bash
# Local API for phones on Wi‑Fi. `artisan serve` spawns `php -S` without your CLI `-d` flags — use PHP_INI_SCAN_DIR instead.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MEZBAN_EXTRA_INI="${ROOT}/dev-php-ini.d"

# Append our overrides so they load AFTER system conf.d (otherwise defaults win and uploads stay ~8M).
DEFAULT_SCAN="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR ?: "";')"
if [ -n "${PHP_INI_SCAN_DIR:-}" ]; then
  export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR}:${MEZBAN_EXTRA_INI}"
elif [ -n "$DEFAULT_SCAN" ]; then
  export PHP_INI_SCAN_DIR="${DEFAULT_SCAN}:${MEZBAN_EXTRA_INI}"
else
  export PHP_INI_SCAN_DIR="${MEZBAN_EXTRA_INI}"
fi

exec php artisan serve --host=0.0.0.0 --port=8000 "$@"
