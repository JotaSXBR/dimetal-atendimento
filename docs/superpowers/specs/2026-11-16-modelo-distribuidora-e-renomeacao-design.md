# Projetos 0 e 1: Atendimento padrão do beta e renomeação para Fluxie Inbox

Data: 16/11/2026. Roteiro geral em `2026-11-16-fluxie-inbox-roteiro-design.md`. São duas entregas, cada uma com seu próprio plano e PR.

## Intenção

- **Projeto 0:** a automação que roda hoje no dimetal-beta (desenhada pelo dono: horário → saudação → menu de setores → fila)
  passa a ser o modelo básico **"Atendimento padrão"**. Ela é genérica: só captura o horário e o setor, nada de distribuidora.
  É o fluxo que o assistente do projeto 3 instala junto com os setores e etiquetas do segmento Distribuidora. O beta será
  apagado no projeto 2, então ela precisa existir no código antes disso.
- **Projeto 1:** o produto deixa de ser "da Dimetal" e passa a se chamar **Fluxie Inbox**, num repositório público da
  organização `fluxie-tech`. Os projetos 2 a 5 (imagens, compose e preset) dependem dos nomes definitivos.

## Objetivos

1. O modelo `padrao` ("Atendimento padrão", e o `DefaultWorkflow::graph()` que o seeder usa) gera o grafo da automação do beta,
   com `{{empresa}}` no lugar de "Dimetal Distribuidora".
2. O modelo "Atendimento com horário" (`horario`) sai: ficaria igual ao padrão. Os básicos passam a ser Em branco e Atendimento padrão.
3. O segmento "Distribuidora" (CNPJ e nota fiscal) continua como está, com o mesmo nome e a mesma chave.
4. Não sobra "dimetal" em código, configuração, CI, README, LICENSE e docs vivos, exceto onde for para ler dados antigos.
5. O beta continua de pé durante e depois da renomeação, até o projeto 2 substituí-lo.

## Não objetivos

- Assistente de primeiro acesso, compose e Releases (projetos 2 a 4).
- Reescrever specs, planos e auditorias antigas em `docs/superpowers/` e `docs/ux-audit/`. Eles são histórico e ficam com "Dimetal".
- Mudar setores e etiquetas padrão: já são iguais aos do beta (ver abaixo).

## Anti-objetivos

- Um workflow existente mudar sozinho. Os modelos só valem para workflows **novos**: o grafo é copiado na criação.
- A lista de backups ou a restauração deixar de enxergar os arquivos `dimetal-*.dump` que já existem.
- O deploy do beta quebrar por apontar para uma imagem base que ainda não existe.

## Dados de origem (projeto 0)

Os dados foram exportados do beta em 16/11, só para consulta, para `.superpowers/beta-export/2026-11-16-beta-distribuidora.json`.
O arquivo é local e ignorado pelo git; o essencial está copiado aqui.

**Setores e etiquetas do beta:**
- Setores: Vendas, Financeiro, Comercial, SAC e Diretoria, todos ativos. São iguais a `DefaultSectors::NAMES`.
- Etiquetas: 15, com nomes e escopos iguais a `DefaultTags::ALL`. As cores não foram lidas, e as do código ficam.

Nenhuma mudança aqui.

**Automação ativa:** é o workflow "Padrão", versão 3, publicada em 14/11/2026 14:51. O rascunho tem a mesma hora, então é ela que roda.

| id | tipo | dados |
|----|------|-------|
| `inicio` | inicio | — |
| `nmw4wryvab7` | condicao | `kind: horario` |
| `saudacao` | mensagem | `Olá, {{nome}}! Bem-vindo a Dimetal Distribuidora.` |
| `menu` | menu_setores | texto `Com qual setor você quer falar? Responda com o número da opção:`, `invalido: Resposta inválida.`, `timeout_min: 5` |
| `encaminhamento` | mensagem | `Certo! Seu protocolo é {{protocolo}}. Você entrou na fila de {{setor}} e um atendente já te responde em instantes.` |
| `transferir` | transferir | — |
| `nmw4x90wo5j` | mensagem | `Olá, {{nome}}! \nBem-vindo a Dimetal Distribuidora. \nNosso horário de atendimento é {{horario}}. Retornamos {{proximo_horario}} e você já está na fila para ser atendido.` |
| `nmw4xcipvn9` | transferir | `setor_id: 1, fila_geral: true` |

Ligações:
- `inicio→condicao`;
- `condicao` pela porta `true` → `saudacao→menu`;
- `menu` pela porta `setor` → `encaminhamento→transferir`;
- `condicao` pela porta `false` → `nmw4x90wo5j→nmw4xcipvn9`.

`settings.mensagens.encaminhamento_setor`: `Vou encaminhar voce para o setor: {{setor}} \nSeu protocolo é {{protocolo}}`.

## Approaches consideradas

Houve duas escolhas: como entregar os dois projetos e de que forma o modelo do beta entra no código.

