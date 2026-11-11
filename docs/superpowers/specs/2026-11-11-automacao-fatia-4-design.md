# Automação, fatia 4: blocos novos, simulador e galeria por segmento

Continuação de `2026-11-11-automacao-leva-b-design.md` (fatias 1 a 3 já mergeadas). Esta spec **substitui** o texto da "Fatia 4" daquele documento: o recorte mudou depois de ler o motor e de uma pesquisa (resumida no fim, sem fontes: as referências ficam no chat e no ledger).

## Intenção

O dono opera a automação no beta praticamente como vai operar em produção, e quer o app completo: fluxos mais ricos, a possibilidade de **testar sem tocar em cliente nenhum** e **exemplos prontos de atendimento para vários segmentos** (o dele é distribuidora; outros entram para o app servir a qualquer negócio). O que existe hoje é um motor pequeno e correto, com editor visual novo; falta o que torna o fluxo útil de verdade (coletar dado válido, passar o dado ao atendente, entregar a quem já conhece o cliente) e a confiança de publicar sem medo.

## Objetivos

1. **Atribuir a** no bloco Transferir: o ticket entra na fila do setor e é assumido por uma pessoa fixa (o vendedor da carteira).
2. **Horário de atendimento** na paleta: uma Condição já em modo horário, com as portas rotuladas "Aberto" e "Fechado". Não nasce tipo novo.
3. **Pergunta com validação** de CNPJ (numérico e alfanumérico), CPF, CEP, telefone e data, além de e-mail e número.
4. **Condição nova**: "o contato já tem nome" e "o cliente já foi atendido antes", para pular perguntas que o bot já sabe responder.
5. **Bloco Nota para a equipe**: grava uma nota interna com o que o bot coletou, para o atendente abrir o ticket já sabendo.
6. **Simulador "Testar"** no editor: conversa de teste que roda o **motor real** sobre o rascunho, sem gravar nada e sem enviar nada.
7. **Galeria de modelos por segmento**: modelos prontos (distribuidora e mais sete), cada um montado a partir dos blocos acima e conferido pelo simulador.

## Não objetivos

- Integração com ERP, API externa, webhook, pesquisa de satisfação (continua fora, como na spec da leva). Por isso **nenhum modelo promete resposta automática de status, boleto ou agenda**: eles coletam e entregam.
- Rodízio de atribuição entre quem está online (fica para depois; "Atribuir a" é pessoa fixa).
- **Etiquetas do contato** (hoje a etiqueta é só do ticket; o dono quer etiqueta também no contato, como em outros inboxes). Fica **anotado para uma fatia própria**: pede coluna ou tabela nova, tela de contato, filtro em Contatos e decisão sobre o que acontece com a etiqueta do ticket. Quando existir, a Condição "tem a etiqueta X" e o Etiquetar com destino (ticket ou contato) entram juntos.
- Salvar o próprio fluxo como modelo, exportar e importar modelo (feature à parte).
- Horário por canal, tema claro e QA de acessibilidade completo (leva C).
- Mudar o comportamento de qualquer workflow já publicado ou o texto que o cliente recebe hoje.

## Anti-objetivos (seria falha mesmo funcionando)

- O simulador mandar uma mensagem ao cliente real, gravar ticket, mensagem, nota, etiqueta, contato ou evento, ou disparar job, broadcast ou alerta.
- Duas implementações das regras dos blocos (uma no motor, outra no simulador).
- Um modelo dizer ao cliente algo que o bot não consegue cumprir ("vou consultar seu pedido").
- Pedir dado pessoal sem necessidade: CPF só onde o fluxo precisa, com a finalidade dita na própria pergunta.

## Restrições

