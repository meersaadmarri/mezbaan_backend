#!/usr/bin/env bash
# Production entrypoint (Railway, Render, Fly.io, Docker).
set -euo pipefail

PORT="${PORT:-8080}"

if [ -z "${APP_KEY:-}" ]; then
  echo "ERROR: APP_KEY is not set. Run: php artisan key:generate --show"
  exit 1
fi

php artisan migrate --force
php artisan storage:link --force 2>/dev/null || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Mezban API listening on 0.0.0.0:${PORT}"
exec php artisan serve --host=0.0.0.0 --port="${PORT}"
