<div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6">
    <div>
        <h1 class="text-xl font-semibold tracking-tight">Primeiros passos</h1>
        <p class="mt-1 text-sm text-ink-2">Três passos rápidos para deixar a instalação pronta.</p>
    </div>

    <ol class="grid grid-cols-3 gap-2" aria-label="Passos">
        @foreach (['empresa' => 'Empresa', 'segmento' => 'Segmento', 'canal' => 'Canal'] as $key => $label)
            @php($current = $step === $key)
            <li @if ($current) aria-current="step" @endif class="flex items-center gap-2 rounded-[10px] border px-3 py-2 text-sm {{ $current ? 'border-accent bg-accent-soft font-semibold text-accent-content' : 'border-line text-ink-3' }}">
                <span class="flex size-6 shrink-0 items-center justify-center rounded-full border border-current text-xs">{{ $loop->iteration }}</span>
                {{ $label }}
            </li>
        @endforeach
    </ol>

    @if ($step === 'empresa')
        <section class="space-y-3 rounded-[14px] border border-line bg-surface p-5" aria-labelledby="logo-title">
            <div>
                <p id="logo-title" class="text-sm font-semibold text-ink">Logotipo</p>
                <p class="text-xs text-ink-3">Opcional. Sem logotipo, usamos a inicial do nome.</p>
            </div>

            <x-avatar-uploader model="photo" action="saveLogo" :current="\App\Settings\CompanySettings::logoUrl()" :name="$name" shape="rounded" single accent>
                @if (\App\Settings\CompanySettings::logoUrl())
                    <flux:button size="sm" type="button" variant="ghost" icon="trash" wire:click="removeLogo" data-confirm="Remover o logotipo da empresa?" data-confirm-label="Remover" data-confirm-tone="danger">Remover</flux:button>
                @endif
            </x-avatar-uploader>
        </section>

        <form wire:submit="saveCompany" class="space-y-4 rounded-[14px] border border-line bg-surface p-5">
            <flux:input label="Nome fantasia" wire:model="name" autofocus />

            <flux:button type="submit" variant="primary">Continuar</flux:button>
        </form>
    @elseif ($step === 'segmento')
        <section class="space-y-4 rounded-[14px] border border-line bg-surface p-5">
            <div>
                <p class="text-sm font-semibold text-ink">Segmento</p>
                <p class="text-xs text-ink-3">Distribuidora já traz setores, etiquetas e o Atendimento padrão. Em branco começa sem nada.</p>
            </div>

            @error('segment')
                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
            @enderror

            <div class="grid gap-3 sm:grid-cols-2">
                <flux:button type="button" variant="primary" wire:click="chooseSegment('distribuidora')" wire:loading.attr="disabled">Distribuidora</flux:button>
                <flux:button type="button" wire:click="chooseSegment('em_branco')" wire:loading.attr="disabled">Em branco</flux:button>
            </div>
        </section>
    @else
        @if ($channel === null)
            <div class="space-y-3 rounded-[14px] border border-line bg-surface p-5">
                <div>
                    <p class="text-sm font-semibold text-ink">Conectar o WhatsApp</p>
                    <p class="text-xs text-ink-3">Cada número tem uma sessão no WAHA. Depois de criar, use Conectar no cartão do número.</p>
                </div>

                <form wire:submit="createChannel" class="flex items-end gap-2">
                    <div class="flex-1">
                        <flux:input wire:model="sessionName" label="Nome da sessão" placeholder="principal" />
                    </div>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Criar canal</flux:button>
                </form>
            </div>
        @else
            <livewire:settings.channel-card :channel="$channel" :key="'channel-'.$channel->id" />
            <p class="text-xs text-ink-3">Quando o número conectar, você vai para a fila automaticamente.</p>
        @endif

        <div>
            <flux:button type="button" variant="ghost" wire:click="skip">Pular, conecto depois</flux:button>
        </div>
    @endif
</div>