**Como entregar:**

- **A. Duas entregas separadas (escolhida pelo dono).**
  - Primeiro o modelo, um PR pequeno, com TDD, que vai ao beta sem depender de nada.
  - Depois a renomeação, só após o dono criar a organização e transferir o repositório, e após a imagem base nova ser publicada.
  - Revisão limpa: comportamento num PR, troca mecânica no outro.
- **B. Um PR só.** Mistura comportamento com cerca de 600 trocas mecânicas e deixa o modelo esperando a organização. Descartada.
- **C. Renomeação em duas ondas** (o que o público vê, depois os nomes técnicos). Divide um trabalho mecânico sem ganho, já
  que o dono quer tudo como `fluxie`. Descartada.

**Onde o fluxo do beta entra (decisão do dono, 16/11):**

- **Escolhida:** vira o "Atendimento padrão" e o "Atendimento com horário" sai, porque os dois ficariam iguais. O nome reflete o
  que o fluxo faz (horário e setor), e o "Distribuidora" com CNPJ continua sendo o modelo de segmento.
- Descartadas: chamá-lo de "Distribuidora" e renomear o atual para "Distribuidora completo" (o fluxo não tem nada de
  distribuidora); só atualizar o "com horário" mantendo três básicos quase iguais; ou acrescentar um quarto básico.

## Projeto 0: design

1. `DefaultWorkflow::graph()` passa a devolver o fluxo do beta (a tabela de "Dados de origem"), com ids legíveis:
   `inicio`, `horario` (condicao), `saudacao`, `menu` (menu_setores), `encaminhamento`, `transferir`, `fora`, `transferir_fora`.
   - Textos do beta, trocando "Dimetal Distribuidora" por `{{empresa}}` e "voce" por "você".
   - `menu` com `invalido: Resposta inválida.` e `timeout_min: 5`.
   - `transferir_fora` com `fila_geral: true` e **sem** `setor_id`.
   - O grafo leva `settings.mensagens.encaminhamento_setor` com o texto do beta.
   - Posições `x`/`y` como as do `withHours()` de hoje.
2. `DefaultWorkflow::withHours()` e `WorkflowTemplates::WITH_HOURS` saem. `OUT_OF_HOURS` fica, porque o `FlowBuilder::opening`
   do segmento Distribuidora usa. A descrição do "Atendimento padrão" passa a citar o horário. Uma chave desconhecida (inclusive
   uma `horario` antiga) continua caindo no em branco; nenhum workflow guarda a chave.
3. O `DefaultWorkflowSeeder` não muda: instalações novas já ganham o fluxo novo como rascunho. Os workflows existentes não mudam.
4. Testes (RED antes) que dependem dos três básicos ou do `withHours`:
   - `WorkflowTemplatesTest`;
   - `AutomationIndexTest` (lista, criação com `horario`);
   - `WorkflowPublisherTest` e `AutomationEditorTest` (usam `DefaultWorkflow::graph()` e continuam válidos).

   Os dois testes "com horário" do `WorkflowTemplatesTest` passam a usar o `padrao`, e entram testes novos para o "Resposta
   inválida." e o `{{empresa}}` resolvido.

## Projeto 1: design

### Ordem (o beta não pode quebrar)

1. **Dono:** cria a organização `fluxie-tech` no GitHub (pela conta JotaSXBR) e transfere o repositório, renomeando-o para
   `fluxie-inbox`. O GitHub redireciona `JotaSXBR/dimetal-atendimento`, e o `origin` local é atualizado.
2. **Coolify** (revisto em 16/11): os apps do beta usavam a fonte "Public GitHub" (clone público pela URL, sem GitHub App),
   então a transferência não exige instalar nada na organização. O dono decidiu **apagar os projetos `dimetal-beta` e
   `dimetal-main` e recriar do zero no projeto 2**; não se repontam os apps antigos. O repositório precisa seguir público.
   Feito em 16/11: repositório em `fluxie-tech/fluxie-inbox`, `origin` local atualizado, segredos do Actions
   (`COOLIFY_URL`, `COOLIFY_API_TOKEN`) transferidos junto. O `deploy-beta` deixa de ter alvo quando o beta for apagado
   e deve ser desativado nessa hora.
3. **Eu:** disparo o workflow `base-image` no repositório novo, já com o nome novo da imagem, para publicar
   `ghcr.io/fluxie-tech/fluxie-php-base:8.5`. A imagem antiga continua existindo para o beta até o merge.
   **Dono:** torna o pacote público em Packages > Package settings da organização (o pacote nasce privado e o workflow não
   muda isso). Pacote público é o que permite a terceiros e ao Coolify puxarem sem login.
4. **Eu:** PR da renomeação. O merge só acontece depois que a imagem do passo 3 existir **e estiver pública**.

### O que muda

