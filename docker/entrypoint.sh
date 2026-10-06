#!/bin/sh
set -eu

export PORT="${PORT:-10000}"
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

mkdir -p \
    /app/backend/storage/app/private \
    /app/backend/storage/framework/cache/data \
    /app/backend/storage/framework/sessions \
    /app/backend/storage/framework/views \
    /app/backend/storage/logs \
    /app/backend/bootstrap/cache

chown -R www-data:www-data /app/backend/storage /app/backend/bootstrap/cache

cd /app/backend
php artisan config:cache
php artisan migrate --force --no-interaction
php artisan view:cache

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/valor.conf
