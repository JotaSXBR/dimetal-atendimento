# Leva A: empresa na UI, avatares e setores editáveis

Data: 2026-11-10. Só o beta está em escopo. Laravel 13, Livewire 4, Flux 2.21 (grátis), Tailwind 4, RustFS (S3).

## Por que

O dono quer que a identidade e as pessoas apareçam no app: nome e logotipo da empresa configuráveis pela UI (hoje o nome ainda vem do
`.env` em várias telas), foto nos contatos (vinda do WhatsApp) e nos usuários, e setores que o admin cria, renomeia e apaga sem tocar em
código. Está atrasado com o projeto: esta leva junta quatro pedidos num só branch.

## Metas

1. **Nome da empresa pela UI.** `CompanySettings::name()` já lê `company.name` (tela Empresa já edita) com fallback no `APP_NAME`. Menu lateral,
   login, título da aba, texto do QR e `<meta description>` passam a usar `CompanySettings::name()`. O `APP_NAME` fica só como fallback e
   para prefixos técnicos (cache, sessão, Redis).
2. **Logotipo da empresa** na tela Empresa: um arquivo 1:1 para menu, login e centro do QR. **Moldura quadrada com cantos arredondados** (decisão
   de 10/11). Sem logo ou removido: monograma com a inicial do nome. Substitui o logo do Laravel e o `public/images/qr-logo.svg` provisório.
3. **Foto de usuário** (redonda) com o mesmo editor, no perfil do usuário e na lista de usuários; aparece onde o usuário aparece (cabeçalho do
   ticket, assinatura de quem atende, Auditoria quando houver espaço).
4. **Foto do contato vinda do WAHA** (redonda): **só visualizada, nunca guardada** (decisão do dono, 10/11: a foto do cliente não é baixada para o
   RustFS nem para disco). O app guarda apenas a URL que o WAHA devolve e a entrega ao navegador por uma rota própria que repassa a imagem em
   memória, sem gravar. Aparece na fila, no cabeçalho do ticket, no Histórico e em Contatos. Sem foto, privacidade restrita ou URL vencida:
   monograma. Nenhuma tela chama o WAHA nem o CDN do WhatsApp.
5. **Setores pela UI:** criar, renomear e apagar, além de estado e ordem já existentes, com o padrão (Vendas, Financeiro, Comercial, SAC, Diretoria,
   na ordem do menu, ativos) carregado numa instalação nova sem comando manual e sem recriar o que o admin editou ou apagou.
6. **Importação de histórico:** já está na `main` (commit `005faa7`, conferido em 10/11); não há merge a fazer. Só atualizar a memória.

## Não metas

- Foto de grupo do WhatsApp; troca manual da foto do contato pelo atendente (pode vir depois, o componente já serve).
- Histórico de versões de foto; guardar a imagem original (só a miniatura final fica).
- Qualquer mudança em Automação, backup ou QA de acessibilidade (levas B e C).
- `flux:file-upload` (é Pro; o projeto usa o grátis).

## Anti-metas

- Navegador falando com o WAHA, com o CDN do WhatsApp (`pps.whatsapp.net`) ou com o bucket; chave de API fora do servidor.
- Foto de contato gravada em disco, no RustFS ou em qualquer cache do servidor; foto original de usuário ou logo gravada; imagem de usuário/logo sem reduzir.
- Apagar setor com ticket aberto ou apontado por workflow; perder o nome do setor nos tickets antigos.
- URL de foto de cliente guardada para sempre (LGPD): expira sozinha.
- Monograma ou avatar sem nome acessível; rolagem horizontal em 1280, 1920, 2560 ou 360 px; contraste abaixo de AA nos dois temas.

## Decisões do dono

- Renovação da URL da foto de contato a cada **24 horas**; a URL expira em **90 dias** sem atualização, pela rotina de purga de mídia que já existe
  (`PurgeMediaCommand`, tela Retenção). A foto do contato não é baixada nem guardada: só visualizada, pela rota própria que repassa a imagem.
- Editor de enquadramento como nas redes sociais (arrastar, zoom, moldura), compactando o original no formato do app.
- Logo: quadrado com cantos arredondados; pessoas: redondo. **Um só método para os três** (logo, usuário e contato): mesmo editor e mesmo
  tratamento no servidor; só a moldura (CSS) muda. Imagem não quadrada nunca é recusada: o editor abre com recorte central (cobrindo o maior
  quadrado possível) e o dono ajusta; o servidor repete o recorte central como rede de segurança. A recusa de 01/11 deixa de valer.
