# syntax=docker/dockerfile:1.7

# Base do PHP com as extensões já compiladas. Publicada em ghcr.io/jotasxbr/dimetal-php-base (workflow base-image) para o
# deploy não recompilar intl, gd e bcmath a cada build (~95 s). O Dockerfile do app só usa esta imagem.
FROM serversideup/php:8.5.12-fpm-nginx-trixie

# Liga o pacote do GHCR a este repositório (permissões e visibilidade).
LABEL org.opencontainers.image.source="https://github.com/JotaSXBR/dimetal-atendimento"

USER root

RUN install-php-extensions pdo_pgsql intl zip gd bcmath redis

# A imagem de cima (serversideup) é publicada antes das correções de segurança do Debian chegarem (CVEs HIGH em apt e
# linux-libc-dev já saíram assim). Atualizar os pacotes aqui fecha a janela; o ARG muda a cada execução do workflow e quebra
# o cache só desta camada, então o upgrade nunca é reaproveitado velho.
ARG SECURITY_REFRESH
RUN apt-get update -qq \
    && DEBIAN_FRONTEND=noninteractive apt-get upgrade -y -qq \
    && rm -rf /var/lib/apt/lists/*

USER www-data
