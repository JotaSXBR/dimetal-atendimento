# Busca de texto nas mensagens: medição (30/10/2026)

Pergunta: `ILIKE '%termo%'` basta para o histórico, ou o índice `pg_trgm` se paga? O dono prevê volume alto (2 números
de WhatsApp na produção).

**Método.** PostgreSQL 18.6 (mesma versão do beta) em Docker local, 2,1 milhões de mensagens sintéticas em português
(3 a 14 palavras, vocabulário de 222 termos), 200 mil tickets. Tudo cabia em memória. Medida única, sem concorrência.

| Medida | Sem índice | Com índice `pg_trgm` (GIN) |
|---|---|---|
| Termo raro (nome, 441 linhas) | 0,9 s (varredura da tabela inteira) | 39 ms |
| Termo inexistente (protocolo) | 0,69 s | 0,6 ms |
| Termo comum (4% das linhas) | 0,6 s | 0,17 a 0,26 s (o índice quase não ajuda) |
| Termo de 2 caracteres | 0,6 s | 0,6 s (o índice não é usado) |
| Consulta real: tickets dos últimos 30 dias com `exists` no texto | 0,98 s (o plano varreu todas as mensagens apesar do filtro de período) | 27 ms |
| Gravação em lote de 100 mil mensagens | 0,42 s | 2,3 s |
| Gravação mensagem a mensagem (como o webhook) | n/d | cerca de 0,5 ms por mensagem |
| Espaço | tabela 215 MB | índice 155 a 178 MB (cerca de 0,7 vezes a tabela) |

**Texto completo `portuguese` (tsvector):** índice de 31 MB e 1,2 ms para duas palavras raras. Não foi adotado: casa por radical
de palavra, não por trecho (a busca por "cotaç" exige sintaxe de prefixo) e rende pouco com nomes e números.

**Conclusões.** O filtro de período não limita a varredura sem índice. Com o índice a busca seletiva cai de cerca de 1 s para
dezenas de ms, e a varredura cresce linearmente com a tabela. Custo: espaço e gravação, ambos aceitáveis para chat. Exige termo
de 3 caracteres ou mais e não ajuda termo comum.

**Limites da medição.** Dados sintéticos (textos reais têm mais trigramas distintos, então o índice tende a ficar maior);
sem concorrência com o inbox; disco rápido e tudo em cache (numa VPS com pouca memória a varredura piora); gravação em
rajada e a lista pendente do GIN (`fastupdate`) não foram exercitadas.
