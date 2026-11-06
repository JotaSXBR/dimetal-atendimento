# Nome do contato pelo WhatsApp: plano

> Mudança pequena, alinhada no chat com o dono em 06/11 (nome da agenda primeiro, depois push name; contatos novos também). Execução inline.

**Goal:** preencher o nome do contato com o nome salvo na agenda do WhatsApp (`name`), senão o `pushname`, via `GET /api/contacts`.

**Architecture:** `WahaContacts::contactNames` consulta o WAHA; `FillContactName` aplica a regra e decide quando consultar (uma vez por dia por contato, só sem nome de agenda e sem edição); `FillContactNameJob` roda na fila; `IngestMessage` só chama `queueFor`. A coluna `contacts.name_source` ('agenda') impede o push name de sobrescrever o nome da agenda; `edited_at` não serve porque liga o envio ao Google.

**Spec:** conversa de 06/11 (sem doc separado).

## Review Focus
- Contato editado à mão nunca muda; push name novo não apaga nome da agenda; WAHA fora do ar não derruba a ingestão; `@lid` sem telefone consulta pelo lid; fusão lid→telefone leva `name_source`.

### Task 1: coluna, serviço, job e gatilho
- Create: migration `add_name_source_to_contacts`, `app/Services/Inbox/FillContactName.php`, `app/Jobs/FillContactNameJob.php`; Modify: `Contact` (fillable), `ResolveContact` (não sobrescreve nome da agenda), `MergeLidContact` (campo), `WahaContacts::contactNames(string $id): array{agenda: ?string, push: ?string}`, `IngestMessage` (uma linha), `ResolveLidPhone` (chama `queueFor` no sobrevivente).
- Tests: `tests/Feature/Inbox/FillContactNameTest.php`, `tests/Feature/Waha/WahaContactNamesTest.php`.
- [ ] RED, GREEN, Pint/Larastan/PHPMD, commit `feat: nome do contato vem da agenda do WhatsApp`.
