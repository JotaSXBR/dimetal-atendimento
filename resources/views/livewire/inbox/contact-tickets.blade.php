<div>
    @if ($items !== [])
        @php($tz = config('app.display_timezone'))
        <div class="border-b border-line bg-canvas px-4 py-2 text-xs" role="region" aria-label="Outros tickets deste contato">
            <p class="font-semibold text-ink-2">Outros tickets deste contato</p>
            <ul class="mt-1 space-y-1">
                @foreach ($items as $item)
                    <li wire:key="contact-ticket-{{ $item['id'] }}" class="flex flex-wrap items-center gap-x-2 {{ $item['open'] && $item['otherNumber'] ? 'text-warn' : 'text-ink-2' }}">
                        @if ($item['link'])
                            <button type="button" wire:click="$parent.select({{ $item['id'] }})" class="font-mono font-semibold underline hover:text-ink">{{ $item['protocol'] }}</button>
                        @else
                            <span class="font-mono font-semibold">{{ $item['protocol'] }}</span>
                        @endif
                        <span>
                            @if ($item['open'] && $item['otherNumber'])
                                também tem ticket aberto no número {{ $item['number'] }} ({{ $item['status'] }})
                            @elseif ($item['open'])
                                {{ $item['status'] }}
                            @else
                                encerrado {{ $item['closedAt']?->timezone($tz)->format('d/m') }}@if ($item['otherNumber']) · número {{ $item['number'] }}@endif
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
