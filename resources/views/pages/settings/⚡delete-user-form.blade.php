<?php

use Livewire\Component;

new class extends Component {}; ?>

<x-panel :title="__('Delete account')" :subtitle="__('Delete your account and all of its resources')">
    <div class="px-4 py-3">
        <flux:modal.trigger name="confirm-user-deletion">
            <flux:button variant="danger" size="sm" data-test="delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    <livewire:pages::settings.delete-user-modal />
</x-panel>