- Laravel 13, Livewire 4.4, Flux 2, Rete.js 2. Sem dependência nova (a validação de CNPJ é interna: o cálculo é módulo 11 de poucas linhas, verificado com exemplo oficial).
- O grafo publicado é versionado: toda chave nova é **opcional** e o padrão é o comportamento de hoje. Versões já publicadas continuam válidas e rodando igual.
- O motor é um só, em produção e no simulador.
- **Nomes:** a automação por blocos é o **Bot**; quando houver IA, ela se chamará **IA** (ou Agente) na interface e nos dados. Nada novo mistura os dois nomes.
- `ticket_notes.user_id` é `NOT NULL` hoje. A nota automática não tem autor humano. Em vez de só aceitar nulo (que não diz *quem* escreveu), a nota ganha uma **origem** própria: coluna `origin` (texto, padrão `equipe`, valores `equipe` e `bot` agora; `ia` quando a IA existir) e `user_id` passa a aceitar nulo (as duas mudanças são compatíveis com o código antigo: linhas existentes ficam `equipe` com autor). Regra: origem `equipe` exige autor; as demais não têm autor. A tela mostra o rótulo da origem ("Bot", depois "IA") no lugar do nome; nota que não é da equipe não é editável nem apagável por usuário. Enum `NoteOrigin` com `label()`, no padrão dos outros enums do app.
- Texto novo para o cliente nasce igual ao de hoje; textos de modelo são texto de modelo (o dono edita depois).
- Sem referência a projetos ou bibliotecas de terceiros em código, commit e PR.

## Abordagens

**Efeitos do motor (simulador).** Escolhida pelo dono: uma interface de efeitos que o motor usa para tudo que sai dele. A implementação real é o código de hoje, movido; a do simulador só registra. Descartadas: rodar o motor numa transação desfeita (broadcast, alertas e jobs saem da transação) e reescrever em JavaScript (duplica regra).

**Modelos por segmento.** Escolhida pelo dono: um arquivo PHP por segmento, com um montador curto de grafo (encadear blocos e ligações sem escrever coordenadas; o editor organiza o desenho na primeira abertura quando o grafo vem sem posição). Descartadas: JSON em `resources/` (perde concisão) e modelos gravados no banco com "salvar como modelo" (feature à parte).

## Fatia 4a: blocos e validações

**Atribuir a.** `transferir.data.atribuir_a` (id de usuário, opcional). Depois de o ticket entrar na fila (`enterQueue`), o motor tenta assumi-lo para a pessoa pelo caminho de `ClaimTicket` (atômico). Fica só na fila do setor, sem erro, se a pessoa estiver inativa, não atender o setor (`canHandleSector`) ou o ticket já tiver dono. A publicação recusa pessoa inexistente ou inativa (o validador recebe a lista de usuários ativos, como já recebe setores e etiquetas). O aviso de "ticket na fila" não deve tocar para todos quando o ticket nasce atribuído: o construtor confere como o alerta de fila e o de atribuição se comportam e escolhe o aviso certo.

**Horário na paleta.** Item "Horário de atendimento" que cria uma `condicao` com `kind: horario`. As portas continuam `true` e `false` no grafo (versões publicadas seguem válidas); o canvas e o painel as rotulam "Aberto" e "Fechado" quando o `kind` é horário. O modelo "Atendimento com horário" passa a usá-lo.

**Pergunta com validação.** `validacao` ganha `cnpj`, `cpf`, `cep`, `telefone`, `data`. Regras:
- CNPJ: 14 posições, as 12 primeiras de `0-9A-Z`, os dois dígitos numéricos; módulo 11 com valor do caractere igual ao ASCII menos 48, pesos 2 a 9 da direita para a esquerda; resto 0 ou 1 dá dígito 0, senão 11 menos o resto. Aceita máscara e minúsculas; recusa sequência repetida (`00000000000000`). Vetores de teste: `12ABC34501DE` fecha com `35`; `112223330001` fecha com `81`.
- CPF: 11 dígitos, módulo 11, recusa sequência repetida.
- CEP: 8 dígitos. Telefone: 10 ou 11 dígitos com DDD, `+55` opcional. Data: `dd/mm/aaaa` existente no calendário.
- Resposta válida é guardada **normalizada e formatada** (CNPJ `11.222.333/0001-81`, CPF `111.222.333-96`, CEP `01310-100`, telefone `(11) 98888-7777`, data `dd/mm/aaaa`), porque o texto vai para a nota e para o atendente ler.
- Resposta inválida usa o aviso do bloco (`invalido`) ou o do workflow, como hoje. Os modelos escrevem um aviso próprio por pergunta.
- Cada regra é uma classe pequena e testada sozinha (sugestão: regras de validação do Laravel em `app/Rules`, reaproveitáveis).

