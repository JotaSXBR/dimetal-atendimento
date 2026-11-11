<div
    class="flex h-[calc(100dvh-4rem)] min-h-[32rem] flex-col"
    {{-- O x-data carrega o grafo da carga da página; sem isto o Livewire reescreve o atributo a cada gravação e o Alpine recria o editor (perde a seleção). --}}
    wire:ignore.self
    x-data="flowEditor($wire, @js($config))"
    x-on:keydown.window="shortcut($event)"
>
    <header class="flex flex-wrap items-center gap-3 border-b border-line bg-surface px-4 py-3">
        <a href="{{ route('automacao') }}" wire:navigate class="text-sm text-accent-content hover:underline">← Automação</a>
        <h1 class="min-w-0 flex-1 truncate text-base font-semibold text-ink">{{ $workflow->name }}</h1>

        <p class="text-xs text-ink-3" role="status" x-text="busy ? 'Salvando…' : (dirty ? 'Alterações não salvas' : status)"></p>

        <flux:button size="sm" x-on:click="save()" x-bind:disabled="busy || failed">Salvar rascunho</flux:button>
        <flux:button size="sm" x-on:click="validate()" x-bind:disabled="busy || failed">Validar</flux:button>
        <flux:button size="sm" variant="primary" x-on:click="publish()" x-bind:disabled="busy || failed">Publicar</flux:button>
    </header>

    <p x-show="failed" x-cloak class="border-b border-danger/30 bg-danger/10 px-4 py-2 text-sm text-danger" role="alert">
        Não foi possível carregar o editor visual. Recarregue a página; se continuar, avise o suporte.
    </p>

    @error('graph')
        <p class="border-b border-danger/30 bg-danger/10 px-4 py-2 text-sm text-danger" role="alert">{{ $message }}</p>
    @enderror

    <div x-show="errors.length > 0" x-cloak class="border-b border-warn/30 bg-warn/10 px-4 py-2 text-sm text-warn" role="alert">
        <p class="font-semibold">Corrija antes de publicar:</p>
        <ul class="mt-1 list-disc ps-5">
            <template x-for="error in errors" x-bind:key="error">
                <li><button type="button" class="text-start underline" x-on:click="focus(error)" x-text="error"></button></li>
            </template>
        </ul>
    </div>

    <div class="flex min-h-0 flex-1">
        <aside class="w-44 shrink-0 space-y-1 overflow-y-auto border-e border-line bg-surface p-3" aria-label="Blocos">
            <p class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Adicionar bloco</p>
            <template x-for="(block, index) in palette" x-bind:key="block.type">
                <div>
                    <p x-show="index === 0 || palette[index - 1].group !== block.group" class="mt-2 font-mono text-[10px] tracking-wider text-ink-3 uppercase" x-text="block.group"></p>
                    <button type="button" class="w-full rounded-md border border-line px-2 py-1.5 text-start text-xs text-ink hover:bg-canvas" x-on:click="add(block.type)" x-text="block.label"></button>
                </div>
            </template>
        </aside>

        {{-- O canvas é do Rete: o Livewire não pode reescrever o que está aqui dentro. --}}
        <div class="relative min-w-0 flex-1">
            <div wire:ignore class="flow-canvas absolute inset-0 overflow-hidden bg-canvas" x-ref="canvas" aria-label="Canvas do workflow"></div>

            <div class="absolute start-3 top-3 z-10 flex flex-wrap gap-1" role="toolbar" aria-label="Controles do canvas">
                <flux:button size="sm" x-on:click="undo()" x-bind:disabled="failed || !canUndo">Desfazer</flux:button>
                <flux:button size="sm" x-on:click="redo()" x-bind:disabled="failed || !canRedo">Refazer</flux:button>
                <flux:button size="sm" x-on:click="zoom(1 / 1.25)" x-bind:disabled="failed" aria-label="Afastar">−</flux:button>
                <flux:button size="sm" x-on:click="zoom(1.25)" x-bind:disabled="failed" aria-label="Aproximar">+</flux:button>
                <flux:button size="sm" x-on:click="fit()" x-bind:disabled="failed">Centralizar</flux:button>
                <flux:button size="sm" x-on:click="organize()" x-bind:disabled="failed">Organizar blocos</flux:button>
            </div>

            {{-- Menu do clique direito: no fundo adiciona bloco; num bloco duplica ou apaga (o Início não). --}}
            <div x-show="menu" x-cloak x-on:click.outside="menu = null" x-on:keydown.escape.window="menu = null" role="menu" aria-label="Ações do canvas"
                 class="fixed z-50 flex w-52 flex-col gap-0.5 rounded-md border border-line bg-surface p-1 shadow-lg"
                 x-bind:style="menu ? `left:${menu.x}px;top:${menu.y}px` : ''">
                <template x-if="menu && menu.nodeId">
                    <div class="flex flex-col gap-0.5">
                        <template x-if="menu.type === 'inicio'"><p class="px-2 py-1 text-xs text-ink-3">O Início não se duplica nem se apaga.</p></template>
                        <template x-if="menu.type !== 'inicio'">
                            <div class="flex flex-col gap-0.5">
                                <flux:button size="sm" variant="ghost" class="w-full justify-start" x-on:click="menuDuplicate()">Duplicar</flux:button>
                                <flux:button size="sm" variant="ghost" class="w-full justify-start" x-on:click="menuRemove()">Apagar</flux:button>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="menu && !menu.nodeId">
                    <div class="flex flex-col gap-0.5">
                        <p class="px-2 py-1 font-mono text-[10px] tracking-wider text-ink-3 uppercase">Adicionar bloco</p>
                        <template x-for="block in palette" x-bind:key="block.type">
                            <flux:button size="sm" variant="ghost" class="w-full justify-start" x-on:click="menuAdd(block.type)" x-text="block.label"></flux:button>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        <aside class="w-72 shrink-0 space-y-4 overflow-y-auto border-s border-line bg-surface p-4" aria-label="Propriedades do bloco">
            <template x-if="selected">
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-ink" x-text="blockLabel(selected.type)"></p>
                    <p class="font-mono text-[10px] text-ink-3" x-text="'id: ' + selected.id"></p>

                    <template x-for="field in fields()" x-bind:key="selected.id + field.key">
                        <label class="block text-xs text-ink-2">
                            <span x-text="field.label"></span>

                            <template x-if="field.kind === 'textarea'">
                                <textarea rows="4" class="mt-1 w-full rounded-md border border-line bg-surface p-2 text-sm text-ink" x-model="selected.data[field.key]" x-on:input="apply()"></textarea>
                            </template>
                            <template x-if="field.kind === 'text'">
                                <input type="text" class="mt-1 w-full rounded-md border border-line bg-surface p-2 text-sm text-ink" x-model="selected.data[field.key]" x-on:input="apply()">
                            </template>
                            <template x-if="field.kind === 'number'">
                                <input type="number" x-bind:min="field.min" x-bind:max="field.max" class="mt-1 w-full rounded-md border border-line bg-surface p-2 text-sm text-ink" x-model.number="selected.data[field.key]" x-on:input="apply()">
                            </template>
                            <template x-if="field.kind === 'lines'">
                                <textarea rows="5" class="mt-1 w-full rounded-md border border-line bg-surface p-2 text-sm text-ink" x-bind:value="linesValue(field.key)" x-on:change="setLines(field.key, $event.target.value)"></textarea>
                            </template>
                            <template x-if="field.kind === 'select'">
                                <select class="mt-1 w-full rounded-md border border-line bg-surface p-2 text-sm text-ink" x-model="selected.data[field.key]" x-on:change="apply()">
                                    <template x-for="option in field.options" x-bind:key="option[0]">
                                        <option x-bind:value="option[0]" x-text="option[1]"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="field.kind === 'sector'">
                                <select class="mt-1 w-full rounded-md border border-line bg-surface p-2 text-sm text-ink" x-model.number="selected.data[field.key]" x-on:change="apply()">
                                    <option value="">Setor escolhido no menu</option>
                                    <template x-for="sector in sectors" x-bind:key="sector.id">
                                        <option x-bind:value="sector.id" x-text="sector.name"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="field.kind === 'tag'">
                                <select class="mt-1 w-full rounded-md border border-line bg-surface p-2 text-sm text-ink" x-model.number="selected.data[field.key]" x-on:change="apply()">
                                    <option value="">Escolha a etiqueta</option>
                                    <template x-for="tag in tags" x-bind:key="tag.id">
                                        <option x-bind:value="tag.id" x-text="tag.name"></option>
                                    </template>
                                </select>
                            </template>
                        </label>
                    </template>

                    <p x-show="selected.type === 'mensagem' || selected.type === 'pergunta' || selected.type === 'menu' || selected.type === 'menu_setores'" class="text-[11px] text-ink-3">
                        Variáveis: &#123;&#123;nome&#125;&#125;, &#123;&#123;empresa&#125;&#125;, &#123;&#123;protocolo&#125;&#125;, &#123;&#123;setor&#125;&#125;, &#123;&#123;horario&#125;&#125;, &#123;&#123;proximo_horario&#125;&#125; e as das perguntas.
                    </p>

                    <flux:button size="sm" variant="subtle" icon="trash" x-show="selected.type !== 'inicio'" x-on:click="remove()">Remover bloco</flux:button>
                </div>
            </template>
            <div x-show="! selected" class="space-y-3">
                <p class="text-xs text-ink-3">Clique em um bloco para editar. Arraste da bolinha de saída até a entrada de outro bloco para ligar.</p>

                <hr class="border-line">

                <div class="space-y-3">
                    <p class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Mensagens do bot</p>
                    <p class="text-[11px] text-ink-3">O que o bot diz sozinho. Em branco vale o texto padrão (mostrado cinza). O aviso de um bloco, quando preenchido, vale mais que estes.</p>

                    <template x-for="item in catalog" x-bind:key="item.key">
                        <div class="text-xs text-ink-2">
                            <label>
                                <span x-text="item.label"></span>
                                <textarea rows="3" class="mt-1 w-full rounded-md border border-line bg-surface p-2 text-sm text-ink" x-bind:placeholder="item.default" x-bind:disabled="item.key === 'rodape_sair' && exitHintHidden()" x-bind:value="messages[item.key] ?? ''" x-on:input="setMessage(item.key, $event.target.value)"></textarea>
                            </label>
                            <template x-if="item.key === 'rodape_sair'">
                                <label class="mt-1 flex items-center gap-2">
                                    <input type="checkbox" x-bind:checked="exitHintHidden()" x-on:change="toggleExitHint($event.target.checked)">
                                    <span>Não mostrar a dica (o #sair continua valendo)</span>
                                </label>
                            </template>
                            <flux:button type="button" size="xs" variant="subtle" class="mt-1" x-show="item.key in messages && ! (item.key === 'rodape_sair' && exitHintHidden())" x-on:click="resetMessage(item.key)">Usar o padrão</flux:button>
                        </div>
                    </template>

                    <p class="text-[11px] text-ink-3">
                        Variáveis: &#123;&#123;nome&#125;&#125;, &#123;&#123;empresa&#125;&#125;, &#123;&#123;protocolo&#125;&#125;, &#123;&#123;setor&#125;&#125; (só nos avisos de encaminhamento), &#123;&#123;horario&#125;&#125; e &#123;&#123;proximo_horario&#125;&#125;.
                    </p>
                </div>
            </div>

            <hr class="border-line">

            <div>
                <p class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Versões publicadas</p>
                <ul class="mt-2 space-y-2">
                    <template x-for="version in versions" x-bind:key="version.id">
                        <li class="text-xs">
                            <p class="font-semibold text-ink" x-text="'Versão ' + version.number"></p>
                            <p class="text-ink-3" x-text="version.at + ' · ' + version.by"></p>
                            <flux:button type="button" size="xs" variant="subtle" class="mt-1" x-on:click="if (confirm('Copiar esta versão para o rascunho? O rascunho atual será substituído.')) restore(version.id)">Restaurar no rascunho</flux:button>
                        </li>
                    </template>
                </ul>
                <p x-show="versions.length === 0" class="mt-2 text-xs text-ink-3">Ainda não publicado.</p>
            </div>
        </aside>
    </div>
</div>
