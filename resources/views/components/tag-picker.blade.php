@props(['tags', 'label', 'method' => null, 'model' => null, 'selected' => null, 'placeholder' => 'Adicionar etiqueta…', 'grouped' => false])

{{-- Seletor de etiquetas com busca (visual inspirado no select com busca do Flux Pro; código próprio). Dois modos: `method` aplica a etiqueta
     escolhida chamando `$wire.<method>(id)`; `model` é filtro de escolha única (`$wire.set(<model>, id)`, "Todas" limpa). A lista vem em `data-tags`.
     O estado e o teclado vivem em `Alpine.data('tagPicker')` (resources/js/tag-picker.js): o build CSP não aceita método dentro do atributo. --}}
@if ($tags->isNotEmpty())
    @php
        $items = $tags->map(fn ($tag) => [
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color->value,
            'group' => $grouped ? ($tag->scope === \App\Enums\TagScope::CONTATO ? 'Do contato' : 'Da conversa') : '',
        ])->values();
        $hint = ($selected === null ? null : $tags->firstWhere('id', $selected)?->name) ?? $placeholder;
        $listId = 'tag-picker-'.substr(md5($label.$method.$model), 0, 8);
    @endphp
    <div
        x-data="tagPicker"
        data-tags="{{ $items->toJson(JSON_UNESCAPED_UNICODE) }}"
        @if ($method) data-method="{{ $method }}" @endif
        @if ($model) data-model="{{ $model }}" data-clear="{{ $placeholder }}" @endif
        data-list-id="{{ $listId }}"
        x-on:keydown.escape="escape"
        x-on:focusout="leave"
        {{ $attributes->class('relative') }}
    >
        <input
            type="text"
            role="combobox"
            autocomplete="off"
            aria-autocomplete="list"
            aria-haspopup="listbox"
            aria-controls="{{ $listId }}"
            aria-label="{{ $label }}"
            placeholder="{{ $hint }}"
            x-ref="field"
            x-model="query"
            x-on:focus="show"
            x-on:click="show"
            x-on:input="typed"
            x-on:keydown.arrow-down.prevent="next"
            x-on:keydown.arrow-up.prevent="prev"
            x-on:keydown.enter.prevent="choose"
            x-bind:aria-expanded="open"
            x-bind:aria-activedescendant="activeId"
            @class([
                'h-8 w-full rounded-lg border border-line bg-surface ps-3 pe-8 text-sm text-ink placeholder:text-ink-3',
                'placeholder:text-ink' => $selected !== null,
            ])
        />

        <flux:icon.chevron-up-down variant="micro" class="pointer-events-none absolute end-2 top-2.5 text-ink-3" />

        <ul id="{{ $listId }}" role="listbox" aria-label="{{ $label }}" x-show="open" x-cloak x-on:mousedown.prevent class="absolute inset-x-0 z-30 mt-1 max-h-56 overflow-y-auto rounded-lg border border-line bg-surface p-1 shadow-lg">
            <template x-for="item in items" x-bind:key="item.key">
                <li role="presentation">
                    <p x-show="item.header" aria-hidden="true" class="px-2 pt-2 pb-1 text-xs font-semibold text-ink-3" x-text="item.group"></p>
                    <button
                        type="button"
                        role="option"
                        tabindex="-1"
                        x-bind:id="item.domId"
                        x-bind:aria-selected="item.active"
                        x-on:mousedown.prevent
                        x-on:click="pick(item.id)"
                        x-on:mousemove="hover(item.index)"
                        x-bind:class="item.rowClass"
                        class="flex min-h-8 w-full items-center gap-2 rounded-md px-2 text-start text-sm text-ink"
                    >
                        <span x-show="item.hasDot" x-bind:class="item.dotClass" class="size-2 shrink-0 rounded-full bg-[var(--tag-line)]" aria-hidden="true"></span>
                        <span class="truncate" x-text="item.name"></span>
                    </button>
                </li>
            </template>
        </ul>

        <p x-show="empty" x-cloak role="status" class="absolute inset-x-0 z-30 mt-1 rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink-3 shadow-lg">Nenhuma etiqueta encontrada.</p>
    </div>
@endif
