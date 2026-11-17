# Projeto 3: primeiro acesso com código e assistente

Data: 17/11/2026. Branch `feat/assistente-primeiro-acesso`. Roteiro: `2026-11-16-fluxie-inbox-roteiro-design.md` (projeto 3).

## Intenção

Quem instala o Fluxie Inbox pelo Coolify não deve precisar de terminal nem de `.env`. Hoje o primeiro admin nasce de
`php artisan app:create-user` no terminal do container, e setores e etiquetas aparecem sozinhos pelo agendador. O projeto 3 troca
isso por: abrir a URL, digitar o código que o Coolify gerou, criar o admin e seguir um assistente curto até o primeiro canal.

Sucesso: numa instalação nova do compose, a pessoa sai da URL vazia até o QR lido sem abrir o terminal, e ninguém que chegue à URL
antes dela consegue virar admin sem o código.

## Objetivos

- Criar o primeiro admin pela tela, protegido pelo código da instalação.
- Assistente de primeiros passos para o admin: empresa, segmento e primeiro canal.
- O segmento decide o que a instalação ganha (Distribuidora ou Em branco).
- Instalações que já existem (staging, production, dev local, testes) não mudam de comportamento.

## Não objetivos (decisões do dono em 17/11)

- **E-mail**: nada de SMTP, recuperação de senha ou convite. Login segue por e-mail, que pode ser inventado
  (`atendente@empresa.com.br`), e o admin cria os demais usuários. Recuperação de senha é funcionalidade futura.
- **Google**: fora do assistente e fora deste projeto. Client ID e Secret seguem no `.env`.
- **Fuso**: fora do assistente (ver Fuso).
- Linha no `docs/deploy.md` sobre manter "Connect to Predefined Network" desligado entra neste PR (rede própria por recurso).
- Outros segmentos além de Distribuidora e Em branco.
- Instalação fora do Coolify (sem o código, o assistente não abre).

## Anti-objetivos

- Admin criado por quem chegou primeiro na URL sem o código.
- Uma instalação que já tem usuário ver o assistente ou perder setores, etiquetas ou automação.
- Assistente interrompido que deixa a instalação num estado sem saída.

## Approaches

| | Caminho | Troca |
|---|---|---|
| A | Tudo numa página pública, antes do login | Uma tela só; mas fechar o navegador depois do admin some com o assistente, e segmento e QR rodariam sem `can:admin` |
| **B (escolhido)** | Metade pública (código + admin, entra logado) e metade logada (empresa, segmento, QR) até a marca `setup.concluido` | Aguenta interrupção; só a criação do admin fica sem login, guardada pelo código; reaproveita a tela Empresa e o cartão do canal |
| C | Ligar o cadastro do Fortify com campo de código + lista de pendências no painel | Reaproveita o cadastro, mas é rota global de registro ligada e desligada por estado, e a lista é fácil de ignorar |

O dono escolheu o B em 17/11.

## Desenho

### Estado da instalação

- **Sem admin**: não existe nenhum usuário com papel admin. Só vale a metade pública.
- **Em configuração**: existe admin e `settings` não tem `setup.concluido = true`. O admin logado cai nos primeiros passos.
- **Concluída**: `setup.concluido = true`. O assistente não existe mais (rotas respondem 404).

Migração nova: se a tabela `users` tem qualquer linha, grava `setup.concluido = true`. Assim staging, production e quem atualiza
de versão pulam o assistente. Instalação nova (banco vazio) fica sem a marca.

### Código da instalação

- Compose: `SETUP_CODE: ${SERVICE_PASSWORD_SETUP}` na âncora `x-app-env`. O Coolify gera e mostra na aba Environment.
- `config('setup.code')` lê `SETUP_CODE`. Vazio: a metade pública mostra uma página dizendo que a instalação não tem código e que o
  admin se cria com `app:create-user` (o comando continua existindo, para fora do Coolify e para emergência).
- Comparação com `hash_equals`. Limite de tentativas por IP (5 por minuto, `RateLimiter`), com a mensagem de espera.
- `docs/deploy.md` explica onde achar o código.

### Metade pública: `/instalar`

- Rota fora do `auth`. Só responde enquanto não há admin; com admin, redireciona para `/login`.
- Enquanto não há admin, `/login` e `/` redirecionam para `/instalar` (quem abre a URL nova cai no lugar certo).
- Um formulário Livewire: código, nome, e-mail, senha e confirmação (regras de senha de `PasswordValidationRules`).
- Ao salvar, numa transação com `pg_advisory_xact_lock`: confere de novo que não há admin, cria o usuário admin ativo e
  verificado (como o `CreateUser`), registra na auditoria (`UserCreated`, autor "Instalação") e faz login. Duas abas ao mesmo tempo:
  a segunda recebe "a instalação já tem admin".
