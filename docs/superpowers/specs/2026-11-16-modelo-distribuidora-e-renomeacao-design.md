# Projetos 0 e 1: modelo Distribuidora do beta e renomeação para Fluxie Inbox

Data: 16/11/2026. Roteiro geral em `2026-11-16-fluxie-inbox-roteiro-design.md`. São duas entregas, cada uma com seu próprio plano e PR.

## Intenção

- **Projeto 0:** a automação que roda hoje no dimetal-beta, desenhada pelo dono, passa a ser o modelo do segmento
  **Distribuidora**. É ela que o assistente do projeto 3 vai instalar. O beta será apagado no projeto 2, então ela precisa
  existir no código antes disso.
- **Projeto 1:** o produto deixa de ser "da Dimetal" e passa a se chamar **Fluxie Inbox**, num repositório público da
  organização `fluxie-tech`. Os projetos 2 a 5 (imagens, compose e preset) dependem dos nomes definitivos.

## Objetivos

1. O modelo `distribuidora` gera o grafo da automação do beta, com `{{empresa}}` no lugar de "Dimetal Distribuidora".
2. O modelo atual, com CNPJ e nota fiscal, continua disponível como **"Distribuidora completo"**.
3. Não sobra "dimetal" em código, configuração, CI, README, LICENSE e docs vivos, exceto onde for para ler dados antigos.
4. O beta continua de pé durante e depois da renomeação, até o projeto 2 substituí-lo.

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

**Como o modelo entra no código (decisão minha, ver "Decisões do builder"):**

- **Escolhida:** uma classe `Template` nova, que monta o grafo com o `FlowBuilder`. Os ids dos blocos ficam legíveis
  (`horario`, `fora`, `transferir_fora`), e não os aleatórios do editor.
- **Descartada:** colar o JSON do beta como arquivo. Os ids ilegíveis e as coordenadas não acrescentam nada, e o editor reorganiza o desenho.

## Projeto 0: design

1. **Nova classe** `App\Services\Bot\Templates\Segments\Distribuidora`, com a chave `distribuidora` e o nome "Distribuidora",
   reescrita para gerar a automação do beta. A estrutura é a do `FlowBuilder::opening`:
   - fora do horário, a mensagem do beta (com `{{empresa}}`) e depois um `transferir` com `fila_geral: true` **sem** `setor_id`;
   - no horário, a saudação e o bloco `menu_setores`, com o texto, o `invalido` e o `timeout_min: 5` do beta;
   - em seguida o `encaminhamento` e o `transferir` sem setor fixo (o setor vem do menu).

   O `opening()` atual usa o `menu` de opções fixas e o `OUT_OF_HOURS` padrão. O builder decide se monta o grafo bloco a bloco
   ou se ganha um parâmetro; o resultado tem de ser o descrito acima.
   - Os textos ficam como no beta, trocando só "Dimetal Distribuidora" por `{{empresa}}`. O "voce" sem acento do
     `encaminhamento_setor` é corrigido para "você".
   - O grafo leva `settings.mensagens.encaminhamento_setor` com o texto do beta. Confirmar que o `resolveGraph` e o
     `WorkflowPublisher::create` preservam `settings`; se não preservarem, é ajuste do mesmo PR.
   - Descrição honesta: menu dos setores cadastrados, aviso do horário e fila geral fora do expediente, protocolo ao encaminhar.
2. **Classe atual** renomeada para `DistribuidoraCompleto`, com chave `distribuidora_completo`, nome "Distribuidora completo" e
   a descrição de hoje. O grafo não muda.
