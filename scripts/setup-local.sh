#!/usr/bin/env bash
# Brings a fresh clone to a working local app in one command.
set -Eeuo pipefail

cd "$(dirname "$0")/.."

say() { printf '\n\033[1;36m==>\033[0m %s\n' "$1"; }

say "Preparing environment files"
[ -f .env ] || cp .env.example .env
[ -f backend/.env ] || cp backend/.env.example backend/.env

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  say "Starting containers"
  docker compose up -d postgres redis

  say "Waiting for PostgreSQL"
  until docker compose exec -T postgres pg_isready >/dev/null 2>&1; do sleep 1; done

  run_backend() { docker compose run --rm backend "$@"; }
else
  echo "Docker not found — using the PHP and PostgreSQL already on this machine."
  run_backend() { (cd backend && "$@"); }
fi

say "Installing backend dependencies"
run_backend composer install --no-interaction --prefer-dist

say "Generating application key"
grep -q '^APP_KEY=base64' backend/.env || run_backend php artisan key:generate --force

say "Running migrations"
run_backend php artisan migrate --force

say "Seeding demo data"
run_backend php artisan db:seed --force

say "Installing frontend dependencies"
(cd frontend && npm install)

cat <<'EOF'

  Setup complete.

    API       http://localhost:8000
    SPA       http://localhost:5173
    Health    http://localhost:8000/api/health

  Demo sign-in details are in README-DEMO.md.

  Without Docker, start the two servers yourself:
    (cd backend && php artisan serve)
    (cd frontend && npm run dev)

EOF
