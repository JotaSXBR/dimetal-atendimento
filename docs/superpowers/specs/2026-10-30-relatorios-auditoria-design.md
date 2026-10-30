# Relatórios, auditoria e retenção — design

Data: 2026-10-30. Decisões de escopo do dono (30/10); detalhes marcados "(builder)" foram delegados. Referência visual: telas
"Relatorios", "Auditoria" e "Config - Retencao" do `Dimetal.pen`.

## 1. Relatórios (supervisor e admin) — `/relatorios`

Período: hoje, 7 dias (padrão) ou 30 dias, pelo fuso do app. Sobre os tickets **abertos no período** (`opened_at`):
- **Tickets abertos** (quantidade).
- **1ª resposta (mediana)**, de `opened_at` a `first_human_reply_at`, em minutos e segundos.
- **SLA cumprido**: % dos tickets com resposta humana cuja 1ª resposta veio dentro do SLA (`settings`, padrão 10 min). Sem
  nenhum ticket respondido, mostra "—".
- **Sem resposta além do SLA**: tickets abertos agora, sem resposta humana, parados há mais que o SLA (não depende do período).
- **Volume por setor** e **desempenho por atendente** (tickets assumidos, mediana da 1ª resposta, encerrados), só quem tem
  tickets no período.
- (builder) Sem filtros extras, sem exportação CSV e sem "fora do painel" na v1. Tempo de espera por faixa: fora.

## 2. Auditoria (admin) — `/auditoria`

Lista paginada (25) dos `TicketEvent` (mais novos primeiro), com data, usuário, ação, protocolo e detalhe, e filtros por período
(hoje, 7 e 30 dias), usuário e tipo. O detalhe vem do `payload` (motivo e resumo do encerramento, setor de origem e destino,
etc.). (builder) Só eventos de ticket; sem IP, sem mudança de configuração e sem CSV na v1.

## 3. Retenção (admin) — `configuracoes/retencao`

Página de leitura: prazo de retenção (constante `PurgeMediaCommand::RETENTION_DAYS`, 90), espaço usado pelos anexos ainda guardados (soma de
`media_size`), quantos já passaram do prazo e o botão **Executar expurgo agora**, que roda `app:purge-media`. Texto e eventos
ficam para sempre. (builder) Sem edição do prazo e sem backup na tela: o prazo é configuração de deploy.

## 4. Não-objetivos

CSV, gráficos além de barras simples, auditoria de configuração, IP.
