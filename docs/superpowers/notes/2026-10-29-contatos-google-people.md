# Notas — Contatos e Google People API (29/10/2026)

Levantamento para a Fase 2 (Contatos). Fontes: documentação oficial do People API (consultada em 29/10/2026) e o que foi
feito no CRMv2 (`C:\Projetos\CRMv2`, `docs/product/contacts-google-sync.md`, `contacts-identity.md`,
`packages/core/src/modules/external-connections/google-*.ts`).

## O que o CRMv2 já decidiu (reaproveitar o raciocínio, portar para PHP)

- Perfil no formato do People API, campos planos em JSON (`metadata`), sem coluna por campo: nome, telefone (+adicional),
  e-mail, empresa + cargo, endereço (texto), notas, aniversário, até 5 URLs. Só campos padrão; nada de `userDefined`.
- **O telefone é a identidade**: `<dígitos>@c.us`. O LID é alias, resolvido por `_data.Info.SenderAlt` (tirar o sufixo
  `:<device>`) ou `GET /api/{session}/lids/{lid}`. Se existem os dois contatos, o do LID é fundido no do telefone numa
  transação, sem perder mensagem. Nome do WhatsApp (push name) só nomeia contato novo, nunca sobrescreve.
- Sync **só de ida** (app -> Google), contínuo, uma conta Google por instalação. Casamento por telefone E.164; a busca do
  Google é por prefixo, então conferir igualdade normalizada no cliente antes de escrever. Foto: nunca. Exclusão: não
  propaga. Guarda `resourceName`, `etag`, hash do payload e `lastError` no próprio contato.
- Execução **sequencial** por conexão; 429/5xx com backoff; POST que deu erro depois de enviado NÃO é repetido (risco de
  duplicar), só 429.
- Sanitização na saída para o Google: sem caracteres de controle/bidi, URLs só http(s) sem usuário e senha.

## O que a documentação do Google confirma (e o que muda)

| Ponto | Fato | Efeito no desenho |
|---|---|---|
| Escrita | "Mutate requests for the same user should be sent sequentially" | Fila própria com um consumidor (ou `WithoutOverlapping`) para o Google |
| `createContact` | `POST /v1/people:createContact`; mais de um valor em `names`, `birthdays`, `biographies`, `genders` dá 400 | Mapeamento gera no máximo um valor nesses campos |
| `updateContact` | `etag` de `metadata.sources` obrigatório; `etag` velho dá 400 `failedPrecondition`; todos os campos em `updatePersonFields` são **substituídos** | Reler, mesclar e tentar uma vez; limpar campo = listá-lo na máscara sem valor (a doc não diz explicitamente; o CRMv2 faz assim e testou) |
| Busca | `people:searchContacts` usa cache preguiçoso: chamar com query vazia antes (aquecimento) e esperar alguns segundos; busca por prefixo | Para achar contato existente, aquecer uma vez por execução; preferir guardar `resourceName` |
| Lote | `batchCreateContacts` aceita até 200 contatos por chamada, escopo `contacts` | Útil só na carga inicial; para o dia a dia, um por vez e em ordem |
| Leitura incremental | `people.connections.list`: `pageSize` até 1000, `requestSyncToken`, `syncToken`; token vale 7 dias; expirado devolve erro `EXPIRED_SYNC_TOKEN` e exige carga completa; apagados voltam com `metadata.deleted=true`; escritas podem levar minutos para aparecer | É o caminho do sync de volta (Google -> app) numa fatia futura; guardar `resourceName`/`etag` agora deixa isso aberto |
| Escopo | `https://www.googleapis.com/auth/contacts` (escrita) ou `contacts.readonly` | Escopo sensível do Google |
| Token de renovação | Em projeto externo com status **Testing**, o refresh token expira em **7 dias**; também expira sem uso por 6 meses, ao revogar, ou ao passar de 100 por cliente | **Decisão de OAuth necessária**: publicar o app (status "In production") ou usar tipo "Internal" se a conta for Google Workspace; senão o sync para toda semana |
| Cota | Não achei número oficial de escritas por minuto. Um relato de comunidade fala em ~30 escritas/min por projeto (não confirmado) | Tratar 429 com backoff e conferir em Cloud Console > APIs > People API > Cotas |
| Visibilidade no WhatsApp/Android | A documentação não diz nada | O CRMv2 afirma que o contato aparece no aparelho pareado; confirmar no teste real |

## Bibliotecas PHP

- `swapneal-dev/laravel-google-contacts`: 0 estrelas, 80 instalações, última versão em fev/2023 (Laravel 8/9). Não serve.
- Caminho provável: **Laravel Socialite** (provider Google, com `access_type=offline` e `prompt=consent` para receber o
  refresh token) para o OAuth, e o cliente HTTP do Laravel (`Http::`) chamando o People API direto, como o CRMv2 fez
  com `fetch`. `google/apiclient` é a alternativa oficial, mas é pesada para 5 chamadas. A decidir na spec.

## Pontos em aberto para a spec

1. Tipo de conta/OAuth: Google Workspace da empresa (app "Internal") ou conta Gmail comum (precisa publicar o app).
2. Credenciais: variáveis de ambiente (como o WAHA) ou tela de configuração com segredo cifrado no banco.
3. Escopo da primeira entrega: modelo + tela + identidade por telefone, e o push do Google na mesma leva ou na seguinte.
4. Pull do Google (importar contatos existentes e nomes) fica para depois, mas o dono marcou como desejado no CRMv2.
