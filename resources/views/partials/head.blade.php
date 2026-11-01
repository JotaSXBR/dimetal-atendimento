<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<meta name="description" content="Atendimento via WhatsApp da {{ config('app.name', 'Laravel') }}">

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
<meta name="reverb-url" content="{{ config('broadcasting.connections.reverb.public_url') }}">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

@auth
    {{-- O servidor entrega as preferências; o módulo de alertas decide no navegador (resources/js/alerts.js). --}}
    <meta name="alert-user" content="{{ auth()->id() }}">
    <meta name="alert-settings" content="{{ json_encode(auth()->user()->alertSettings()->toArray(), JSON_THROW_ON_ERROR) }}">
@endauth
