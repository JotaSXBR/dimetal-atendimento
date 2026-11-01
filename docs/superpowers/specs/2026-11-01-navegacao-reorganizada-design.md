# Navegação reorganizada e app em português — design

Data: 2026-11-01 · Branch: `feat/navegacao-reorganizada` · Tamanho: projeto médio (≈25 views, sidebar, testes, idioma)

## Intenção e motivo

O dono relatou três problemas: **textos em inglês**, **configuração confusa** e **configuração espalhada**. A causa
no código: o menu existe em vários lugares sem fonte única, e as páginas de configuração usam três cabeçalhos
diferentes. Canais e Horário abrem dentro do layout das configurações *pessoais*.

Fatos levantados no código (não são hipóteses):

- `lang/pt_BR` só tem `pagination.php`. Cerca de 109 chaves `__()` aparecem em inglês no app (menu da conta,
  Segurança, Perfil, login, 2FA, recuperar senha etc.).
- Relatórios e Auditoria não têm link para o ticket. Contatos não tem ação "abrir conversa" (fora de escopo, é funcionalidade nova).
- Não existe rota `register`, mas a view `pages/auth/register` existe. `layouts/app/header.blade.php` é um segundo layout fora de uso.
- Para o supervisor, os grupos "Operação" e "Pessoas e empresa" têm um item cada.

## Objetivos

1. Todo texto visível em português do Brasil, inclusive login, 2FA e recuperação de senha.
2. Uma estrutura de navegação só, com os mesmos nomes na sidebar, no cabeçalho das páginas e no menu da conta.
3. Toda página de configuração com o mesmo cabeçalho e uma linha de "irmãos" do grupo.
4. Links no contexto onde hoje há texto morto (Relatórios e Auditoria abrem o ticket).
5. Remover código morto de navegação.

## Não objetivos

- Nenhuma capacidade nova nem removida; nenhuma regra de negócio ou permissão muda.
- URLs e nomes de rota não mudam (`/configuracoes/*`, `/settings/*`). O callback do Google segue idêntico.
- Sem "abrir conversa" em Contatos. Sem hub com abas (Alternativa B). Sem redesenho visual (cores, estilo).

## Anti-objetivo

Qualquer item aparecer para um papel que hoje não o vê, ou sumir de quem hoje o vê. O portão das rotas
(`auth`, `can:supervisor`, `can:admin`) continua sendo a única barreira real.

## Abordagem escolhida

Alternativa A do estudo de arquitetura de informação, aplicada ao app todo. Para implementar:

| Opção | Resumo | Resultado |
|---|---|---|
| **1. Lista central de navegação** | Uma classe guarda rótulo, rota, portão e grupo; sidebar e irmãos leem dela | **Escolhida** (corrige a causa) |
| 2. Componente de layout com irmãos à mão | Menos código, duas listas para manter | Descartada |
| 3. Só renomear a sidebar | Mais barata | Descartada: não une o cabeçalho |

Tradução: `lang/pt_BR.json` para o que vem do starter kit e do Fortify; textos do próprio app ficam nas views.

## Estrutura final (decidida pelo dono)

```
ATENDIMENTO:     Fila · Contatos · Histórico
ACOMPANHAMENTO:  Relatórios · Auditoria (admin)
CONFIGURAÇÃO
  Atendimento:   Setores (admin) · Horário e SLA (admin) · Respostas rápidas (supervisor+)
  Equipe:        Usuários (supervisor+)
  Conexões:      Canais (admin) · Google Contatos (admin)
  Empresa:       Empresa e assinatura (admin) · Anexos e retenção (admin)
Avatar → Minha conta (Perfil · Segurança · Aparência · Meus alertas) · Sair
```

Renomeações: Atendimento (item) → Fila; Gestão → Acompanhamento; Horário → Horário e SLA; Empresa → Empresa e
assinatura; Retenção → Anexos e retenção; Alertas → Meus alertas; Settings → Minha conta.
O grupo da sidebar continua se chamando "Atendimento" (o teste `test_o_menu_se_chama_atendimento` segue valendo).

## Decisões de como

- **`App\Support\Navigation`** (mesmo diretório de `SidebarState`): lista de itens com `label`, `route`, `gate`
  (`null`, `supervisor` ou `admin`), `section` (Atendimento / Acompanhamento / Configuração) e `group`.
  Método que devolve os itens visíveis ao usuário via `Gate::allows`. Sidebar e irmãos consomem esta lista.
