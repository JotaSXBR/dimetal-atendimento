# Automação, leva B (design)

Projeto de quatro fatias, na ordem abaixo, cada uma verificada e entregue antes da seguinte. Parte do pedido do dono em 11/11: o menu do bot não reconhece a escolha quando o cliente escreve com assinatura, as mensagens do bot não são editáveis, o editor parece protótipo e falta o que n8n/Make dão (organizar, minimapa, simular).

## Intenção

Fazer da Automação uma parte confiável e com cara de produto: o bot entende o cliente de verdade, o texto que ele fala é do dono, o editor é bom de olhar e de usar, e dá para testar um fluxo sem mandar nada ao WhatsApp.

## Objetivos

1. **Fatia 1, bug do número com assinatura e assinatura sem linha em branco.**
2. **Fatia 2, mensagens do bot editáveis por workflow e galeria de modelos prontos** (inclui o modelo com horário, vindo do fluxo que o dono montou no beta).
3. **Fatia 3, editor visual:** blocos com cara de produto, organizar, minimapa e zoom, menu de contexto e atalhos, erros de validação no próprio bloco.
4. **Fatia 4, fluxo mais rico:** simulador de conversa no editor, bloco de horário de atendimento, atribuir a atendente no bloco Transferir.

## Não objetivos

- Tirar a assinatura do cliente da resposta do bloco **Pergunta** (o nome guardado numa variável pode vir com a assinatura junto). Só o menu e o `#sair` passam a entender a assinatura.
- Blocos de integração (HTTP, webhook), pesquisa de satisfação, horário por canal.
- Tema claro e QA de acessibilidade completos (leva C); o editor novo nasce no tema escuro/amarelo atual.
- Editar o fluxo pelo celular: o editor segue sendo de tela larga.
- Trocar o Rete.js por outra biblioteca.

## Restrições

- Stack atual: Laravel 13, Livewire 4.4, Flux 2, Rete.js 2 com `@retejs/lit-plugin`. Dependência nova só se o dono aceitar o custo (ver fatia 3: organizar usa `elkjs`, carregado só na página do editor).
- O grafo publicado é versionado (`workflow_versions.graph`); o que o motor lê tem de continuar válido para versões já publicadas. Chave nova no grafo é sempre opcional, com o comportamento de hoje como padrão.
- O motor é o mesmo em produção e no simulador: nada de segunda implementação das regras dos blocos.
- Texto novo para o cliente nasce igual ao de hoje (nenhum workflow muda de comportamento sem o dono editar).
- Sem referência a bibliotecas ou projetos de terceiros em código e commit; pesquisa só em chat e ledger.

## Abordagens

- **A, Rete.js com blocos nossos e simulador que roda o motor real (escolhida pelo dono).** Componentes Lit próprios para bloco, bolinha e linha; minimapa, organizar e menu de contexto pelos plugins do Rete. O simulador executa os mesmos blocos do motor com uma costura que troca os efeitos reais (enviar mensagem, entrar na fila, etiquetar, encerrar) por registro.
- **B, Rete.js com simulador em JavaScript.** Descartada: regras do menu, da pergunta e da condição passariam a existir em PHP e em JS e divergiriam.
- **C, trocar para React Flow.** Descartada: React só para uma tela e reescrita do editor e do painel; o ganho visual o Rete entrega com blocos customizados.

Ajuste em relação ao que foi conversado: rodar o motor "numa transação desfeita" não serve, porque broadcast, alertas, jobs e notificação de ticket saem da transação. O simulador usa a costura no motor (fatia 4), não rollback.

## Fatia 1: número com assinatura e assinatura sem linha em branco

**Bug.** `ChoiceNode::answer` só aceita a mensagem se ela for inteira um número (`^\d{1,3}$`), e `BotEngine::receive` só reconhece `#sair` se a mensagem for exatamente isso. O cliente cujo sistema acrescenta uma assinatura ("*#Jessé:*⏎1") cai em "opção inválida" e, na terceira, vai à fila sem ter escolhido o setor.

Regra nova (uma classe pequena do bot, usada pelos dois pontos):
- Mensagem sem linhas vazias significativas: dividir em linhas, aparar cada uma.
- Número: se o texto inteiro, aparado, é só um número de 1 a 3 dígitos, vale. Senão, as linhas que são **só** um número de 1 a 3 dígitos: se todas forem o mesmo valor (uma ou repetidas), vale esse; se não houver nenhuma ou houver valores diferentes, é resposta inválida.
- `#sair`: vale se o texto inteiro, ou **qualquer linha**, é `#sair` (sem diferenciar maiúscula). `#sair` vence o número.
- Telefone, CEP, "Rua 10, 200" e frases não viram opção, porque a linha não é só um número curto.
- O que o cliente escolheu é o número; o texto da assinatura é ignorado e não é guardado em variável.