- Fotos só são vistas depois do login. O logo é a exceção, porque aparece na tela de login.
- Pesquisa na documentação de Flux, Livewire e Laravel: nenhum tem componente de recorte; `flux:file-upload` é Pro. Seguir com o desenho abaixo.
- Setores: só apaga sem ticket aberto e sem workflow apontando; o histórico guarda o nome do setor.

## Abordagens consideradas

- **Editor Cropper.js v2 (web components) num componente Alpine, envio por `WithFileUploads`, redução no servidor** (escolhida): é MIT, ~13,8 mil
  estrelas, sem Vue/React, combina com o stack e com o upload que a Inbox já usa; o Filament usa a mesma biblioteca. O navegador envia só o
  recorte já pequeno e o servidor revalida.
- **Pacote `artflow-studio/uploader`:** traz o editor pronto, mas é pouco conhecido, sem garantia de manutenção nem de Laravel 13, e acopla a tela a
  um pacote de terceiros.
- **Recorte feito à mão (canvas, arrastar, pinça):** zero dependência, mas muito código de gesto para manter.
- **Só servidor (recorte central automático):** simples, mas tira do dono o controle do enquadramento do logo.
- **Guardar a URL do CDN do WhatsApp:** descartada; a URL é assinada e expira, vaza o IP do atendente e sobrecarrega o WAHA por linha de tela.

## Restrições

- **Armazenamento (usuário e logo):** disco S3 do RustFS (`Message::mediaDisk()` como referência); entrega pelo app (rota própria, lendo o stream
  do disco), no estilo de `MessageMediaController`. Chaves de objeto sem dado pessoal (`avatars/users/…`, `branding/…`; este último é só o logo).
  **Foto de contato não usa o disco:** só a URL no banco (ver abaixo).
- **Quem vê:** `avatars/*` exige sessão autenticada. Só o logo (`branding/*`) é público, porque a tela de login o mostra.
- **Cache da entrega:** a URL leva versão (hash curto do conteúdo, guardado junto do caminho no banco), então trocar a imagem muda a URL e o
  cabeçalho pode ser longo: `Cache-Control: private, max-age=31536000, immutable` nas fotos e `public` no logo, mais `ETag`. Sem versão na
  URL não se usa cache longo.
- **Imagem (usuário e logo):** o navegador já recorta e reduz (canvas quadrado, ~512 px) e envia só esse recorte, nunca o original; o limite de 1 MB vale para esse
  recorte. O servidor revalida, aplica recorte central de segurança, reduz (~256 px) e converte para WebP;
  entrada PNG/JPG/WebP, sem SVG; tipo pelo conteúdo, não pela extensão. Biblioteca: `intervention/image-laravel` (v1.x, driver GD por padrão,
  `Image::decode` aceita o upload e o binário baixado), numa única classe de serviço usada por logo, usuário e contato. **Conferir o WebP no GD da
  imagem de produção antes de adicionar** (`composer.json` autorizado em 10/11).
- **Foto de contato:** `GET /api/contacts/profile-picture` (query `contactId`, `session`, opcional `refresh`) do WAHA devolve só a URL completa do CDN
  do WhatsApp (`profilePictureURL`, pública e que vence); o WAHA a guarda em cache por 24 h. Guardamos só essa URL (exigindo `https`; outros
  apps do dono, CRM e CRMv2, fazem o mesmo, sem lista de hosts, porque a URL vem do nosso próprio WAHA). **A busca da URL roda em job na fila,
  nunca no caminho do webhook**: WAHA lento ou fora do ar não atrasa a ingestão. Disparo: na criação do contato e, depois, no máximo uma vez a
  cada 24 h por contato, quando chega mensagem nova; sem `refresh=true` (o WAHA avisa de `rate-overlimit`); respeitar o ritmo de chamadas ao WAHA.
- **Entrega da foto de contato:** rota autenticada que, ao ser pedida, o servidor busca a URL guardada (tempo-limite curto, sem seguir redirecionamento,
  só `https`, teto de 2 MB, só `image/jpeg|png|webp`) e **repassa a imagem ao navegador direto da memória, sem gravar nada**, com
  `Cache-Control: private, max-age=86400`; o navegador guarda por 24 h e não volta a pedir. O navegador nunca fala com o CDN. URL vencida
  (403/404/410) ou resposta inválida: responde 404 (a tela cai no monograma) e enfileira nova busca da URL.