- **Cabeçalho comum de configuração**: um componente Blade (por ex. `<x-settings-page :route="...">`) que mostra
  o caminho "Configuração › Grupo", a linha de irmãos visíveis ao papel e o `page-header` da página. Canais e
  Horário deixam de usar `pages::settings.layout`.
- **Minha conta**: as 4 páginas pessoais mantêm `pages::settings.layout`, com rótulos em português e título
  "Minha conta". O item do avatar passa a se chamar "Minha conta". Mesma coisa no menu do avatar do mobile.
- **SLA**: o campo `slaMinutes` passa do formulário de Empresa para o de Horário e SLA. O armazenamento fica em
  `CompanySettings` (nenhuma migração). Empresa mantém nome fantasia e assinatura.
- **Links no contexto**: o ticket em SLA estourado (Relatórios) e o protocolo (Auditoria) levam a
  `route('historico', ['ticketId' => …])` quando o ticket está encerrado e à Fila quando está aberto. A
  permissão de ver o ticket continua sendo a da rota de destino.
- **Código morto**: apagar `pages/auth/register.blade.php` e `layouts/app/header.blade.php`, depois de confirmar
  que nada os referencia (rota, teste, view).

## Testes e verificação

- `SidebarTest` atualizado para os novos nomes. Novo teste de `Navigation` por papel: atendente, supervisor e
  admin veem exatamente o conjunto de hoje (só com os nomes novos), nenhum a mais.
- Teste que percorre toda rota nomeada de configuração e confere que a página tem o cabeçalho comum.
- `CompanyPageTest` e `BusinessHoursPageTest`: o SLA e as validações do campo seguem as mesmas, agora na página de Horário e SLA.
- Teste de idioma: nenhuma das chaves `__()` usadas no app fica sem tradução em `pt_BR` (varre o código).
- Antes do PR: suíte inteira, Pint, Larastan e PHPMD, lidos na sessão.

## Fica para quem constrói

- Ordem das tarefas e divisão em commits.
- Exatamente quais chaves entram no JSON (é o resultado da varredura de `__()`).
- Como o componente de cabeçalho lê o grupo atual (pela rota corrente é suficiente).
- Se o selo "Aguardando/SLA estourado" da sidebar continua onde está (sim, não muda).

## Dúvidas assumidas (calls minhas, a revisar)

- "Horário e SLA" para o nome da página que recebe o prazo. Custo se errado: trocar um rótulo na lista central.
- Link de Relatórios/Auditoria para ticket aberto vai para a Fila, sem tentar selecionar o ticket. Custo se
  errado: o usuário clica de novo na lista.
- Nenhuma validação com usuários reais foi feita; as tarefas de teste de achabilidade do estudo (T1–T14) ficam
  como roteiro para depois do PR.

## Respostas à revisão do construtor

Respondidas pelo código (nenhuma exigiu decisão do dono):

- **Alertas e as outras 3 páginas pessoais** são visíveis a todos os papéis (`auth`, e `verified` nas três últimas).
  "Minha conta" cobre as mesmas 4 páginas para todos. O teste de "ninguém vê mais nem menos" usa como base a
  sidebar atual, não este documento.
- **Cadastro (`register`)**: o Fortify só liga 2FA e passkeys (`config/fortify.php`) e `route:list` não tem rota
  de cadastro. A afirmação do documento está correta e a view pode sair.
- **Idioma**: `APP_LOCALE=pt_BR` já é o padrão; só falta o arquivo de traduções. Não há troca de locale.
- **`CompanySettings::save()`** grava as 5 chaves de uma vez. Como o SLA muda de página, `save()` passa a gravar
  só as chaves recebidas (ou ganha um método próprio para o SLA), para que Empresa e Horário e SLA nunca
  sobrescrevam o valor um do outro. `CompanyPageTest` e `SignatureTest` acompanham.
- **Link para ticket**: Relatórios e Auditoria só são acessíveis a supervisor e admin, que veem todos os setores
  (`scopeReadableBy`), então não há link sem permissão. O filtro `from` do Histórico afeta só a lista; o painel
  usa `ticketId`.

Calls minhas (custo se errado entre parênteses):

- A linha de irmãos mostra só os itens visíveis ao papel **dentro do grupo atual** e **some quando sobra um só**,
  então o supervisor não vê uma linha de um item (custo: ajustar a regra no componente).
- Tradução cobre as chaves `__()` do repositório e renderiza as páginas de login/2FA/recuperação em `pt_BR`
  para confirmar que não sobra inglês (custo: um teste a mais).