**Assinatura do atendente.** `Signature::place` passa a separar assinatura e texto com **uma quebra de linha** (`\n`), nas duas posições. Mensagens já enviadas não mudam. A prévia da tela Empresa e assinatura usa a mesma função e muda junto.

## Fatia 2: mensagens do bot editáveis por workflow

Textos hoje fixos no código, passam a ter campo no workflow, com o valor de hoje como padrão:

| Texto | Onde está hoje | Variáveis |
|---|---|---|
| Aviso ao entregar à fila com setor (`#sair`, erros, silêncio, bloco sem saída) | `BotEngine::handoff` | `{{setor}}`, `{{protocolo}}`, `{{nome}}` |
| Aviso ao entregar à fila geral (nenhum setor recebe) | `BotEngine::handoff` | `{{protocolo}}`, `{{nome}}` |
| Opção inválida do menu | `ChoiceNode` | `{{nome}}` |
| Resposta inválida da pergunta | `PerguntaNode` | `{{nome}}` |
| Rodapé "Para falar com um atendente digite #sair" | `ChoiceNode::render` | nenhuma |

- Guardados no próprio grafo (chave `settings`), portanto publicados, versionados e restauráveis com o resto. Chave ausente = texto de hoje; rodapé vazio (`''`) = sem rodapé (o `#sair` continua valendo).
- O campo `invalido` de cada bloco continua mandando sobre o texto do workflow.
- Edição: painel lateral do editor quando nenhum bloco está selecionado ("Mensagens do bot"), com a lista das variáveis e o valor padrão visível.
- O motor lê as mensagens do grafo da versão da sessão; versões antigas, sem `settings`, comportam-se como hoje.
- Validação ao publicar: tamanho máximo por texto (mesmo teto dos textos dos blocos) e variáveis desconhecidas como aviso, não bloqueio (`Vars` já deixa a chave desconhecida como está).

**Variável nova `{{empresa}}`**, preenchida com `CompanySettings::name()`, disponível em todas as mensagens do bot (blocos e textos do workflow) e listada nos painéis ao lado das outras.

**Galeria de modelos.** "Novo workflow" passa a abrir a escolha de um modelo em vez de criar direto:
- **Em branco:** só o Início (`DefaultWorkflow::blank`).
- **Atendimento padrão:** o modelo de hoje (`DefaultWorkflow::graph`), sem mudança.
- **Atendimento com horário:** generalização do fluxo que o dono montou no beta. Início → Condição "Dentro do horário de funcionamento". Dentro do horário: Mensagem "Olá, {{nome}}! Bem-vindo a {{empresa}}." → Menu de setores ("Com qual setor você quer falar? Responda com o número da opção:", 5 min sem resposta) → Mensagem "Certo! Seu protocolo é {{protocolo}}. Você entrou na fila de {{setor}} e um atendente responde em instantes." → Transferir (setor escolhido no menu). Fora do horário: Mensagem "Olá, {{nome}}! Nosso horário de atendimento é {{horario}}. Retornamos {{proximo_horario}} e você já está na fila para ser atendido." → Transferir **sem setor fixo** (fila padrão: o primeiro setor do menu). No fluxo do dono o destino fora do horário era o setor "Vendas"; no modelo vira fila padrão e quem aplica troca o setor no bloco se quiser.
- Cada modelo na galeria mostra nome, uma frase do que faz e, depois da fatia 3, uma miniatura do fluxo. O modelo aplicado cria um workflow novo com rascunho, sem publicar e sem ativar em canal.
- Os modelos ficam em código (como o `DefaultWorkflow` hoje), com teste de que cada um publica sem erro do `FlowValidator`. Na fatia 4, o modelo com horário troca a Condição pelo bloco `horario`.
- O fluxo do beta do dono não é alterado.

## Fatia 3: editor visual

Mantém Rete.js; a base é a troca dos componentes padrão por nossos (`customize.node/socket/connection` do preset Lit).

- **Bloco:** cabeçalho com ícone e cor por categoria (começo, fala, espera, lógica, ação, fim), título do tipo, corpo com prévia do conteúdo (primeiras linhas da mensagem, número de opções do menu, nome do setor ou da etiqueta, regra da condição), rótulos das portas legíveis, estado selecionado e estado de erro.
- **Bolinhas e linhas:** bolinhas na cor da categoria, linhas curvas com curvatura suave e sem laço, seta de direção, linha realçada ao passar o mouse; fundo pontilhado que acompanha o zoom.
- **Navegação:** minimapa, botões de zoom e "centralizar", botão **Organizar blocos** (layout automático da esquerda para a direita).
- **Uso:** menu de contexto (clique direito no fundo: adicionar bloco; num bloco: duplicar, apagar), `Delete`, `Ctrl+Z`/`Ctrl+Y` (desfazer/refazer), `Ctrl+D` duplicar, e a paleta lateral agrupada por categoria com o mesmo vocabulário dos blocos.
- **Erros no bloco:** `FlowValidator` passa a devolver erros estruturados (`bloco` e `mensagem`); ao validar ou ao falhar a publicação, o bloco com problema fica marcado e a lista no painel leva ao bloco ao clicar (já existe `focus`).
- **Lista de Automação:** o cartão ganha mini-resumo (nº de blocos, setores alcançados, canais) e ações mais claras; o visual segue o dos demais cartões de Configuração.
- **Dependências a avaliar pelo construtor:** `elkjs` + plugin de organizar (carregados com import dinâmico só no editor; medir o peso e informar), plugin de histórico, plugin de menu de contexto ou menu próprio se o do Rete não tiver preset Lit. Cada uma é MIT; qualquer uma que não fechar com Rete 2 + Lit vira componente próprio.
- Serialização (`serialize.js`) não muda de formato: posição e dados continuam como estão; o que for de apresentação (cor, ícone) vem do tipo, não do grafo.

