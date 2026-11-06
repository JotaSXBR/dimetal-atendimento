{{-- Claro / Escuro / Sistema: o Flux guarda a escolha no navegador (localStorage) e aplica a classe `dark` no <html>. --}}
<flux:menu.radio.group x-data x-model="$flux.appearance" data-test="appearance-switcher">
    <flux:menu.radio value="light">Claro</flux:menu.radio>
    <flux:menu.radio value="dark">Escuro</flux:menu.radio>
    <flux:menu.radio value="system">Sistema</flux:menu.radio>
</flux:menu.radio.group>
