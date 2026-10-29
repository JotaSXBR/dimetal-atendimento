# Contatos (Fase 2) — design

Data: 2026-10-29. Status: aguardando aprovação do dono. Pesquisa de apoio:
`docs/superpowers/notes/2026-10-29-contatos-google-people.md`. Referência de produto: CRMv2
(`C:\Projetos\CRMv2\docs\product\contacts-google-sync.md` e `contacts-identity.md`).

## 1. Por que

O inbox mostra o contato como o nome que o próprio cliente escolheu no WhatsApp (push name) e, sem nome, como
`5516997524950@c.us`. Não existe cadastro: ninguém corrige o nome, anota empresa ou e-mail, e o celular pareado não aprende
o nome (o WhatsApp mostra os nomes da agenda do aparelho). Esta fase cria o cadastro de contatos, no formato do Google
People API, e espelha no Google Contacts da empresa, que serve de backup e de agenda do celular.

Esta fase vem **antes do bot** (decisão do dono, 29/10/2026), depois da "leva de anotações" do inbox.

## 2. Objetivos

1. Cadastro local de contatos com os campos do People API, editável na ficha do inbox e numa tela Contatos.
2. Telefone como identidade; contato por LID sem telefone aparece de forma legível.
3. Telefone sempre exibido em formato humano, `(16) 99752-4950`, nunca `...@c.us`.
4. Espelho automático no Google Contacts, só de ida, **só depois da primeira edição** do contato.
5. Se o número já existe no Google, o contato dele é atualizado (nunca duplicado e sem apagar dados que o app não tem).

## 3. Não-objetivos (depois da v1, anotados pelo dono)

- Importar a agenda do Google para o sistema (puxar). O desenho guarda `resourceName` e `etag` para isso.
- Excluir contato no sistema e no Google (pedido de apagar dados). Hoje não existe exclusão de contato.
- Foto, grupos/etiquetas, campos personalizados, mais de uma conta Google.
- Auditoria de quem editou cada campo.

## 4. Anti-objetivos (a fase falha se)

- Cada contato que entra gerar uma requisição ao Google (só contato editado sincroniza).
- O push name sobrescrever um nome que um atendente editou.
- O Google ganhar contato duplicado, ou perder dado que a pessoa já tinha lá na primeira adoção.
- O envio ao Google atrasar mensagens do WhatsApp (fila separada).
- A conexão com o Google cair sem ninguém perceber (status visível, "reconectar").

## 5. Restrições

- Laravel 13 / Livewire 4 / Flux / PostgreSQL 18; um desenvolvedor; um worker (sem container novo).
- Escrita no Google é sequencial (exigência do People API). Cota de escrita não documentada: tratar 429 com espera.
- Conta de teste agora (mesmo número do beta); conta da Dimetal depois. Credenciais e conta trocáveis sem código.
- Conta de teste pode ficar em "Testing" (refresh token expira em 7 dias). Antes de ligar a conta da Dimetal em
  produção, publicar o app OAuth ou usar "Interno" se for Workspace.
- Só o beta está em escopo.

## 6. Abordagens consideradas

**Onde guardar os dados.** (A) colunas reais; (B) JSON plano como no CRMv2. **Escolhida A**: o conjunto é pequeno e fixo
(segue o People API), a lista precisa buscar por nome, telefone, e-mail e empresa, e o PHPStan tipa as colunas.

**Como falar com o Google.** (A) Socialite para o OAuth + classe fina com `Http::`; (B) `google/apiclient`. **Escolhida A**:
são 4 chamadas; o SDK traz centenas de classes para isso.

**Execução do envio.** Um job por contato numa fila `google`, com um processo próprio dentro do worker que já existe
(terceiro processo em `docker/worker.sh`), mantendo a escrita sequencial sem container novo. Alternativa descartada:
varredura agendada como única via (atrasa até 10 min); fica só como rede de segurança.

## 7. Modelo de dados

**`contacts`** ganha colunas (todas anuláveis): `phone2` (32), `email` (255), `company` (120), `job_title` (120),
`address` (500), `notes` (4000), `birthday` (date), `urls` (json, até 5), `edited_at` (timestamp, "primeira edição"),
e o estado do espelho: `google_resource_name` (único), `google_etag`, `google_payload_hash`, `google_synced_at`,
`google_attempted_at`, `google_error` (código curto). Já existem `wa_id`, `lid`, `name`, `nickname` (apelido interno, nunca vai ao Google).

**`google_connections`** (no máximo uma linha): `account_email`, `access_token` e `refresh_token` (cast `encrypted`),
`expires_at`, `status` (`connected` | `needs_reauth`), `last_error`, `connected_at`.

## 8. Comportamentos

**Identidade e nome (`ResolveContact`)**
- Hoje o push name sobrescreve `name` a cada mensagem. Passa a nomear só contato **novo** ou **nunca editado**
  (`edited_at` nulo). Contato editado nunca tem o nome trocado pelo push name.
