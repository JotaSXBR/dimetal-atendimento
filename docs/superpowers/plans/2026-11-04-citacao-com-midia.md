# Citação e mídia como no WhatsApp — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O bloco citado e a barra "Respondendo a…" mostram miniatura de foto e ícone com nome de documento; a foto na bolha tem tamanho máximo fixo; o documento vira cartão como o do WhatsApp (ícone do tipo, nome, "TIPO · tamanho", Abrir e Salvar como…).

**Architecture:** O snapshot da citação (`Quote::snapshot`) passa a levar `media` (id local da original, tipo e nome). Um helper `MediaPresentation` decide o tipo (foto, figurinha, vídeo, áudio, documento), a etiqueta do ícone e o tamanho legível. Um partial Blade `quote-preview` desenha a citação e é usado pelo transcript e pela barra. A miniatura usa a rota `midia/{id}` que já existe (mesma autorização por ticket).

**Tech Stack:** Laravel 13, Livewire 4, Flux, Tailwind, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-03-inbox-completa-design.md` (seção 5, "Fatia 3", bullet "Citação e mídia como no WhatsApp"). Referência visual: captura de tela do WhatsApp Web que o dono deixou na raiz do projeto (`Captura de tela 2026-11-04 170506.png`, ignorada pelo git: mostra comprovante com dados da empresa).

## Global Constraints

- Textos em português; commits em português com prefixo, um por tarefa; branch `feat/citacao-com-midia` (empilhado sobre `fix/menu-da-bolha`); sem PR até o dono pedir.
- Referência WhatsApp: **foto** com largura fixa e altura máxima, mais alta que o limite é **cortada** (`object-cover`); **citação de foto** com miniatura quadrada à direita e o rótulo "Foto"; **citação de documento** com ícone e nome do arquivo; **documento** com ícone do tipo (DOCX azul "W"), nome, "DOCX · 22 KB" e, abaixo, **Abrir** e **Salvar como…**.
- Nada de HTML cru: nome de arquivo e legenda sempre escapados.
- Citação gravada antes desta mudança (sem `media`) continua funcionando com o rótulo.
- Sem miniatura gerada no servidor (atalho): a rota `midia/{id}` serve o arquivo e o navegador reduz.
- Layout mínimo e coerente com o que já existe (o dono refaz o frontend depois com outras skills). Blade/JS: `grep` e leitura direta, avisando o dono. Testes: Postgres em 127.0.0.1:5432; rápido com `QUEUE_CONNECTION=database`; portão 7 com Redis em série, Pint, Larastan e PHPMD.

## Review Focus

1. Nome de arquivo ou legenda com `<script>`, aspas ou `*`: sai escapado em todos os lugares novos (Tasks 2 e 4).
2. Citação antiga (sem `media`), original apagada ou com mídia expirada: o bloco não mostra imagem quebrada nem estoura (Task 2).
3. Foto muito alta, muito larga ou pequena, e figurinha: tamanho limitado e figurinha não vira foto grande (Task 3).
4. Documento sem nome, sem tamanho ou sem extensão: o cartão aparece com valores padrão, sem "· 0 KB" nem erro (Task 4).
5. Quem não pode ver o ticket não carrega a miniatura (a rota `midia` autoriza): coberto pelo teste da rota existente, conferido na Task 2.

---

### Task 1: Tipo de mídia, tamanho legível e `media` no snapshot da citação

**Files:**
- Create: `app/Support/MediaPresentation.php`
- Modify: `app/Services/Inbox/Quote.php` (`snapshot`)
- Test: `tests/Unit/MediaPresentationTest.php`, `tests/Feature/Inbox/QuoteSnapshotTest.php`

**Interfaces:**
- Produces: `MediaPresentation::kind(?string $mime): string` → `sticker` (`image/webp`), `image`, `video`, `audio` ou `document`.
- Produces: `MediaPresentation::extension(?string $name, ?string $mime): string` → extensão em maiúsculas (`DOCX`), ou a do mime (`pdf` → `PDF`), ou `''`.
- Produces: `MediaPresentation::badge(string $extension): array{label: string, tone: string}` → `DOCX`/`DOC` `['W','blue']`, `XLSX`/`XLS`/`CSV` `['X','green']`, `PPTX`/`PPT` `['P','orange']`, `PDF` `['PDF','red']`, resto `['','gray']`.
- Produces: `MediaPresentation::size(?int $bytes): ?string` → `Number::fileSize($bytes, precision: 0)` em português (`22 KB`), `null` quando nulo ou zero.
- Changes: `Quote::snapshot(Message $target)` devolve também `media: array{message_id: int, kind: string, name: ?string}` **só quando a mensagem é mídia** (texto continua com as três chaves de hoje); o trecho de documento sem legenda passa a ser o nome do arquivo (sem nome, o rótulo de hoje).

- [ ] **Step 1: Write the failing tests** — `MediaPresentationTest`: `test_tipo_por_mime` (`image/webp`→`sticker`, `image/jpeg`→`image`, `video/mp4`, `audio/ogg`, `application/pdf` e nulo→`document`), `test_extensao_pelo_nome_e_depois_pelo_mime`, `test_etiqueta_do_icone_por_extensao` (as cinco famílias e o padrão cinza), `test_tamanho_legivel_e_nulo_sem_valor` (`22528`→`22 KB`, `0` e `null`→`null`). `QuoteSnapshotTest`: `test_midia_traz_o_id_local_o_tipo_e_o_nome` (foto e documento com `media_name`: `media` com `message_id`, `kind`, `name`), `test_documento_sem_legenda_usa_o_nome_do_arquivo_como_trecho`, `test_texto_continua_sem_a_chave_media`.
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Unit/MediaPresentationTest.php tests/Feature/Inbox/QuoteSnapshotTest.php` → FAIL.
- [ ] **Step 3: Implement** os contratos acima (`final class`, métodos estáticos).
- [ ] **Step 4: Run to verify they pass** — mesmo comando, mais `tests/Feature/Inbox` → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: tipo de midia, tamanho legivel e midia no snapshot da citacao"`.

