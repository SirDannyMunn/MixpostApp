#!/usr/bin/env bash
set -euo pipefail

git pull origin main
git submodule sync --recursive
git submodule update --init --recursive

php artisan optimize:clear

composer install --no-interaction --prefer-dist --optimize-autoloader \
|| composer install --no-interaction --prefer-source --optimize-autoloader

echo "" | sudo -S service php8.4-fpm reload

php artisan migrate --force

php artisan queue:restart

docker compose up -d redis

echo "🚀 Application deployed!"
