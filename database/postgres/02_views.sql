-- ==========================================================================
-- Views do fluxo de caixa
--   vw_fluxo_base  = entradas (vendas), saídas (compras) e lançamentos manuais
--   vw_fluxo_caixa = a mesma lista + "situacao" (pendente vencida = ATRASADO)
-- ==========================================================================
CREATE OR REPLACE VIEW vw_fluxo_base AS
SELECT 'ENTRADA'::varchar AS tipo, 'VENDA'::varchar AS origem, cr.id AS id, v.id AS referencia,
       cr.data_vencimento AS vencimento, cr.data_pagamento AS pagamento, cr.total AS total,
       cr.status::varchar AS status, COALESCE(cl.nome, 'Consumidor final')::varchar AS contraparte, ''::varchar AS categoria
FROM conta_receber cr
JOIN venda v ON v.id = cr.venda_id
LEFT JOIN cliente cl ON cl.id = v.cliente_id
UNION ALL
SELECT 'SAIDA', 'COMPRA', cp.id, c.id, cp.data_vencimento, cp.data_pagamento, cp.total,
       cp.status::varchar, f.nome::varchar, ''
FROM conta_pagar cp
JOIN compra c ON c.id = cp.compra_id
JOIN fornecedor f ON f.id = c.fornecedor_id
UNION ALL
SELECT m.tipo::varchar, 'MANUAL', m.id, m.id, m.data_movimentacao, m.data_pagamento, m.valor,
       m.status::varchar, m.descricao::varchar, m.categoria::varchar
FROM movimentacao_caixa m;

CREATE OR REPLACE VIEW vw_fluxo_caixa AS
SELECT b.tipo, b.origem, b.id, b.referencia, b.vencimento, b.pagamento, b.total, b.status,
       b.contraparte, b.categoria,
       (CASE WHEN b.status = 'PENDENTE' AND b.vencimento < CURRENT_DATE THEN 'ATRASADO' ELSE b.status END)::varchar AS situacao
FROM vw_fluxo_base b;
