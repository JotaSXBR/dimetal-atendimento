<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Aparência')] class extends Component {
    //
}; ?>

<section class="w-full">
    <x-pages::settings.layout route="appearance.edit" :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <x-panel title="Tema">
            <div class="p-4">
                {{-- No celular os três com ícone passam da largura do painel; o ícone sai abaixo de sm. --}}
                <flux:radio.group x-data variant="segmented" size="sm" x-model="$flux.appearance" class="sm:w-fit">
                    <flux:radio value="light" icon="sun" class="max-sm:[&_[data-flux-icon]]:hidden">{{ __('Light') }}</flux:radio>
                    <flux:radio value="dark" icon="moon" class="max-sm:[&_[data-flux-icon]]:hidden">{{ __('Dark') }}</flux:radio>
                    <flux:radio value="system" icon="computer-desktop" class="max-sm:[&_[data-flux-icon]]:hidden">{{ __('System') }}</flux:radio>
                </flux:radio.group>
            </div>
        </x-panel>
    </x-pages::settings.layout>
</section>
