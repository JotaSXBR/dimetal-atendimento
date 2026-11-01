@props(['heading' => '', 'subheading' => '', 'wide' => false])

<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="Minha conta">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>Perfil</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>Segurança</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>Aparência</flux:navlist.item>
            <flux:navlist.item :href="route('alerts.edit')" wire:navigate>Meus alertas</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full {{ $wide ? 'max-w-5xl' : 'max-w-lg' }}">
            {{ $slot }}
        </div>
    </div>
</div>
