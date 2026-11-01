# Traduções pt_BR pelo pacote oficial laravel-lang — design curto

Data: 2026-11-01 · Branch: `feat/laravel-lang` · Tamanho: mudança pequena (dependência de dev + arquivos de idioma)

## Intenção e motivo

O `lang/pt_BR/validation.php` escrito à mão (PR #60) cobre só as regras em uso: 80 linhas contra 111 chaves do framework.
Regra nova fora da lista volta a aparecer em inglês sem aviso, e a manutenção a cada versão do Laravel seria nossa.
O dono escolheu a opção A: usar o pacote oficial de traduções.

## Abordagem

| Opção | Resultado |
|---|---|
| **A. `laravel-lang/lang` + `laravel-lang/publisher` (dev)** | **Escolhida pelo dono.** MIT; lang tem 7.783 estrelas, release 15.37.5 de 31/10/2026; publisher declara Laravel 10 a 13 |
| B. Completar o arquivo à mão | Descartada: manutenção nossa |
| C. Manter como está | Descartada: regra nova cai no inglês |

## Objetivos

1. Os arquivos de `lang/pt_BR` (e `lang/pt_BR.json`) passam a vir do pacote, commitados no repositório.
2. Toda chave de validação do framework tem tradução em pt_BR.
3. Nada do que já está traduzido regride: as chaves próprias do app em `pt_BR.json` e os nomes de campo (`attributes`) ficam.

## Não objetivos

- Outros idiomas. Sem `lang:add` para locales além de `pt_BR`.
- Mudar o idioma padrão ou o fallback (`pt_BR` e `en`, como estão).
- Dependência em produção: o pacote entra em `require-dev`; os arquivos de idioma publicados vão no git.

## Constraints

- Imagem de produção instala com `--no-dev`: por isso os arquivos têm de estar commitados, não gerados no build.
- Os textos próprios do app (`pt_BR.json`, 97 chaves) e os `attributes` do `validation.php` não podem ser sobrescritos pelo publicador.

## Decisões de como

- `composer require --dev laravel-lang/lang laravel-lang/publisher`; publicar só `pt_BR` com `php artisan lang:add pt_BR`.
- Se o publicador sobrescrever chaves próprias, restaurar do git e mesclar à mão; o teste de cobertura das chaves (`TranslationTest`) é a rede de segurança.
- Teste novo: toda chave do `validation.php` do framework existe no `lang/pt_BR/validation.php`.
- O teste que fixa o texto exato de `auth.failed` passa a comparar com `trans('auth.failed')`, porque o texto passa a ser o do pacote.

## Fica para quem constrói

Ordem dos comandos, resolução de conflito de chaves, ajuste fino de `attributes`.

## Aprovação

O dono aprovou a abordagem A no chat ("pode fazer a A, vá até o merge") depois de ver a tabela de opções; este documento registra o que foi combinado.
