<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout heading="Horário de funcionamento" subheading="Faixas por dia, feriados e a resposta automática fora do horário">
        <div class="space-y-6">
            <form wire:submit="saveHours" class="rounded-[14px] border border-line bg-surface p-5">
                <p class="text-sm font-semibold text-ink">Faixas de atendimento</p>
                <p class="mt-1 text-xs text-ink-3">Várias faixas no mesmo dia permitem pausa de almoço. Dia sem faixa fica fechado. Sem nenhuma faixa, o atendimento conta como sempre aberto.</p>

                <div class="mt-4 space-y-4">
                    @foreach (\App\Livewire\Settings\BusinessHoursPage::WEEKDAYS as $weekday => $label)
                        <div wire:key="day-{{ $weekday }}">
                            <div class="flex items-center justify-between">
                                <p class="text-sm text-ink">{{ $label }}</p>
                                <flux:button type="button" size="xs" variant="subtle" icon="plus" wire:click="addWindow({{ $weekday }})">Faixa</flux:button>
                            </div>

                            @forelse ($days[$weekday] ?? [] as $index => $window)
                                <div class="mt-2 flex items-center gap-2" wire:key="day-{{ $weekday }}-{{ $index }}">
                                    <flux:input type="time" wire:model="days.{{ $weekday }}.{{ $index }}.opens" aria-label="{{ $label }}: abre às" />
                                    <span class="text-xs text-ink-3">até</span>
                                    <flux:input type="time" wire:model="days.{{ $weekday }}.{{ $index }}.closes" aria-label="{{ $label }}: fecha às" />
                                    <flux:button type="button" size="xs" variant="subtle" icon="x-mark" wire:click="removeWindow({{ $weekday }}, {{ $index }})" aria-label="Remover faixa" />
                                </div>
                            @empty
                                <p class="mt-1 text-xs text-ink-3">Fechado</p>
                            @endforelse

                            @error('days.'.$weekday)
                                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <flux:button type="submit" variant="primary" size="sm">Salvar horário</flux:button>
                    @if (session('saved'))
                        <p class="text-xs text-accent" role="status">{{ session('saved') }}</p>
                    @endif
                </div>
            </form>

            <div class="rounded-[14px] border border-line bg-surface p-5">
                <p class="text-sm font-semibold text-ink">Feriados</p>
                <p class="mt-1 text-xs text-ink-3">Nas datas abaixo o atendimento fica fechado o dia todo.</p>

                <form wire:submit="addHoliday" class="mt-3 flex flex-wrap items-end gap-2">
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

                <ul class="mt-3 divide-y divide-line text-sm">
                    @forelse ($holidays as $holiday)
                        <li class="flex items-center justify-between py-2" wire:key="holiday-{{ $holiday->id }}">
                            <span><span class="font-mono text-xs text-ink-3">{{ $holiday->date->format('d/m/Y') }}</span> · {{ $holiday->name }}</span>
                            <flux:button type="button" size="xs" variant="subtle" icon="trash" wire:click="removeHoliday({{ $holiday->id }})" wire:confirm="Remover este feriado?" aria-label="Remover feriado" />
                        </li>
                    @empty
                        <li class="py-2 text-xs text-ink-3">Nenhum feriado cadastrado.</li>
                    @endforelse
                </ul>
            </div>

            <form wire:submit="saveMessage" class="rounded-[14px] border border-line bg-surface p-5">
                <p class="text-sm font-semibold text-ink">Resposta automática fora do horário</p>
                <p class="mt-1 text-xs text-ink-3">Enviada uma vez por ticket, só quando o cliente escreve primeiro e ninguém da equipe respondeu. Variáveis: <code>@{{horario}}</code> e <code>@{{proximo_horario}}</code>.</p>

                <div class="mt-3">
                    <flux:switch wire:model="afterHoursEnabled" label="Responder fora do horário" />
                </div>

                <div class="mt-3">
                    <flux:textarea wire:model="afterHoursMessage" rows="4" label="Texto (em branco usa o padrão)" />
                    @error('afterHoursMessage')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <p class="mt-3 text-xs text-ink-3">Prévia agora</p>
                <p class="mt-1 rounded-md bg-accent-soft px-3 py-2 text-sm whitespace-pre-wrap text-ink">{{ $preview }}</p>

                <div class="mt-4 flex items-center gap-3">
                    <flux:button type="submit" variant="primary" size="sm">Salvar resposta</flux:button>
                    @if (session('saved-message'))
                        <p class="text-xs text-accent" role="status">{{ session('saved-message') }}</p>
                    @endif
                </div>
            </form>
        </div>
    </x-pages::settings.layout>
</section>
