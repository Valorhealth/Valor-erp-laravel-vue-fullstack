FROM node:22-bookworm-slim AS frontend-build
WORKDIR /build/frontend
RUN corepack enable
COPY frontend/package.json frontend/pnpm-lock.yaml frontend/pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY frontend/ ./
RUN pnpm build

FROM composer:2 AS backend-dependencies
WORKDIR /build/backend
COPY backend/composer.json backend/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-req=ext-gd \
    --ignore-platform-req=ext-zip

FROM php:8.4-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        gettext-base \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
        nginx \
        python3 \
        python3-pip \
        python3-venv \
        supervisor \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl mbstring opcache pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

RUN python3 -m venv /opt/valor-venv
COPY backend/requirements-budget.txt /tmp/requirements-budget.txt
RUN /opt/valor-venv/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/valor-venv/bin/pip install --no-cache-dir -r /tmp/requirements-budget.txt

WORKDIR /app
COPY backend/ /app/backend/
COPY --from=backend-dependencies /build/backend/vendor /app/backend/vendor
COPY --from=frontend-build /build/frontend/dist /app/frontend/dist
COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/valor.conf
COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/valor-uploads.ini
COPY docker/php-opcache.ini /usr/local/etc/php/conf.d/valor-opcache.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-valor-pool.conf
COPY docker/entrypoint.sh /usr/local/bin/valor-entrypoint

RUN chmod +x /usr/local/bin/valor-entrypoint \
    && rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf \
    && cd /app/backend \
    && rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PYTHON_BINARY=/opt/valor-venv/bin/python \
    PORT=10000

EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/valor-entrypoint"]
