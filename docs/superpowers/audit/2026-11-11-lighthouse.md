# Lighthouse de acessibilidade, 11/11/2026 (fatias 4 e 5 do UX)

Medido no servidor local (`dimetal_local`), Chrome DevTools MCP, modo navegação, `device: desktop`, 1280 px. Tema alterado por
`localStorage['flux.appearance']`. Pontuação de **Acessibilidade**.

## Antes das correções (tema claro)

| Tela | Nota | Falhas de acessibilidade |
|---|---|---|
| Contatos, Histórico, Auditoria, Automação | 100 | `label-content-name-mismatch` na marca da sidebar (link com `aria-label` sem a inicial do monograma, que é texto visível) |
| Relatórios | 96 | a mesma da marca e `definition-list` (`<p>` solto dentro do `<div>` do `<dl>`) |

## Depois das correções

| Tela | Claro | Escuro |
|---|---|---|
| Inbox (sem ticket aberto) | 100 | 100 |
| Contatos | 100 | 100 |
| Histórico | 100 | 100 |
| Relatórios | 100 | 100 |
| Auditoria | 100 | — |
| Automação (lista) | 100 | — |
| Configuração: Setores | 100 | — |
| Configuração: Horário e SLA | 100 | — |
| Configuração: Canais | 100 | 100 |
| Configuração: Empresa e assinatura | 100 | 100 |

Telas que o Lighthouse não alcança ou que não foram medidas por ele:
- **Editor de automação** (`/automacao/{id}`): o canvas (Rete) não é alcançado pelo Lighthouse; coberto pela auditoria por script
  (`docs/superpowers/audit/ux-audit.js`): sem controle sem nome, alvos de pelo menos 24 px e, em 360 px, a rolagem horizontal
  conhecida (ferramenta de desktop).
- Respostas rápidas, Etiquetas, Usuários, Google Contatos e Anexos e retenção: mesmos componentes das telas medidas e sem achado
  na auditoria por script nas 5 larguras e 2 temas; não rodei o Lighthouse nelas.
- Escuro de Auditoria, Automação (lista), Setores e Horário: não medido (mesmos tokens das telas escuras medidas).

As duas falhas de **Melhores práticas** (`errors-in-console`, `inspector-issues`) em todas as telas vêm do WebSocket do Reverb,
que não roda localmente ("Sem conexão com o servidor"); não são de acessibilidade.

## Teclado e movimento reduzido

- Ordem do Tab no compositor (DOM): Emoji, Anexar arquivo, Respostas rápidas, Mensagem, Enviar (ou Gravar áudio quando o campo
  está vazio). Enter envia; Shift+Enter não é impedido (quebra linha). Esc fecha o seletor de emoji e a lista de respostas rápidas e
  devolve o foco ao campo (evento `composer-focus`); clique fora só fecha.
- `prefers-reduced-motion: reduce` zera animações e transições no CSS global; nada novo anima neste PR.
