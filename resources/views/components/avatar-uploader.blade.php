@props([
    'model' => 'photo',
    'action' => 'saveAvatar',
    'current' => null,
    'name' => '',
    'shape' => 'round',
    'single' => false,
])

{{-- Escolhe a imagem, abre o enquadramento (arrastar/aproximar) e envia só o recorte. Ver resources/js/avatar-cropper.js.
     `model` é a propriedade `$photo` do componente (trait ReceivesAvatar); `action` é o método que grava depois do envio. --}}
<div x-data="avatarCropper(@js($model), @js($action))" data-testid="avatar-uploader" {{ $attributes->class('flex flex-col gap-3') }}>
    <div class="flex flex-wrap items-center gap-4">
        <x-avatar :src="$current" :name="$name" :shape="$shape" :single="$single" size="xl" decorative />

        <div class="flex flex-wrap items-center gap-2">
            <flux:button size="sm" type="button" icon="photo" x-on:click="$refs.file.click()">Escolher imagem</flux:button>
            {{ $slot }}
        </div>

        <input x-ref="file" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)">
    </div>

    <p x-show="error && ! open" x-cloak x-text="error" class="text-sm text-danger" role="alert"></p>
    @php $serverError = isset($errors) ? $errors->first($model) : ''; @endphp
    @if ($serverError !== '')
        <p class="text-sm text-danger" role="alert">{{ $serverError }}</p>
    @endif

    <div x-show="open" x-cloak class="w-full max-w-sm rounded-xl border border-line bg-surface p-3">
        <div
            x-ref="stage"
            tabindex="0"
            role="group"
            aria-label="Área de enquadramento: arraste a imagem ou use as setas do teclado para mover"
            class="mx-auto aspect-square w-full touch-none overflow-hidden rounded-lg bg-canvas"
            x-on:keydown.left.prevent="move(-nudge(), 0)"
            x-on:keydown.right.prevent="move(nudge(), 0)"
            x-on:keydown.up.prevent="move(0, -nudge())"
            x-on:keydown.down.prevent="move(0, nudge())"
        ></div>

        <div class="mt-3 flex flex-wrap items-center justify-center gap-1.5">
            <flux:button size="sm" type="button" icon="minus" x-on:click="zoom(-1)">Afastar</flux:button>
            <flux:button size="sm" type="button" icon="plus" x-on:click="zoom(1)">Aproximar</flux:button>
            <flux:button size="sm" type="button" icon="arrow-left" x-on:click="move(-nudge(), 0)" aria-label="Mover para a esquerda" />
            <flux:button size="sm" type="button" icon="arrow-right" x-on:click="move(nudge(), 0)" aria-label="Mover para a direita" />
            <flux:button size="sm" type="button" icon="arrow-up" x-on:click="move(0, -nudge())" aria-label="Mover para cima" />
            <flux:button size="sm" type="button" icon="arrow-down" x-on:click="move(0, nudge())" aria-label="Mover para baixo" />
        </div>

        <p x-show="error" x-text="error" class="mt-2 text-center text-sm text-danger" role="alert"></p>

        <div class="mt-3 flex justify-end gap-2">
            <flux:button size="sm" type="button" variant="ghost" x-on:click="close()" x-bind:disabled="busy">Cancelar</flux:button>
            <flux:button size="sm" type="button" variant="primary" x-on:click="confirm()" x-bind:disabled="busy">
                <span x-show="! busy">Usar esta foto</span>
                <span x-show="busy" x-cloak>Enviando…</span>
            </flux:button>
        </div>
    </div>
</div>