**Condição nova.** `condicao.kind` ganha `nome` ("o contato já tem nome") e `retorno` ("já houve ticket anterior deste contato"). Mesmas portas `true`/`false`.

**Nota para a equipe.** Novo tipo `nota` (porta `out`, texto com variáveis `{{...}}`, no máximo 1000 caracteres, obrigatório). Grava `TicketNote` com origem `bot` e sem autor. Entra em `NodeType`, validador (texto), motor, editor e paleta (categoria Ações).

## Fatia 4b: efeitos e simulador

**Interface de efeitos.** O motor e os blocos passam a falar com o mundo por ela: enviar texto, enviar mídia, mostrar "digitando" e esperar, salvar campo do contato, etiquetar, registrar nota, encerrar, entrar na fila (inclui o "Atribuir a") e persistir o estado da sessão. Também decide "agora está dentro do horário?", para o simulador poder forçar aberto ou fechado. A implementação real é o comportamento de hoje, sem mudança observável; a suíte do bot existente continua verde sem alteração de expectativa.

**Simulador.**
- Botão "Testar" no editor abre um painel de conversa sobre o **rascunho atual** (inclusive não publicado e inclusive com alterações não salvas, que já viajam no grafo), com os setores e o horário reais.
- Mostra: as mensagens que o bot enviaria; a trilha dos blocos percorridos com o bloco atual realçado no canvas; as variáveis e seus valores; os efeitos registrados em linhas próprias ("etiquetaria VIP", "gravaria no contato: nome", "registraria a nota: ...", "esperaria 3 s", "entraria na fila de Vendas, atribuído a Fulano", "encerraria"); e o desfecho.
- Controles: "Reiniciar", "Simular silêncio" (dispara o `timeout_min` do bloco que espera) e um seletor "Agora: horário real | dentro do expediente | fora do expediente".
- Antes de começar, o simulador roda o mesmo validador do "Validar"; com erro, mostra a lista (que já leva ao bloco) em vez de simular um fluxo que o motor não aceitaria.
- O nome do contato de teste é o de quem testa. A resposta do "cliente" passa pela mesma regra de número e `#sair` do motor real.
- O estado da conversa simulada vive no componente Livewire (propriedade `#[Locked]`), não no banco. Teto de passos igual ao do motor.
- Garantia, provada por teste: com a implementação de simulação, **zero** escrita em banco (nenhuma tabela de negócio muda), nenhum job, evento, broadcast, alerta, chamada ao WAHA nem arquivo copiado. O teste liga os observadores (consultas de escrita, filas e eventos falsos) e percorre um fluxo que usa todos os tipos de bloco.
- Se isolar algum bloco exigir mexer em mais lugares do que o previsto, o construtor ajusta o recorte, mas o contrato acima não muda.

## Fatia 4c: galeria por segmento

Cada modelo é um fluxo completo e curto, com no máximo 5 opções no primeiro menu, "falar com atendente" sempre disponível (o `#sair` e o rodapé já existem), aviso de horário no início (Condição de horário; fora do expediente avisa e entra na fila), perguntas validadas, Nota com o que foi coletado e Transferir ao setor certo. Dado pessoal só quando necessário e com a finalidade na pergunta.

**Setores.** O modelo traz o nome do setor desejado (Vendas, Financeiro, SAC, Comercial: os padrões da instalação). Ao criar o workflow a partir do modelo, o nome vira o id do setor existente (sem diferenciar maiúscula, só setor que recebe tickets). Nome que não existe: o Transferir fica sem setor fixo (cai na fila padrão) e a tela avisa quais setores faltaram, para o dono ajustar.

