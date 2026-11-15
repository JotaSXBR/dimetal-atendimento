# Faixas de aviso dispensáveis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fatia H: cada faixa fixa do topo ganha um "✕" que a esconde por um tempo e ela volta sozinha (ou antes, se o problema piora).

**Architecture:** Regra pura em `resources/js/alerts-rules.js` (`DISMISSALS`, `isDismissed`, `dismissEntry`) com `node --test`; cola de DOM em `resources/js/alert-dismiss.js` (delegação no clique do ✕, `localStorage`/`sessionStorage` com try/catch, reaplicação a cada mutação e a cada 5 s). As faixas ganham `data-dismiss="<tipo>"` e `data-signature="<assinatura>"` e um `flux:button` ✕ (`data-dismiss-button`, `aria-label="Dispensar aviso"`). Dispensar só **esconde** (`data-dismissed` + CSS): o elemento fica no DOM porque o som repetido (`alerts.js`, `STATES`) depende dele. Nada no servidor além das assinaturas nos atributos.

**Tech Stack:** Laravel Blade, Livewire (Banner), JS puro, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-14-ux-simplificacao-design.md` (fatia H, item 15; tempos aprovados pelo dono em 14/11 — "pode seguir com a proposta da spec").

## Global Constraints

- Tempos: Sem conexão 1 min; WhatsApp desconectado 2 min; SLA estourado 3 min; Som desligado até a próxima visita (`sessionStorage`). Nada persiste no servidor.
- "Volta antes se": offline cai de novo (contador de quedas muda); WhatsApp: muda o conjunto de canais fora do ar ou a hora da queda; SLA: o número **aumenta** (comparação `>`, nunca igualdade); som: nunca no mesmo acesso.
- ✕ com `aria-label="Dispensar aviso"`, alvo ≥ 24 px; texto ≥ 12 px; botão do Flux (nada de `<button>` cru).
- Dispensar nunca silencia o som nem remove a faixa do DOM. Os botões "Silenciar som" seguem como estão.

## Review Focus

- **Morph do Livewire:** o Banner re-renderiza por Reverb; a faixa dispensada não pode reaparecer num re-render dentro do prazo (reaplicação na mutação), nem a assinatura nova ser ignorada.
- **Comparação do SLA:** 15 → 14 não reexibe; 15 → 16 reexibe.
- **Storage bloqueado/inválido:** sem `localStorage` (modo privado), JSON corrompido ou data no futuro absurdo não quebram nem escondem para sempre.
- **Som:** com a faixa dispensada o som repetido continua (o DOM da faixa existe).
- **Acessibilidade:** o ✕ tem nome, o aviso dispensado sai do leitor de tela (`display: none`).

### Task 1: regra pura

Files: `resources/js/alerts-rules.js`, `tests/js/alerts-rules.test.mjs`.
Produces: `DISMISSALS = { offline: {ms: 60000, store: 'local'}, whatsapp: {ms: 120000, store: 'local'}, sla: {ms: 180000, store: 'local', grows: true}, audio: {ms: null, store: 'session'} }`; `dismissEntry(kind, now, signature) → {until: number|null, signature: string}`; `isDismissed(entry, now, signature, kind) → boolean` (verdadeiro só com entrada válida, não vencida e sem piora; `grows` compara `Number(signature) > Number(entry.signature)`, os demais `signature !== entry.signature`). Testes: vence no prazo; sem piora dentro do prazo; SLA 15→14 segue escondida e 15→16 volta; assinatura diferente volta (offline/whatsapp); `audio` sem `until` nunca vence; entrada nula/corrompida (`until` não numérico, tipo errado) → falso.

### Task 2: faixas e cola de DOM

Files: `resources/views/livewire/alerts/banner.blade.php`, `app/Livewire/Alerts/Banner.php`, `resources/views/components/alerts-local.blade.php`, `resources/js/alert-dismiss.js`, `resources/js/alerts.js`, `resources/js/app.js`, `resources/css/app.css`, `tests/Feature/Alertas/BannerTest.php`, `tests/Feature/Alertas/AlertsLocalTest.php` (novo se preciso).
Produces: atributos `data-dismiss`/`data-signature` nas quatro faixas (`offline` com `data-signature="0"` e contador de quedas atualizado por `alerts.js`; `whatsapp` = ids dos canais fora do ar com `down_since`, ordenados; `sla` = contagem; `audio` = ''); ✕ em cada uma; `alert-dismiss.js` aplica `data-dismissed` conforme `isDismissed` (leitura dos storages em try/catch); regra CSS `[data-dismissed] { display: none !important; }`.
Testes PHP: cada faixa renderiza o ✕ com `aria-label="Dispensar aviso"` e os `data-dismiss`/`data-signature` certos; a assinatura do WhatsApp muda com o conjunto de canais; a do SLA é a contagem.

### Task 3: verificação, revisão e PR (portões 6 e 7, ledger, PR, merge com CI verde); depois o bug do sino em branch próprio (fatia final).