### Task 2: Bloco citado e barra "Respondendo a…" com miniatura ou ícone

**Files:**
- Create: `resources/views/livewire/inbox/quote-preview.blade.php`
- Modify: `resources/views/livewire/inbox/transcript.blade.php` (bloco citado), `resources/views/livewire/inbox/inbox.blade.php` (barra sobre o campo)
- Test: `tests/Feature/Livewire/InboxTranscriptTest.php`, `tests/Feature/Livewire/InboxReplyTest.php`

**Interfaces:**
- Consumes: `Quote::snapshot` com `media`; `MediaPresentation::kind/badge`; rota `midia` (`route('midia', $id)`).
- Produces: partial `livewire.inbox.quote-preview` com a variável `$quote` (o snapshot): texto à esquerda (autor em destaque, ícone e trecho) e, quando `media.kind` é `image` ou `sticker`, miniatura quadrada de 3.5rem à direita (`<img src="{{ route('midia', …) }}" loading="lazy" class="size-14 … object-cover" onerror="this.style.display='none'">`); documento, vídeo e áudio mostram um ícone pequeno antes do trecho (documento: o nome do arquivo). Sem `media` (texto ou citação antiga): só o texto.

- [ ] **Step 1: Write the failing tests** — `InboxTranscriptTest::test_bloco_citado_de_foto_mostra_a_miniatura_e_o_rotulo`, `::test_bloco_citado_de_documento_mostra_o_nome_e_nenhuma_imagem`, `::test_bloco_citado_antigo_sem_media_continua_so_com_texto` (sem `<img` dentro do bloco), `::test_nome_do_arquivo_citado_sai_escapado` (Review Focus 1); `InboxReplyTest::test_barra_responder_a_foto_mostra_a_miniatura` e `::test_barra_responder_a_documento_mostra_o_nome`.
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Livewire/InboxTranscriptTest.php tests/Feature/Livewire/InboxReplyTest.php` → FAIL.
- [ ] **Step 3: Implement** o partial e os dois usos (o botão `data-quote-target` do transcript e a barra continuam com o mesmo comportamento de hoje; só o interior passa a usar o partial). Se a miniatura falhar ao carregar (mídia expirada), ela some (`onerror`).
- [ ] **Step 4: Run to verify they pass** — mesmo comando, mais `tests/Feature/Inbox/MessageMediaRouteTest.php` e `npm run build` sem erro → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: citacao com miniatura da foto e icone com nome do documento"`.