**Segmentos** (proposta; o construtor pode cortar um se o conteúdo não ficar honesto):
1. **Distribuidora** (o do dono): pedido e orçamento (CNPJ, itens e quantidades → Vendas), 2ª via de boleto (CNPJ e nº da nota → Financeiro), entrega (CNPJ e nº da nota → SAC), troca ou devolução (CNPJ, nota, motivo → SAC), falar com meu vendedor (CNPJ → Vendas, com nota).
2. **Clínica e consultório:** agendar ou remarcar (nome, convênio ou particular, preferência de dia → Vendas), resultado de exame (nome e data de nascimento → SAC), segunda via de cobrança (→ Financeiro), falar com a recepção.
3. **Restaurante e delivery:** fazer pedido (itens, endereço, forma de pagamento → Vendas), acompanhar pedido, reclamação (→ SAC), reserva.
4. **Loja de varejo:** disponibilidade de produto, troca, pós-venda, pagamento (→ Financeiro).
5. **Oficina e assistência técnica:** orçamento (modelo, problema), status do serviço, garantia, agendamento.
6. **Escola e curso:** matrícula (curso, turno), segunda via de boleto, secretaria, suporte ao aluno.
7. **Imobiliária:** quero comprar ou alugar (tipo, região, faixa de valor → Comercial), já sou cliente (contrato → SAC), boleto de aluguel (→ Financeiro).
8. **Salão e estética:** agendar (serviço, dia), cancelar ou remarcar, valores.

Cada modelo é registrado com chave, nome, descrição honesta do que faz e do que **não** faz, e aparece na galeria de "Novo workflow" agrupado (o "Atendimento padrão" e o "com horário" continuam).

**Prova.** Teste que, para cada modelo: o grafo passa no validador com setores resolvidos e com setores ausentes; e o simulador percorre o caminho principal de cada opção do menu com respostas roteirizadas até o desfecho esperado (setor, nota gravada e variável coletada).

## O que fica para o construtor

- Forma exata da interface de efeitos, do estado da sessão simulada e dos registros do simulador.
- Montador de grafo dos modelos (assinatura e nomes), e como o editor reconhece "sem posição" para organizar na abertura.
- Onde o painel do simulador mora no layout do editor sem cobrir o canvas nem o painel de propriedades em janela estreita.
- Textos exatos dos modelos, dentro das regras acima, e os avisos de resposta inválida por pergunta.
- Se as regras de validação ficam em `app/Rules` ou noutro lugar, desde que sejam testáveis sozinhas.

## Decisões minhas (custo se erradas)

- **Condição por etiqueta fica de fora desta fatia:** a etiqueta é do ticket (nasce a cada conversa), então a condição teria pouco uso. O dono quer etiqueta também no contato; isso é uma fatia própria (ver Não objetivos) e a condição entra com ela. Custo: nenhum agora.
- **Resposta validada guardada já formatada:** o atendente lê direto. Custo: quem quiser o número cru para outra coisa precisa limpar a máscara.
- **Origem da nota em coluna própria (`origin`), autor nulo para bot e IA:** a alternativa era um usuário "Bot" fictício, que polui listas de usuários e permissões, e um autor nulo sem origem não distingue Bot de IA. Custo: telas que supõem autor precisam tratar nulo e ler a origem; o construtor varre os usos (Transcript, política, fábrica de teste).
- **Seletor de horário no simulador:** não estava pedido, mas sem ele não dá para testar o ramo "fora do expediente" fora do expediente. Custo: um valor a mais passado ao motor de teste.
- **Segmentos e setores padrão:** a lista de oito é minha proposta, e os setores são os cinco padrões da instalação. Custo: segmento que o dono não atende vira peso morto na galeria; ele pode pedir para tirar.
- **Três fatias, um planejamento cada:** 4a blocos, 4b efeitos e simulador, 4c galeria, nessa ordem, para o simulador já cobrir os blocos novos e os modelos serem testados por ele.
- **Simulador recusa fluxo inválido:** mostra os erros do validador em vez de simular. Custo: ninguém testa um rascunho incompleto; o dono corrige primeiro.

## Pesquisa que embasa (resumo; links no chat)

Emuladores de fluxo mostram chat, trilha de etapas, variáveis e reinício, e não persistem a conversa. O CNPJ alfanumérico existe desde julho de 2026 com o mesmo módulo 11. As recomendações de desenho de fluxo para WhatsApp (menu curto, humano sempre à mão, entregar o coletado ao atendente) vêm de material de fornecedores e servem como direção, não como prova. Dado pessoal: CNPJ isolado não é dado pessoal, CPF é; pedir só o necessário e informar a finalidade.
