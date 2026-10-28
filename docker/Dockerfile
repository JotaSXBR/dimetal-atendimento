# syntax=docker/dockerfile:1.7

# Base: nginx + php-fpm no mesmo container, unprivileged por padrão.
# O Vite precisa de vendor/livewire/flux/dist/flux.css, então o Composer roda antes dos assets.
FROM serversideup/php:8.5.12-fpm-nginx-bookworm AS base

# Produção por padrão: sem isso a imagem avisa a cada boot que o OPcache está off.
# Código é imutável (vem da imagem), então o OPcache nunca precisa revalidar timestamps.
# SHOW_WELCOME_MESSAGE=false desliga o banner ASCII e os avisos de tuning no log do container.
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_MEMORY_LIMIT=256M \
    SHOW_WELCOME_MESSAGE=false

USER root

RUN install-php-extensions pdo_pgsql intl zip gd bcmath redis

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

COPY --chown=www-data:www-data . .

RUN php artisan package:discover --ansi \
    && php artisan vendor:publish --tag=laravel-assets --ansi --force \
    && chown -R www-data:www-data /var/www/html

USER www-data


# Assets: Node 24 gera public/build (Vite + Tailwind + fontes do Bunny).
FROM node:24-alpine AS assets

WORKDIR /build

COPY package.json package-lock.json ./

RUN npm ci --no-audit --no-fund

COPY . .

COPY --from=base /var/www/html/vendor ./vendor

RUN npm run build


FROM base AS runtime

USER root

COPY --from=assets --chown=www-data:www-data /build/public/build ./public/build

RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8080
