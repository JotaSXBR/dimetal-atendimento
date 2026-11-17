<?php

return [

    /** Versão publicada (Coolify injeta `FLUXIE_VERSION`); vazia, vale a do build da imagem. */
    'version' => env('FLUXIE_VERSION'),

    /** Versão gravada na imagem (`ARG VERSION`): sempre `sha-…` (a Release só retaga a imagem) ou `dev` fora do Docker; não reconhece Release. */
    'build' => env('FLUXIE_BUILD', 'dev'),

    'releases_repo' => env('FLUXIE_RELEASES_REPO', 'fluxie-tech/fluxie-inbox'),

    'update_prerelease' => env('FLUXIE_UPDATE_PRERELEASE', false),

    'image' => 'ghcr.io/fluxie-tech/fluxie-inbox',

    /** UUID do Service desta instalação no Coolify (o próprio Coolify injeta `COOLIFY_RESOURCE_UUID`). */
    'resource_uuid' => env('COOLIFY_RESOURCE_UUID'),

];