### Task 3: Foto na bolha com tamanho máximo fixo

**Files:**
- Modify: `resources/views/livewire/inbox/media.blade.php` (ramo de imagem)
- Test: `tests/Feature/Livewire/InboxMediaTest.php`

**Interfaces:**
- Changes: foto (`image/*` exceto `image/webp`) dentro de um contêiner `w-72 max-w-full` com a imagem `w-full max-h-80 object-cover rounded-lg` (fixa em largura, cortada acima da altura máxima); figurinha (`image/webp`) mantém `max-h-40`; o link para abrir em nova aba continua.

- [ ] **Step 1: Write the failing tests** — `test_foto_tem_largura_fixa_e_altura_maxima_com_corte` (classes `w-72`, `max-h-80`, `object-cover`; e não mais `max-h-72`), `test_figurinha_continua_pequena` (`max-h-40`, sem `w-72`), `test_foto_continua_abrindo_em_nova_aba`.
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Livewire/InboxMediaTest.php` → FAIL.
- [ ] **Step 3: Implement** a troca de classes no ramo de imagem.
- [ ] **Step 4: Run to verify they pass** — mesmo comando → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: foto na bolha com largura fixa e altura maxima"`.

### Task 4: Documento como cartão (ícone do tipo, nome, tipo e tamanho, Abrir e Salvar como…)

**Files:**
- Modify: `resources/views/livewire/inbox/media.blade.php` (ramo de documento)
- Test: `tests/Feature/Livewire/InboxMediaTest.php` (atualizar `test_documento_vira_link_de_download` e `test_documento_mostra_o_nome_original` ao novo cartão)

**Interfaces:**
- Consumes: `MediaPresentation::extension/badge/size`.
- Changes: o ramo "arquivo" vira cartão: quadrado colorido com a etiqueta (`W`, `X`, `P`, `PDF`; cinza com ícone de documento no padrão), nome (`truncate`, escapado; padrão "Arquivo" sem nome), linha "DOCX · 22 KB" (só o que existir) e, sob um filete, os dois links lado a lado: **Abrir** (`target="_blank" rel="noopener"`) e **Salvar como…** (`download`). O estado "expirado" e "indisponível" não mudam.

- [ ] **Step 1: Write the failing tests** — `test_documento_vira_cartao_com_icone_nome_tipo_e_tamanho` (nome, `DOCX · 22 KB` para `media_size` 22528 e etiqueta `W`), `test_cartao_tem_abrir_em_nova_aba_e_salvar_como_com_download`, `test_documento_sem_nome_sem_tamanho_e_sem_extensao_usa_padroes` (Review Focus 4: "Arquivo", nada de "· 0 KB"), `test_nome_do_documento_sai_escapado` (Review Focus 1), `test_pdf_e_planilha_usam_a_etiqueta_certa`.
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Livewire/InboxMediaTest.php` → FAIL.
- [ ] **Step 3: Implement** o cartão; as cores vêm do `tone` do `badge` (mapa fixo de classes Tailwind no próprio Blade).
- [ ] **Step 4: Run to verify they pass** — mesmo comando, mais `tests/Feature/Livewire tests/Feature/History` e `npm run build` → PASS. Conferência visual no beta depois do deploy, contra a captura de referência.
- [ ] **Step 5: Commit** — `git commit -m "feat: documento como cartao com icone, nome, tipo e tamanho"`.