3. `WorkflowTemplates::SEGMENTS = [Distribuidora::class, DistribuidoraCompleto::class]`, nessa ordem.
4. **Testes (RED antes):**
   - `WorkflowTemplatesTest::test_a_lista_traz_os_modelos_na_ordem` passa a esperar as duas chaves e os dois nomes;
   - o rascunho de cada modelo continua válido no `check()` (esse teste já percorre todos);
   - novo teste de comportamento do Distribuidora, no molde dos testes "com horário": dentro do horário a saudação traz o
     nome da empresa e o menu lista os setores; fora do horário vai à fila geral sem setor e o texto traz `{{empresa}}` resolvido;
   - o `SegmentTemplatesTest` (no máximo cinco opções, descrição honesta) continua passando para os dois.

   O `{{empresa}}` já é resolvido pelo motor? Conferir em `Vars`. Se não for, é ajuste do mesmo PR, com teste.

## Projeto 1: design

### Ordem (o beta não pode quebrar)

1. **Dono:** cria a organização `fluxie-tech` no GitHub (pela conta JotaSXBR) e transfere o repositório, renomeando-o para
   `fluxie-inbox`. O GitHub redireciona `JotaSXBR/dimetal-atendimento`, e o `origin` local é atualizado.
2. **Fonte do Coolify do beta** (conferido pela API em 16/11): os quatro apps PHP (app, worker, scheduler, reverb) puxam
   `JotaSXBR/dimetal-atendimento`, branch `beta`, pelo **GitHub App do Coolify**, com build por Dockerfile. Depois da transferência:
   - **Dono:** instala o GitHub App do Coolify na organização `fluxie-tech`, com acesso ao `fluxie-inbox`.
   - **Eu, com o OK do dono:** troco o repositório dos quatro apps para `fluxie-tech/fluxie-inbox` (pela API ou pela interface)
     e disparo um deploy do beta **antes** do PR da renomeação, para provar que a fonte nova funciona.
   - Os segredos do Actions (`COOLIFY_URL`, `COOLIFY_API_TOKEN`) vão junto na transferência; conferir na primeira execução do
     `deploy-beta`. Dependabot e proteção de branch: conferir na aba Settings do repositório novo.
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

- `APP_NAME` muda o prefixo do cache e o nome do cookie de sessão onde o `.env` não o fixa. O beta define
  `APP_NAME="Dimetal Distribuidora"` e `DB_DATABASE=dimetal` no Coolify (conferido em 16/11), então o merge não desloga ninguém
  e o beta segue como "Dimetal" até ser substituído no projeto 2. Nada a mudar no beta.
- Os nomes padrão (`fluxie`, `fluxie_test`) só valem para instalações novas e para os testes.
- Suíte local: depois do merge, recriar o Postgres de teste com `fluxie`/`fluxie_test` (comando novo no `CLAUDE.md`) e migrar
  uma vez. O banco de dev `dimetal_local` não muda, porque o `.env` local manda nele.
- O `origin` local e os clones de outras máquinas continuam funcionando pelo redirecionamento, mas é melhor atualizar.

## Decisões do builder (minhas, sujeitas à revisão do dono)

- O modelo do beta é escrito com o `FlowBuilder`, com ids legíveis, e não como o JSON exportado.
- O "voce" sem acento do texto do beta é corrigido.
- No `transferir` de fora do expediente, o `setor_id: 1` do beta sai: com `fila_geral: true` ele não tem efeito, e o id 1 não
  existe numa instalação nova.
- O texto neutro da tela de Contatos fica a critério do builder, curto, como os outros subtítulos.
- As specs, planos e auditorias antigas não são reescritas.

## Prova

- **Projeto 0:**
  - portão 7 completo;
  - no servidor local, criar um workflow "Distribuidora" e simular dentro e fora do horário;
  - no beta, depois do merge, conferir que o workflow "Padrão" que já existe não mudou.
- **Projeto 1:**
  - portão 7 completo;
  - nenhum `dimetal` fora das exceções (`git grep -i dimetal` sem `docs/superpowers` nem `docs/ux-audit`, conferido à mão);
  - deploy do beta verde depois do merge;
  - lista de backups do beta mostrando os antigos `dimetal-*` e um backup novo `fluxie-*`.
