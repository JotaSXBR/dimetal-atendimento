# Etiquetas padrão de uma instalação nova: design

**Aprovado pelo dono em 13/11** ("pode seguir o processo todo ate o pr"), depois da rodada de perguntas (enxuto, genérico, instalação nova e beta vazio, nomes).

## Intenção
O sistema nasce vazio e o admin cria as etiquetas do zero. Um conjunto genérico e enxuto já pronto dá o que usar no primeiro dia, e o card da Fila não se polui (nomes curtos).

## Objetivos / não objetivos
- Criar 15 etiquetas uma única vez em instalação nova (e no beta, que está sem etiquetas), no mesmo mecanismo dos setores padrão.
- Nunca recriar o que o admin apagou; instalação que já tem etiqueta só recebe a marca.
- Não é tela nova, não é por segmento, não traz etiqueta de estado (verde/âmbar/vermelho continuam reservados).

## Conjunto (nomes únicos entre escopos; cores da paleta fixa)
- **Contato (7):** Novo (céu), Ativo (turquesa), PJ (azul), PF (lilás), VIP (fúcsia), Em atraso (rosa), Fornecedor (pedra).
- **Conversa (8):** Orçamento (céu), Pedido (azul), Boleto (violeta), Entrega (turquesa), Troca (ciano), Reclamação (fúcsia), Urgente (ardósia), Dúvida (anil).

## Abordagens
1. **Espelhar `DefaultSectors`** (marca `tags.defaults_loaded` em `settings`, trava advisory, agendador + seeder). Escolhida: padrão já conhecido e testado, zero conceito novo.
2. Migração com `insert`: recriaria em banco restaurado e não distingue "apagou" de "nunca teve". Descartada.
3. Botão "carregar modelos" na tela: pedido não feito; YAGNI.

## Decisões
- Só cria se `Tag::query()->exists()` for falso; a marca é gravada sempre.
- O agendador roda a cada minuto enquanto a marca não existe (o `migrate` roda no start do app e não há gancho de deploy); o beta recebe no próximo deploy.
- `TagSeeder` no `DatabaseSeeder` usa o mesmo caminho.
- Custo se errado: etiqueta indesejada que o admin apaga uma vez e não volta.