- Quando existem dois registros (um só com LID, outro com o telefone) e o telefone aparece, o do LID é fundido no do
  telefone numa transação: tickets e mensagens são reapontados, o registro do LID é removido. Hoje o código só evita o
  erro de índice único e deixa duplicado.

**Exibição do telefone.** Um formatador único (`PhoneFormat`): `5516997524950@c.us` → `(16) 99752-4950`; fixo →
`(16) 3333-4444`; outro país → `+14155550100` (sem biblioteca de telefone); contato só com LID → "Número oculto pelo WhatsApp". Usado no inbox
(`inbox.blade.php`: 46, 50, 72, 85) e nas telas novas. **Entra na leva de anotações**, antes desta fase.

**Edição.** Salvar na ficha ou na tela Contatos grava os campos e, na primeira vez, `edited_at`. Qualquer usuário ativo
edita contatos (escolha do builder). Telefone principal não é editável. Criação manual: nome + telefone (obrigatório),
normalizado para o mesmo formato de `wa_id` (apenas dígitos, 55 por padrão para número brasileiro sem DDI), para que a
próxima mensagem do cliente caia no mesmo registro.

**Quando vai ao Google.** Só com Google conectado, contato com `edited_at` e telefone (`@c.us`) ou e-mail. Salvou →
job `SyncContactToGoogleJob` (único por contato). Ao conectar a conta, enfileira os contatos já editados e ainda não
enviados. Uma rotina a cada 10 minutos (`app:sync-google-contacts`) reenfileira os pendentes e os com erro antigo.

**Sincronização de um contato** (porta de `google-contact-sync.ts`):
1. Monta a `Person` (mapeador `ContactToPerson`): `names` (um valor), `phoneNumbers` (+E.164 do WhatsApp e `phone2` se
   diferente, sem `type`), `emailAddresses`, `organizations`, `addresses.formattedValue`, `biographies`, `birthdays.date`,
   `urls`. Valores com caracteres de controle ou bidi são descartados; URL só http(s) sem usuário e senha.
2. Hash igual ao último enviado e `resourceName` conhecido → nada a fazer.
3. `resourceName` conhecido → `GET` para o `etag` atual e `updateContact` com a máscara completa (campo esvaziado limpa).
   Desconhecido → `searchContacts` (aquecimento uma vez, por telefone e e-mail), conferindo igualdade normalizada.
   Achou → atualiza só os campos que o app tem (não apaga dado alheio). Não achou → `createContact`.
4. `etag` velho (400 `failedPrecondition`) → relê e tenta uma vez. 429 → espera (respeita `Retry-After`).
   `POST` que falhou depois de enviado não é repetido (evita duplicar), só o 429.
5. Grava `resourceName`, `etag`, hash, data e limpa o erro; falha grava o código em `google_error`. 401/403 marca a
   conexão como `needs_reauth`.

**Conexão (admin).** Tela `configuracoes/google`: dados da conta, saúde do token, contagem de sincronizados e
pendentes, último erro, botões Conectar / Reconectar / Desconectar. OAuth pelo Socialite com escopos `contacts` e
e-mail, `access_type=offline` e `prompt=consent`. Credenciais em `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` e
`GOOGLE_REDIRECT_URI` (`{APP_URL}/configuracoes/google/callback`). Desconectar apaga os tokens; o que já foi para o Google fica lá.

**Telas.** Ficha editável no painel lateral do inbox (com o estado do Google: não conectado, pendente, sincronizado,
erro, e "Reenviar"). Tela Contatos (`/contatos`, qualquer usuário): lista paginada com busca por nome, telefone, e-mail
e empresa, "Novo contato", e a mesma ficha. Os dois usam o mesmo componente Livewire.

## 9. Fatias de entrega (ordem, cada uma com testes)

1. Formatador de telefone + uso no inbox *(vai na leva)*.
2. Migração de colunas, modelo, `ResolveContact` (nome só em contato nunca editado; fusão LID/telefone).
3. Ficha editável no inbox + criação manual + `edited_at`.
4. Tela Contatos (lista, busca, edição).
5. `google_connections`, OAuth com Socialite, tela de conexão.
6. `PeopleClient`, `ContactToPerson`, serviço de sincronização, job, fila `google`, rotina de reenvio, estado na ficha.

## 10. Testes

`Http::fake` para o Google (criar, adotar existente, `etag` velho, 429, 401); testes de unidade do mapeador e do
formatador; `ResolveContact` (push name não sobrescreve nome editado; fusão); Livewire da ficha e da lista; contato não
editado nunca gera requisição ao Google.

## 11. Decisões do builder (contestáveis)

- Qualquer usuário ativo edita contatos; só admin conecta o Google.
- Criação manual de contato incluída (o dono pediu "cadastro").
- Fusão LID/telefone incluída na fase, para a lista não mostrar duplicados.
- Apelido interno nunca vai ao Google.
- Reenvio automático a cada 10 min como rede de segurança, além do job imediato.

## 12. Em aberto (dono)

- URL de retorno do OAuth do beta (preciso do domínio do beta para cadastrar no console do Google).
- Credenciais do projeto Google (`.env.local`) e quais e-mails entram como usuários de teste.
