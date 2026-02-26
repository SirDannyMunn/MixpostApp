#!/usr/bin/env bash
set -euo pipefail

cd /home/ploi/api.meetreagan.com

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

cd /home/ploi/api.meetreagan.com/containers/browseruse

# Note: --scale must be SERVICE=NUM
# Use the prod compose file so workers can reach host-local Redis/Postgres.
docker compose -f docker-compose.prod.yml up -d --build --force-recreate --remove-orphans --scale main=3

echo "== BrowserUse worker diagnostics =="
docker compose -f docker-compose.prod.yml ps

for cid in $(docker compose -f docker-compose.prod.yml ps -q main); do
name=$(docker inspect -f '{{.Name}}' "$cid" | sed 's#^/##')
echo "=== $name ==="
docker exec "$cid" python -c "from src.config import load_settings; import redis, psycopg; s=load_settings(); redis.Redis.from_url(s.redis_url, socket_connect_timeout=2, socket_timeout=2).ping(); psycopg.connect(s.database_url, connect_timeout=2).close(); print('redis_ok'); print('db_ok')"
done

echo "🚀 Application deployed!"
