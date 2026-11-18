@props(['route', 'heading' => '', 'subheading' => ''])

{{-- Minha conta no mesmo arranjo das Configurações: menu à esquerda e o título da aba no cabeçalho da página. --}}
<x-settings-page :route="$route" :title="$heading" :subtitle="$subheading" :groups="app(\App\Support\Navigation::class)->accountGroups()" label="Minha conta">
    {{-- O x-page-header não tem margem de baixo; o conteúdo da aba desce os 24 px do desenho. --}}
    <div class="mt-6">{{ $slot }}</div>
</x-settings-page>