| Onde | De → para |
|------|-----------|
| `LICENSE` | `Copyright (c) 2026 Dimetal Distribuidora` → `Copyright (c) 2026 Fluxie` |
| `README.md` | Reescrito como produto público "Fluxie Inbox": o que é, recursos, stack, a instalação local de hoje e o aviso de que a instalação pelo Coolify vem no projeto 2. Sai "projeto interno, sem cadastro público" |
| `composer.json` | `dimetal/atendimento` → `fluxie/inbox` (`package.json` acompanha, se tiver nome) |
| `.env.example` | `APP_NAME="Fluxie Inbox"`, `DB_DATABASE=fluxie`, `DB_USERNAME=fluxie` |
| `compose.yaml`, `docker/postgres/init.sql` | Bancos `fluxie` e `fluxie_test`, usuário `fluxie` |
| `phpunit.xml`, `.github/workflows/tests.yml` | `fluxie_test` e `fluxie` |
| `docker/Dockerfile`, `docker/base/Dockerfile`, `base-image.yml`, `security-scan.yml` | `ghcr.io/fluxie-tech/fluxie-php-base`; o `image.source` aponta para `https://github.com/fluxie-tech/fluxie-inbox`; tags locais `fluxie-base:ci` e `fluxie-scan:ci`. O alvo do ZAP (`dimetal-beta.fluxie.com.br`) **fica** até o projeto 2 trocar o domínio |
| `app/Services/Backup/BackupCatalog.php` | Grava `fluxie-AAAA-MM-DD-HHMM-<tipo>.dump`; o padrão de leitura aceita `(dimetal\|fluxie)-...`. Teste para os dois prefixos (lista, ordem e restauração de um antigo) |
| `app/Services/Inbox/Signature.php`, `config/services.php`, `resources/views/livewire/contacts/index.blade.php` | Tiram a menção à Dimetal ("Quem fala com a Dimetal" → texto neutro, por exemplo "Quem fala com a empresa") |
| `resources/js/alerts.js`, `alert-dismiss.js` | Chaves `dimetal-*` do `localStorage`, `Notification.tag` e `navigator.locks` → `fluxie-*`. Efeito aceito: cada navegador perde uma vez o "som ligado" e os avisos dispensados |
| `phpmd.xml` | `ruleset name="Fluxie"` |
| Testes (cerca de 40 arquivos) | Onde "Dimetal" é só dado de exemplo, troca por um nome neutro; onde testa o prefixo do backup, cobre os dois |
| `tests/Fixtures/restore/toc-esquema-atual.txt` | Conferir se o nome do banco no TOC importa para o `DumpInspector`; se importar, gerar o fixture de novo |
| `CLAUDE.md` | Comandos do Postgres de teste com `fluxie`/`fluxie_test`, nome do produto e IDs que mudarem |
| `docs/deploy.md`, `docs/deploy-coolify-api.md`, `docs/backup-e-restauracao.md` | Nomes de imagem, banco e prefixo do backup. Os UUIDs e o domínio do beta ficam até o projeto 2 |
| Memórias do Claude | Comandos e nomes atualizados junto (fora do repositório) |

### Efeitos colaterais conhecidos

- `APP_NAME` muda o prefixo do cache e o nome do cookie de sessão onde o `.env` não o fixa. O beta definia
  `APP_NAME="Dimetal Distribuidora"` e `DB_DATABASE=dimetal` no Coolify (conferido em 16/11); como ele será apagado, não há
  efeito a tratar.
- Os nomes padrão (`fluxie`, `fluxie_test`) só valem para instalações novas e para os testes.
- Suíte local: depois do merge, recriar o Postgres de teste com `fluxie`/`fluxie_test` (comando novo no `CLAUDE.md`) e migrar
  uma vez. O banco de dev `dimetal_local` não muda, porque o `.env` local manda nele.
- O `origin` local e os clones de outras máquinas continuam funcionando pelo redirecionamento, mas é melhor atualizar.

## Decisões do builder (minhas, sujeitas à revisão do dono)

- O fluxo do beta é escrito com ids legíveis, e não como o JSON exportado.
- O "voce" sem acento do texto do beta é corrigido.
- No `transferir` de fora do expediente, o `setor_id: 1` do beta sai: com `fila_geral: true` ele não tem efeito, e o id 1 não
  existe numa instalação nova.
- O texto neutro da tela de Contatos fica a critério do builder, curto, como os outros subtítulos.
- As specs, planos e auditorias antigas não são reescritas.

## Prova

- **Projeto 0:**
  - portão 7 completo;
  - no servidor local, criar um workflow "Atendimento padrão" e simular dentro e fora do horário;
- **Projeto 1:**
  - portão 7 completo;
  - nenhum `dimetal` fora das exceções (`git grep -i dimetal` sem `docs/superpowers` nem `docs/ux-audit`, conferido à mão);
  - teste automatizado da lista de backups com os dois prefixos (o beta não existirá mais para a prova manual).
