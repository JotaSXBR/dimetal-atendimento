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
4. **Foto do contato vinda do WAHA** (redonda, `flux:avatar`): miniatura guardada no RustFS, mostrada na fila, no cabeçalho do ticket, no Histórico
   e em Contatos. Sem foto ou privacidade restrita: monograma. Nenhuma tela chama o WAHA nem o CDN do WhatsApp.
5. **Setores pela UI:** criar, renomear e apagar, além de estado e ordem já existentes, com o padrão (Vendas, Financeiro, Comercial, SAC, Diretoria,
   na ordem do menu, ativos) carregado numa instalação nova sem comando manual e sem recriar o que o admin editou ou apagou.
6. **Fechar a importação de histórico** (validada pelo dono em 10/11): merge do branch `feat/importacao-historico` e atualização da memória.

## Não metas

- Foto de grupo do WhatsApp; troca manual da foto do contato pelo atendente (pode vir depois, o componente já serve).
- Histórico de versões de foto; guardar a imagem original (só a miniatura final fica).
- Qualquer mudança em Automação, backup ou QA de acessibilidade (levas B e C).
- `flux:file-upload` (é Pro; o projeto usa o grátis).

## Anti-metas

- Navegador falando com o WAHA, com o CDN do WhatsApp (`pps.whatsapp.net`) ou com o bucket; chave de API fora do servidor.
- Foto original gravada no RustFS; imagem sem reduzir (vários MB para um círculo de 32 px).
- Apagar setor com ticket aberto ou apontado por workflow; perder o nome do setor nos tickets antigos.
- Foto de cliente guardada para sempre (LGPD): expira sozinha.
- Monograma ou avatar sem nome acessível; rolagem horizontal em 1280, 1920, 2560 ou 360 px; contraste abaixo de AA nos dois temas.

## Decisões do dono

- Renovação da foto de contato a cada **24 horas**; expira em **90 dias** sem atualização, pela rotina de purga de mídia que já existe
  (`PurgeMediaCommand`, tela Retenção).
- Editor de enquadramento como nas redes sociais (arrastar, zoom, moldura), compactando o original no formato do app.
- Logo: quadrado com cantos arredondados; pessoas: redondo. A recusa de imagem não quadrada (decisão de 01/11) vale só se o editor falhar; com o
  editor o dono enquadra.
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

- **Armazenamento:** disco S3 do RustFS (`Message::mediaDisk()` como referência); entrega pelo app (rota própria com cache), no mesmo estilo de
  `MessageMediaController`, porque o login é público e o logo precisa aparecer nele. Chaves de objeto sem dado pessoal, com prefixo por tipo
  (`avatars/contacts/…`, `avatars/users/…`, `branding/…`).
- **Imagem:** servidor reduz para quadrado (~256 px para pessoas e logo; miniatura de contato menor, ~128 px) e converte para WebP; entrada PNG/JPG/WebP
  até 1 MB para o upload do dono, sem SVG; valida o tipo pelo conteúdo, não pela extensão. Biblioteca: `intervention/image-laravel` com o driver GD;
  **conferir o suporte a WebP no GD da imagem de produção antes de adicionar** (mexer no `composer.json` foi autorizado pelo dono em 10/11).
- **Foto de contato:** `GET /api/contacts/profile-picture` do WAHA devolve só a URL; baixar os bytes pelo servidor (nunca expor ao navegador), com
  tempo-limite curto. Buscar na criação do contato e, depois, no máximo uma vez a cada 24 h por contato, quando chega mensagem nova; respeitar o ritmo
  de chamadas ao WAHA já usado no app. Guardar "sem foto" (cache negativo) com o mesmo prazo de 24 h.
- **Expiração:** objeto de contato não atualizado há 90 dias sai pela rotina de purga existente (estender `PurgeMediaCommand` ou comando irmão no
  mesmo agendamento); o contato volta ao monograma e a próxima mensagem repõe a foto.
- **Setores:** criar o padrão em migration ou passo de primeiro acesso (idempotente, sem recriar apagados: guardar marca de que o padrão já foi
  carregado). Cuidar de `sector_user`, `appearsInMenu`, workflows que apontam setor e tickets antigos. Autorização `admin`.
- **Editor:** componente Blade `x-avatar-uploader` (props `shape="round|rounded"`, `size`, `wire:model`), Alpine + Cropper.js carregado só nas telas
  que o usam; teclado e leitor de tela (arrastar tem alternativa por botões de zoom e setas); `motion-safe:`.
- **Qualidade:** tokens do `app.css`, contraste AA, 1280x720, 1920x1080, 2560x1440 e 360 px, claro e escuro; buscas em Blade por `grep` (CodeGraph não
  indexa Blade); sem referências de pesquisa em commit ou PR.

## Deixado ao builder

Tamanhos finais em pixels e qualidade do WebP; nomes das chaves e da rota de entrega; o cabeçalho de cache; se a purga estende o comando atual ou cria
um irmão; onde exatamente a foto do usuário aparece além do perfil e da lista; como o monograma escolhe a cor; a forma de gravar a marca "padrão de
setores já carregado".

## Entrega

Um branch (`feat/leva-a-empresa-avatares`), execução inline, revisão independente do branch inteiro, suíte com Redis em série, PR só quando o dono pedir
(se o PR ficar grande, dividir em dois: empresa+avatares, depois setores). Menores de revisão corrigidos antes do PR.
