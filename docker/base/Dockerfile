# syntax=docker/dockerfile:1.7

# Base do PHP com as extensões já compiladas. Publicada em ghcr.io/jotasxbr/dimetal-php-base (workflow base-image) para o
# deploy não recompilar intl, gd e bcmath a cada build (~95 s). O Dockerfile do app só usa esta imagem.
FROM serversideup/php:8.5.12-fpm-nginx-bookworm

# Liga o pacote do GHCR a este repositório (permissões e visibilidade).
LABEL org.opencontainers.image.source="https://github.com/JotaSXBR/dimetal-atendimento"

USER root

RUN install-php-extensions pdo_pgsql intl zip gd bcmath redis

USER www-data
