# Telas de administração: usuários e setores — design curto

Data: 2026-10-29. Status: aguardando aprovação do dono. Terceira spec curta da "leva de anotações" do inbox.

## Por que

Usuário só nasce pelo comando `app:create-user`, e papel, ativo, `can_transfer`, setores de cada pessoa e o estado e a ordem dos
setores só mudam no banco ou na linha de comando. Só quem tem acesso ao servidor consegue administrar a equipe.

## Objetivos

1. Tela **Usuários** para admin e supervisor: listar, criar, editar, ativar/desativar e definir a senha de atendentes.
2. Tela **Setores** para admin: estado e ordem no menu dos cinco setores.
3. Navegação: grupo "Configurações" no menu lateral com Canais, Horário, Google Contatos (quando existir), Usuários e Setores,
   cada item visível só para quem pode usar.

## Não-objetivos (depois da v1, anotado pelo dono)

Criar, renomear e excluir setor (os cinco do seed são fixos na v1); redefinição de senha por e-mail; 2FA obrigatório; convite
por e-mail; histórico de alterações de usuário; configurações do WAHA e da empresa pela tela (seguem no ambiente).

## Decisões do dono

- **Admin e supervisor administram usuários.**
- **Admin** cria, edita e desativa qualquer usuário, inclusive outros admins e supervisores.
- **Supervisor** cria e gerencia só **atendentes** (nome, setores, "pode transferir", ativar/desativar, nova senha). Não toca em
  admin nem em outro supervisor e não promove ninguém.
- **Ninguém** desativa a si mesmo nem remove o último admin ativo.
- **Setores:** só admin; edita estado (ativo, fora do menu, desativado) e ordem; desativar setor com ticket aberto é bloqueado.

## Decisões do builder (contestáveis)

- **Senha:** quem cria define a senha inicial (mínimo 8 em desenvolvimento e a regra forte já configurada em produção, via
  `Password::defaults()`); a pessoa troca depois pela tela de perfil que já existe. "Nova senha" na tela é o mesmo campo.
- **Edição de e-mail** permitida (único). Desativar encerra as sessões da pessoa (apaga as sessões do banco) e o login já recusa
  usuário inativo.
- **Desativar quem tem ticket** não é bloqueado: os tickets continuam atribuídos e o supervisor reatribui (a tela mostra o
  número de tickets abertos da pessoa no aviso).
- **Setores da pessoa:** múltipla escolha entre os setores que recebem tickets (um atendente pode atender mais de um).
- **Autorização:** uma Policy `UserPolicy` (`viewAny`, `create`, `update` por papel do alvo) e o Gate `admin` existente para
  setores. A regra "supervisor só mexe em atendente" mora na Policy, não na tela; o servidor revalida em cada ação.
- **Componentes:** Livewire + Flux, como as telas de Canais; sem rotas novas além de `configuracoes/usuarios` e
  `configuracoes/setores`. Os itens `Contatos` e `Atendimento` seguem no grupo principal.
- O comando `app:create-user` continua existindo (primeiro admin).

## Comportamentos que não podem quebrar

- Supervisor nunca consegue criar, editar ou desativar admin ou supervisor, nem pela tela, nem forjando a requisição Livewire.
- O último admin ativo não pode ser desativado nem rebaixado.
- Atendente (papel mais baixo) recebe 403 em ambas as rotas e não vê os itens do menu.
- Mudar a ordem ou o estado do setor não altera tickets existentes.

## Testes

Policy: matriz de papel do ator por papel do alvo (criar, editar, desativar); último admin protegido; auto-desativação
recusada. Livewire: criação válida/inválida (e-mail duplicado, senha curta), setores gravados na tabela `sector_user`, `can_transfer`
alterado, desativar remove as sessões; supervisor tentando forjar o papel `admin` leva 403. Setores: troca de estado, ordem,
bloqueio de desativar com ticket aberto, não admin leva 403.