- Visual da tela de login (layout de auth do Flux).

### Metade logada: `/primeiros-passos`

- `auth` + `can:admin`. Middleware: admin logado com a instalação em configuração é redirecionado para cá de qualquer página do app
  (exceto logout, a própria rota e as rotas técnicas: Livewire, broadcasting, webhooks, `/up`, logo). Atendente e supervisor não são
  redirecionados.
- Três passos numa página, com o passo atual guardado em `settings` (`setup.passo`), para voltar onde parou:
  1. **Empresa**: nome (obrigatório) e logo (opcional), sem fuso. Reaproveita `CompanySettings::save` e o `ReceivesAvatar` da tela Empresa.
  2. **Segmento**:
     - **Distribuidora**: `DefaultSectors::install()`, `DefaultTags::install()` e o workflow "Atendimento padrão" criado e publicado
       (`WorkflowPublisher::create` + `publish`, autor o admin).
     - **Em branco**: grava as duas marcas (`sectors.defaults_loaded`, `tags.defaults_loaded`) sem criar nada.
     - O passo só avança uma vez: escolhido o segmento, voltar a ele não reinstala.
  3. **Canal**: nome da sessão (padrão `principal`), cria o canal como `Channels::addChannel` (com auditoria) e mostra o
     `ChannelCard` para ler o QR. Na Distribuidora, ativa o "Atendimento padrão" no canal. Botão **Pular**: conclui sem canal.
- Concluir (QR lido ou Pular): grava `setup.concluido = true` e leva à Fila.

### Agendador

Saem de `routes/console.php` as tarefas `install-default-sectors` e `install-default-tags`: quem instala setores e etiquetas passa
a ser o passo Segmento. Os seeders (`SectorSeeder`, `TagSeeder`, ambiente de teste) continuam chamando `install()`.

### Fuso

Fora do assistente (dono, 17/11). Continua em `APP_DISPLAY_TIMEZONE` (`America/Sao_Paulo` no compose); quem está em outro fuso troca a variável no Coolify. `docs/deploy.md` diz isso. Campo de fuso na tela é funcionalidade futura.

## Restrições

- Laravel 13, Livewire 4.4, Flux 2; CSP `enforce` (sem Alpine inline fora do que o projeto já usa).
- Testes com Postgres (lock advisory) e na suíte com Redis.
- Conferência visual nas resoluções de costume (720p, 1080p, 2K e celular).

## O que fica para o construtor

- Nomes de classes, componentes e views, e a divisão entre middleware e componente.
- Texto das telas, no tom das telas atuais.
- Como o `ChannelCard` entra na página de primeiros passos (componente aninhado como em Canais).

## Decisões minhas depois da revisão do construtor

- **Concluída sem admin** (o único admin desativado ou apagado): `/instalar` segue 404 e os redirecionamentos de `/` e `/login`
  só valem enquanto a instalação não está concluída. A saída é o `app:create-user`. A migração marca `setup.concluido` quando há
  qualquer usuário, de propósito: quem tem usuário é instalação antiga.
- **Instalação antiga sem os padrões**: a mesma migração, quando há usuário e falta a marca de setores ou de etiquetas, chama
  `DefaultSectors::install()` / `DefaultTags::install()`, que é o que o agendador faria. Nada muda para ela.
- **Testes e dev local**: o `TestCase` base grava `setup.concluido = true` no `setUp`; os testes do assistente apagam a marca.
  `DatabaseSeeder` e `AmbienteDeTesteSeeder` também gravam a marca. Os redirecionamentos não dependem de `SETUP_CODE`.
- **Fim do passo Canal**: automático quando o canal fica conectado (o mesmo evento `channel.status` que o `ChannelCard` já escuta),
  ou pelo **Pular**. A criação do canal (validação, `Channel::create`, auditoria) sai de `Channels::addChannel` para uma classe
  compartilhada pela tela Canais e pelo assistente.
- **Segmento atômico**: Distribuidora roda numa transação só (setores, etiquetas, workflow criado e publicado, `setup.segmento` e
  avanço de passo). Falhou, nada fica gravado e a tela mostra o erro; a nova tentativa refaz tudo. Repetir é seguro: `install()`
  já é idempotente e o workflow é procurado pelo nome antes de criar.

## Pendências do dono

Nenhuma. Fuso respondido em 17/11 (fora do assistente).
