<?php

return [

    /** Versão publicada (Coolify injeta `FLUXIE_VERSION`); vazia, vale a do build da imagem. */
    'version' => env('FLUXIE_VERSION'),

    /** Versão gravada na imagem (`ARG VERSION`): `sha-…` no beta, `dev` fora do Docker. */
    'build' => env('FLUXIE_BUILD', 'dev'),

    'releases_repo' => env('FLUXIE_RELEASES_REPO', 'fluxie-tech/fluxie-inbox'),

    'update_prerelease' => env('FLUXIE_UPDATE_PRERELEASE', false),

    'image' => 'ghcr.io/fluxie-tech/fluxie-inbox',

    /** UUID do Service desta instalação no Coolify (o compose da Release o declara). */
    'resource_uuid' => env('COOLIFY_RESOURCE_UUID'),

];
