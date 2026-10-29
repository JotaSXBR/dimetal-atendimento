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

# Cabeçalhos de segurança também nos arquivos estáticos (ver o comentário do arquivo). O sed insere o include antes do
# `add_header Cache-Control` de cada location de assets e fontes; o build falha se não inserir exatamente duas vezes,
# para uma atualização da imagem base nunca remover a proteção em silêncio.
COPY docker/nginx/static-security-headers.conf /etc/nginx/static-security-headers.conf

RUN sed -i '/add_header Cache-Control "public, max-age=31536000, immutable";/i\    include /etc/nginx/static-security-headers.conf;' /etc/nginx/server-opts.d/performance.conf \
    && test "$(grep -c 'include /etc/nginx/static-security-headers.conf' /etc/nginx/server-opts.d/performance.conf)" = 2

USER www-data

EXPOSE 8080


# No Coolify, build por Dockerfile ignora o campo "start_command": o comando do container é o CMD da
# imagem. Por isso worker e scheduler são estágios próprios, escolhidos em "dockerfile_target_build".
FROM runtime AS worker

STOPSIGNAL SIGTERM

CMD ["php", "/var/www/html/artisan", "queue:work", "--queue=default", "--tries=3", "--timeout=120", "--max-time=3600"]


# Fila de saída: um único processo, para as mensagens de cada conversa saírem na ordem. O worker acima consome só
# `default` (ingestão, acks, tempo real) e nunca fica preso na espera do "digitando".
FROM runtime AS worker-outbound

STOPSIGNAL SIGTERM

CMD ["php", "/var/www/html/artisan", "queue:work", "--queue=outbound", "--tries=1", "--timeout=120", "--max-time=3600"]


FROM runtime AS reverb

STOPSIGNAL SIGTERM

CMD ["php", "/var/www/html/artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]


FROM runtime AS scheduler

STOPSIGNAL SIGTERM

CMD ["php", "/var/www/html/artisan", "schedule:work"]