- **Resposta sem foto (GOWS, o engine em uso):** a documentação do WAHA lista o endpoint como suportado no GOWS, mas não descreve o corpo
  quando o contato não tem foto ou restringiu a privacidade; na prática o WhatsApp devolve vazio, e o cliente pode ver `null`, campo ausente,
  404 ou erro. Regra: **200 com URL** = baixar; **200 com `null`/ausente/vazio ou 404** = "sem foto" definitivo (cache negativo de 24 h, monograma);
  **timeout, 5xx, 429 ou falha ao baixar** = falha transitória, não grava cache negativo longo, refaz com atraso crescente (no máximo 3 tentativas)
  e então tenta de novo na próxima mensagem após 1 h. Antes de fechar o plano, conferir no beta com um contato com foto e outro sem, para
  registrar o corpo real e ajustar os testes a ele.
- **Estado no banco** (`contacts`): `avatar_url` (a URL do CDN) e `avatar_checked_at` (última checagem definitiva). `avatar_url` nulo com
  checagem preenchida = "sem foto". Isso diz se a próxima mensagem deve buscar de novo.
- **Expiração:** contato com `avatar_checked_at` há mais de 90 dias tem `avatar_url` e `avatar_checked_at` zerados pela rotina de purga existente
  (estender `PurgeMediaCommand`). O contato volta ao monograma e a próxima mensagem repõe a URL. Contato ativo renova a cada 24 h e nunca expira.
- **Setores, apagar (conferido em outros apps):** o CRMv2 do dono apaga e zera `sectorId` nos tickets (`set null`); o Chatwoot apaga o time e só
  tira o time das conversas; o CRM antigo do dono arquiva as etiquetas em vez de apagar (`archived`). Nenhum perde o histórico com segurança
  nem recusa o apagar. Aqui o dono quer recusar quando há uso e manter o nome nos tickets antigos, então: `sectors` ganha `SoftDeletes`. Hoje `tickets.sector_id` é `nullOnDelete` (apagar de verdade faria os tickets antigos
  perderem o nome do setor) e `quick_replies.sector_id` é `cascadeOnDelete` (apagaria as respostas rápidas). Com soft delete o ticket antigo
  continua mostrando o nome. O índice único do nome passa a valer só para setores não apagados (índice parcial no PostgreSQL), para poder recriar
  um nome apagado. Antes de apagar, recusar com mensagem clara se houver: ticket aberto, workflow (rascunho ou publicado) com nó apontando o
  setor, ou resposta rápida do setor. Ao apagar, soltar os vínculos em `sector_user`. Renomear mexe só no nome. Criar entra no fim da ordem do menu,
  ativo. Autorização `admin`.
- **Setores, padrão:** não vai em migration (entraria em todo banco de teste e quebraria contagens). Um passo idempotente, no mesmo ponto do deploy
  que já roda `migrate` (o builder confere onde fica), cria Vendas, Financeiro, Comercial, SAC e Diretoria uma única vez, protegido pela marca
  `sectors.defaults_loaded` em `Setting`; se já houver setores (o beta já tem) só grava a marca. Apagar um padrão nunca o recria. O
  `SectorSeeder` passa a usar a mesma classe.
- **Editor:** componente Blade `x-avatar-uploader` (props `shape="round|rounded"`, `size`, `wire:model`), Alpine + Cropper.js v2 (`cropperjs`,
  elementos `cropper-canvas`/`cropper-image`/`cropper-selection` com proporção 1:1, `$toCanvas({width, height})` e `toBlob`). Instalado por npm e
  empacotado pelo Vite, com `import()` dinâmico para só carregar nas telas que usam (nada de CDN). Teclado e leitor de tela (arrastar tem
  alternativa por botões de zoom e setas); `motion-safe:`. O recorte usa canvas e `blob:`: se o CSP for ligado na leva C, liberar `img-src blob:`.
- **Qualidade:** tokens do `app.css`, contraste AA, 1280x720, 1920x1080, 2560x1440 e 360 px, claro e escuro; buscas em Blade por `grep` (CodeGraph não
  indexa Blade); sem referências de pesquisa em commit ou PR.

## Deixado ao builder

Tamanhos finais em pixels e qualidade do WebP; nomes das chaves e da rota de entrega; se a purga estende o comando atual ou cria
um irmão; onde exatamente a foto do usuário aparece além do perfil e da lista; como o monograma escolhe a cor; a forma de gravar a marca "padrão de
setores já carregado".

## Entrega

Um branch (`feat/leva-a-empresa-avatares`), execução inline, revisão independente do branch inteiro, suíte com Redis em série, PR só quando o dono pedir
(se o PR ficar grande, dividir em dois: empresa+avatares, depois setores). Menores de revisão corrigidos antes do PR.