## Fatia 4: fluxo mais rico

**Simulador.** Botão "Testar" no editor abre um painel de conversa. O dono "escreve como cliente"; o painel mostra as mensagens que o bot enviaria, o bloco atual realçado no canvas e, no fim, o desfecho (entrou na fila de tal setor, encerrado, fila geral).
- Roda o **motor real** (mesmos blocos, mesma regra de número com assinatura) com uma costura nos efeitos: enviar mensagem, entregar à fila, encerrar, etiquetar, salvar no contato. A implementação de produção é o código de hoje; a do simulador só registra. Não grava ticket, mensagem, sessão nem evento, não dispara job, broadcast nem alerta.
- O estado da conversa simulada vive na sessão do Livewire/navegador, não no banco.
- Roda o rascunho atual do editor (inclusive não publicado) e usa os setores e o horário reais para o menu e o bloco de horário; o nome do contato de teste é o de quem está testando.
- Teto de passos como o do motor (`MAX_STEPS`) e as mesmas esperas (o "tempo" do bloco Esperar não é aguardado: aparece como "esperaria N s").
- Se a costura exigir tocar muito mais blocos do que o previsto, o construtor ajusta o recorte (o contrato é: nada vai ao WhatsApp nem ao banco), mas não duplica regra em JavaScript.

**Bloco Horário de atendimento (`horario`).** Duas portas, `aberto` e `fechado`, decididas por `BusinessHours::isOpen(now())` (horário e feriados já cadastrados). Entra em `NodeType`, `FlowValidator` (ambas as portas ligadas), no motor, no editor e na paleta. Sem horário cadastrado, `isOpen` é verdadeiro e o fluxo segue por `aberto`, como em todo o sistema.

**Atribuir a atendente.** O bloco Transferir ganha o campo opcional "Atribuir a": uma pessoa. O ticket entra na fila do setor e, em seguida, é assumido por ela pelo mesmo caminho de `ClaimTicket`. Se a pessoa estiver inativa, não atender o setor ou o ticket já tiver outro dono, fica só na fila do setor (comportamento de hoje). A validação ao publicar recusa pessoa inexistente ou inativa.

## O que fica para o construtor

- Nome e forma exata da classe da regra do número (fatia 1) e do tipo de erro estruturado (fatia 3).
- Cores, ícones e espaçamentos dos blocos dentro dos tokens do tema atual.
- Se o menu de contexto e o desfazer vêm de plugin ou de componente próprio.
- Como a costura de efeitos se encaixa (interface única ou parâmetro), desde que respeite o contrato da fatia 4.

## Decisões minhas (custo se erradas)

- **`#sair` em qualquer linha:** mais tolerante que hoje; se o cliente escrever "preciso sair do plano\n#sair", sai. Custo: nenhum relevante (a linha tem de ser exatamente `#sair`).
- **Valores diferentes entre as linhas é resposta inválida:** "1\n2" não escolhe nada. Custo: o cliente precisa repetir; a alternativa (pegar o primeiro) escolheria o setor errado.
- **Mensagens guardadas no grafo e não em tabela própria:** acompanham versão e restauração. Custo: trocar um texto exige publicar uma versão nova.
- **Rodapé vazio some o rodapé:** o dono pode ocultar a dica de `#sair`. Custo: cliente sem dica de como falar com atendente; o `#sair` continua funcionando.
- **Atribuir via `ClaimTicket`:** reaproveita a disputa por ticket. Custo: se o setor não receber mais, vai só para a fila.
- **Fila padrão fora do horário no modelo com horário:** diferente do que o dono tem no beta (Vendas fixo). Custo: o texto promete "você já está na fila" e ele cai no primeiro setor do menu, que pode não ser o ideal; quem aplica ajusta o bloco.
- **Duas dependências possíveis (organizar e histórico) na fatia 3, a confirmar no começo dela:** se o peso do `elkjs` incomodar, "Organizar" sai e o resto fica.
