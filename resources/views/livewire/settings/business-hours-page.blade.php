<x-settings-page route="configuracoes.horario" title="Horário e SLA" subtitle="Faixas por dia, feriados, resposta fora do horário e prazo de primeira resposta">
    @php
        // Em `@php` o `{{` fica literal; num atributo de componente o Blade o tomaria por eco.
        $afterHoursSubtitle = 'Enviada uma vez por ticket, só quando o cliente escreve primeiro e ninguém da equipe respondeu. Variáveis: {{horario}} e {{proximo_horario}}.';
    @endphp

    <div class="mt-6 space-y-5">
        <x-panel title="Faixas de atendimento" subtitle="Várias faixas no mesmo dia permitem pausa de almoço. Dia sem faixa fica fechado. Sem nenhuma faixa, o atendimento conta como sempre aberto.">
            <form wire:submit="saveHours">
                @foreach (\App\Livewire\Settings\BusinessHoursPage::WEEKDAYS as $weekday => $label)
                    <div class="flex flex-wrap items-start gap-x-4 gap-y-2 border-b border-line px-4 py-3" wire:key="day-{{ $weekday }}">
                        <p data-day-name class="w-28 shrink-0 pt-1.5 text-sm font-medium text-ink">{{ $label }}</p>

                        <div data-day-windows class="order-last w-full min-w-0 space-y-2 sm:order-none sm:w-auto sm:flex-1">
                            @forelse ($days[$weekday] ?? [] as $index => $window)
                                <div class="flex items-center gap-2" wire:key="day-{{ $weekday }}-{{ $index }}">
                                    <flux:input type="time" wire:model="days.{{ $weekday }}.{{ $index }}.opens" aria-label="{{ $label }}: abre às" />
                                    <span class="text-xs text-ink-3">até</span>
                                    <flux:input type="time" wire:model="days.{{ $weekday }}.{{ $index }}.closes" aria-label="{{ $label }}: fecha às" />
                                    <flux:button type="button" size="xs" variant="subtle" icon="x-mark" wire:click="removeWindow({{ $weekday }}, {{ $index }})" aria-label="Remover faixa" />
                                </div>
                            @empty
                                <p class="pt-1.5 text-xs text-ink-3">Fechado</p>
                            @endforelse

                            @error('days.'.$weekday)
                                <p class="text-xs text-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <flux:button class="ms-auto" type="button" size="xs" variant="subtle" icon="plus" wire:click="addWindow({{ $weekday }})">Faixa</flux:button>
                    </div>
                @endforeach

                <div data-panel-footer class="flex items-center gap-3 px-4 py-3">
                    <flux:button type="submit" variant="primary" size="sm">Salvar horário</flux:button>
                    @if (session('saved'))
                        <p class="text-xs text-ok" role="status">{{ session('saved') }}</p>
                    @endif
                </div>
            </form>
        </x-panel>

        <x-panel title="Feriados" subtitle="Nas datas abaixo o atendimento fica fechado o dia todo.">
            <form wire:submit="addHoliday" class="flex flex-wrap items-end gap-2 border-b border-line p-4">
                <div>
                    <flux:input type="date" wire:model="holidayDate" label="Data" />
                    @error('holidayDate')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>
                <div class="min-w-40 flex-1">
                    <flux:input wire:model="holidayName" label="Nome" maxlength="120" />
                    @error('holidayName')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>
                <flux:button type="submit" size="sm">Adicionar</flux:button>
            </form>

            <ul class="text-sm">
                @forelse ($holidays as $holiday)
                    <li class="flex items-center justify-between gap-3 border-b border-line px-4 py-3 last:border-b-0" wire:key="holiday-{{ $holiday->id }}">
                        <span><span class="font-mono text-xs text-ink-3">{{ $holiday->date->format('d/m/Y') }}</span> · {{ $holiday->name }}</span>
                        <flux:button type="button" size="xs" variant="subtle" icon="trash" wire:click="removeHoliday({{ $holiday->id }})" data-confirm="Remover este feriado?" data-confirm-label="Remover" data-confirm-tone="danger" aria-label="Remover feriado" />
                    </li>
                @empty
                    <li class="px-4 py-3 text-xs text-ink-3">Nenhum feriado cadastrado.</li>
                @endforelse
            </ul>
        </x-panel>

        <x-panel title="Prazo de primeira resposta (SLA)" subtitle="Minutos de funcionamento até a primeira resposta humana. Aparece nos relatórios e nos alertas de espera.">
            <form wire:submit="saveSla">
                <div class="max-w-xs p-4">
                    <flux:input type="number" min="1" max="1440" label="Minutos" wire:model="slaMinutes" />
                    @error('slaMinutes')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div data-panel-footer class="flex items-center gap-3 border-t border-line px-4 py-3">
                    <flux:button type="submit" variant="primary" size="sm">Salvar prazo</flux:button>
                    @if (session('saved-sla'))
                        <p class="text-xs text-ok" role="status">{{ session('saved-sla') }}</p>
                    @endif
                </div>
            </form>
        </x-panel>

        <x-panel title="Resposta automática fora do horário" :subtitle="$afterHoursSubtitle">
            <form wire:submit="saveMessage">
                <div data-switch-row class="border-b border-line px-4 py-3">
                    <flux:switch wire:model="afterHoursEnabled" label="Responder fora do horário" />
                </div>

                <div class="p-4">
                    <flux:textarea wire:model="afterHoursMessage" rows="4" label="Texto (em branco usa o padrão)" />
                    @error('afterHoursMessage')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror

                    <p class="mt-3 text-xs text-ink-3">Prévia agora</p>
                    <p class="mt-1 rounded-md bg-accent-soft px-3 py-2 text-sm whitespace-pre-wrap text-ink">{{ $preview }}</p>
                </div>

                <div data-panel-footer class="flex items-center gap-3 border-t border-line px-4 py-3">
                    <flux:button type="submit" variant="primary" size="sm">Salvar resposta</flux:button>
                    @if (session('saved-message'))
                        <p class="text-xs text-ok" role="status">{{ session('saved-message') }}</p>
                    @endif
                </div>
            </form>
        </x-panel>
    </div>
</x-settings-page>
