#!/usr/bin/env bash
# Production entrypoint (Railway, Render, Fly.io, Docker).
set -euo pipefail

PORT="${PORT:-8080}"

# Render sets DATABASE_URL; Laravel reads DB_URL. Drop localhost defaults so URL wins.
if [ -n "${DATABASE_URL:-}" ]; then
  export DB_URL="${DATABASE_URL}"
  export DB_CONNECTION="${DB_CONNECTION:-pgsql}"
  unset DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET || true
  echo "Database: using DATABASE_URL (Render Postgres)."
elif [ -n "${DB_URL:-}" ]; then
  export DB_CONNECTION="${DB_CONNECTION:-pgsql}"
  unset DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET || true
  echo "Database: using DB_URL."
fi

if [ -z "${DB_URL:-}${DATABASE_URL:-}" ] && [ "${DB_CONNECTION:-}" = "pgsql" ]; then
  echo "ERROR: Postgres selected but DATABASE_URL / DB_URL is missing."
  echo "Render → Postgres → copy Internal Database URL → web service Environment → DATABASE_URL"
  exit 1
fi

if [ -z "${APP_KEY:-}" ]; then
  echo "ERROR: APP_KEY is not set."
  echo "Render → your service → Environment → Add:"
  echo "  APP_KEY = (run locally: php artisan key:generate --show)"
  exit 1
fi

php artisan migrate --force
php artisan storage:link --force 2>/dev/null || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Mezban API listening on 0.0.0.0:${PORT}"
exec php artisan serve --host=0.0.0.0 --port="${PORT}"
