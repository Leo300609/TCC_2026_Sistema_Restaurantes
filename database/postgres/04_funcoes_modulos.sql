-- MODULOS
-- (convertido do MariaDB; funções fn_* retornam valores e sp_* retornam jsonb, uma linha por registro)

-- Custo estimado de uma unidade do item: soma dos insumos da ficha técnica
-- ou, para itens de revenda, o menor preço de fornecedor. NULL se desconhecido.
DROP FUNCTION IF EXISTS fn_produto_custo;
CREATE OR REPLACE FUNCTION fn_produto_custo(p_produto_id integer)
RETURNS numeric(12, 2)
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_custo numeric(12, 2) DEFAULT NULL;
BEGIN


    IF EXISTS (SELECT 1 FROM FICHA_TECNICA WHERE PRODUTO_ID = p_produto_id) THEN
        SELECT SUM(f.QUANTIDADE * i.PRECO_UNITARIO) INTO v_custo
        FROM FICHA_TECNICA f
        JOIN PRODUTO i ON i.ID = f.INSUMO_ID
        WHERE f.PRODUTO_ID = p_produto_id;
    ELSE
        SELECT MIN(PRECO_FORNECEDOR) INTO v_custo
        FROM FORNECEDOR_PRODUTO
        WHERE PRODUTO_ID = p_produto_id AND STATUS = 'ATIVO';
    END IF;

    RETURN v_custo;
END
$fn$;

-- Quantas unidades do item ainda dá para produzir com o estoque atual.
-- NULL quando o item não tem ficha técnica nem controla o próprio estoque.
DROP FUNCTION IF EXISTS fn_produto_produziveis;
CREATE OR REPLACE FUNCTION fn_produto_produziveis(p_produto_id integer)
RETURNS integer
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_qtd integer DEFAULT NULL;
    v_controla smallint DEFAULT 0;
BEGIN



    IF EXISTS (SELECT 1 FROM FICHA_TECNICA WHERE PRODUTO_ID = p_produto_id) THEN
        SELECT MIN((CASE WHEN i.STATUS = 'ATIVO' THEN FLOOR(i.ESTOQUE / GREATEST(f.QUANTIDADE, 1)) ELSE 0 END)) INTO v_qtd
        FROM FICHA_TECNICA f
        JOIN PRODUTO i ON i.ID = f.INSUMO_ID
        WHERE f.PRODUTO_ID = p_produto_id;
        RETURN v_qtd;
    END IF;

    SELECT CONTROLA_ESTOQUE, ESTOQUE INTO v_controla, v_qtd FROM PRODUTO WHERE ID = p_produto_id;
    RETURN (CASE WHEN v_controla = 1 THEN v_qtd ELSE NULL END);
END
$fn$;

DROP FUNCTION IF EXISTS sp_ficha_listar;
CREATE OR REPLACE FUNCTION sp_ficha_listar(p_produto_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT f.INSUMO_ID AS insumo_id, i.NOME AS nome, f.QUANTIDADE AS quantidade, i.UNIDADE_MEDIDA AS unidade,
           i.PRECO_UNITARIO AS custo_unitario, f.QUANTIDADE * i.PRECO_UNITARIO AS custo, i.ESTOQUE AS estoque
    FROM FICHA_TECNICA f
    JOIN PRODUTO i ON i.ID = f.INSUMO_ID
    WHERE f.PRODUTO_ID = p_produto_id
    ORDER BY fn_ord(i.NOME)) _t;
END
$fn$;

-- Insumos ativos que podem entrar em uma ficha técnica.
DROP FUNCTION IF EXISTS sp_ficha_insumos;
CREATE OR REPLACE FUNCTION sp_ficha_insumos()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT p.ID AS id, p.NOME AS nome, p.UNIDADE_MEDIDA AS unidade, p.PRECO_UNITARIO AS preco, c.NOME AS categoria
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE p.STATUS = 'ATIVO' AND c.TIPO = 'INSUMO'
    ORDER BY fn_ord(c.NOME), fn_ord(p.NOME)) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_ficha_limpar;
CREATE OR REPLACE FUNCTION sp_ficha_limpar(p_produto_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    DELETE FROM FICHA_TECNICA WHERE PRODUTO_ID = p_produto_id;
END
$fn$;

DROP FUNCTION IF EXISTS sp_ficha_adicionar;
CREATE OR REPLACE FUNCTION sp_ficha_adicionar(p_produto_id integer, p_insumo_id integer, p_quantidade integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF p_quantidade IS NULL OR p_quantidade < 1 OR p_quantidade > 9999 THEN
        RAISE EXCEPTION '%', 'Informe a quantidade de cada insumo da ficha técnica (1 a 9999).';
    END IF;
    IF p_produto_id = p_insumo_id THEN
        RAISE EXCEPTION '%', 'Um item não pode ser insumo dele mesmo.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM PRODUTO p JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
        WHERE p.ID = p_insumo_id AND c.TIPO = 'INSUMO'
    ) THEN
        RAISE EXCEPTION '%', 'Um dos insumos da ficha técnica não foi encontrado.';
    END IF;

    INSERT INTO FICHA_TECNICA (PRODUTO_ID, INSUMO_ID, QUANTIDADE)
    VALUES (p_produto_id, p_insumo_id, p_quantidade)
    ON CONFLICT (produto_id, insumo_id) DO UPDATE SET QUANTIDADE = LEAST(9999, FICHA_TECNICA.QUANTIDADE + p_quantidade);
END
$fn$;

-- Item de revenda (sem ficha técnica) que desconta o próprio estoque ao ser vendido.
DROP FUNCTION IF EXISTS sp_produto_controle_estoque;
CREATE OR REPLACE FUNCTION sp_produto_controle_estoque(p_produto_id integer, p_controla smallint)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    UPDATE PRODUTO SET CONTROLA_ESTOQUE = (CASE WHEN p_controla = 1 THEN 1 ELSE 0 END) WHERE ID = p_produto_id;
END
$fn$;

-- p_sinal = -1 dá baixa nos insumos de um pedido entregue; +1 devolve (estorno).
-- A coluna VENDA.ESTOQUE_BAIXADO impede baixa ou estorno em dobro.
-- Não abre transação: roda dentro da transação de quem chama.
DROP FUNCTION IF EXISTS sp_estoque_baixa_pedido;
CREATE OR REPLACE FUNCTION sp_estoque_baixa_pedido(p_venda_id integer, p_sinal integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_baixado smallint DEFAULT NULL;
    v_usuario integer DEFAULT NULL;
BEGIN



    SELECT ESTOQUE_BAIXADO, FUNCIONARIO_ID INTO v_baixado, v_usuario FROM VENDA WHERE ID = p_venda_id;

    IF v_baixado IS NULL OR (p_sinal < 0 AND v_baixado = 1) OR (p_sinal > 0 AND v_baixado = 0) THEN
        RETURN;
    END IF;

    WITH baixa AS (
        SELECT x.pid, SUM(x.q) AS qtd
        FROM (
            SELECT f.INSUMO_ID AS pid, vi.QUANTIDADE * f.QUANTIDADE AS q
            FROM VENDA_ITEM vi
            JOIN FICHA_TECNICA f ON f.PRODUTO_ID = vi.PRODUTO_ID
            WHERE vi.VENDA_ID = p_venda_id
            UNION ALL
            SELECT vi.PRODUTO_ID, vi.QUANTIDADE
            FROM VENDA_ITEM vi
            JOIN PRODUTO pr ON pr.ID = vi.PRODUTO_ID
            WHERE vi.VENDA_ID = p_venda_id
              AND pr.CONTROLA_ESTOQUE = 1
              AND NOT EXISTS (SELECT 1 FROM FICHA_TECNICA f2 WHERE f2.PRODUTO_ID = vi.PRODUTO_ID)
        ) x
        GROUP BY x.pid
    ), atualiza AS (
        UPDATE PRODUTO p
        SET ESTOQUE = GREATEST(0, p.ESTOQUE + p_sinal * b.qtd)
        FROM baixa b
        WHERE b.pid = p.ID
        RETURNING p.ID
    )
    INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, OBSERVACAO)
    SELECT b.pid, v_usuario, (CASE WHEN p_sinal < 0 THEN 'SAIDA' ELSE 'ENTRADA' END), b.qtd,
           CONCAT((CASE WHEN p_sinal < 0 THEN 'Baixa automática' ELSE 'Estorno' END), ' · Pedido #', p_venda_id)
    FROM baixa b
    WHERE b.qtd > 0;

    UPDATE VENDA SET ESTOQUE_BAIXADO = (CASE WHEN p_sinal < 0 THEN 1 ELSE 0 END) WHERE ID = p_venda_id;
END
$fn$;

-- ==========================================================================
-- 2. PDV: venda direta (cria, cobra e entrega na mesma operação)
--    Rode na mesma transação do PHP que chamou sp_pedido_criar/itens/totais.
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_pedido_finalizar;
CREATE OR REPLACE FUNCTION sp_pedido_finalizar(p_id integer, p_valor_recebido numeric(10, 2))
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_etapa varchar DEFAULT NULL;
    v_total numeric(10, 2) DEFAULT 0;
    v_forma varchar DEFAULT NULL;
BEGIN




    SELECT ETAPA, TOTAL, FORMA_PAGAMENTO INTO v_etapa, v_total, v_forma FROM VENDA WHERE ID = p_id;

    IF v_etapa IS NULL THEN
        RAISE EXCEPTION '%', 'Pedido não encontrado.';
    END IF;
    IF v_etapa NOT IN ('NOVO', 'PREPARO', 'PRONTO') THEN
        RAISE EXCEPTION '%', 'Este pedido já foi encerrado.';
    END IF;
    IF v_forma = 'DINHEIRO' AND p_valor_recebido IS NOT NULL AND p_valor_recebido < v_total THEN
        RAISE EXCEPTION '%', 'O valor recebido em dinheiro é menor que o total.';
    END IF;

    UPDATE VENDA
    SET ETAPA = 'ENTREGUE', STATUS = 'FINALIZADA', DATA_ETAPA = localtimestamp(0),
        VALOR_RECEBIDO = (CASE WHEN v_forma = 'DINHEIRO' THEN p_valor_recebido ELSE NULL END)
    WHERE ID = p_id;
    UPDATE VENDA_ITEM SET STATUS = 'FINALIZADA' WHERE VENDA_ID = p_id;
    UPDATE CONTA_RECEBER
    SET STATUS = 'PAGO', DATA_PAGAMENTO = CURRENT_DATE
    WHERE VENDA_ID = p_id AND STATUS IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO');

    PERFORM sp_estoque_baixa_pedido(p_id, -1);
END
$fn$;

-- Dados completos para o comprovante (cupom não fiscal).
DROP FUNCTION IF EXISTS sp_pedido_comprovante;
CREATE OR REPLACE FUNCTION sp_pedido_comprovante(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v.ID AS id, v.TIPO_PEDIDO AS tipo, v.MESA AS mesa, cl.NOME AS cliente, cl.TELEFONE AS cliente_telefone,
           CONCAT(cl.ENDERECO, ', ', cl.ENDERECO_NUMERO) AS cliente_endereco,
           v.ETAPA AS etapa, v.STATUS AS status, v.FORMA_PAGAMENTO AS forma_pagamento,
           v.VALOR AS valor, v.DESCONTO AS desconto, v.TOTAL AS total, v.VALOR_RECEBIDO AS valor_recebido,
           v.OBSERVACAO AS observacao, u.NOME AS atendente,
           to_char(v.DATA_CADASTRO, 'DD/MM/YYYY HH24:MI') AS data_hora,
           e.NOME_FANTASIA AS empresa, e.RAZAO_SOCIAL AS razao_social, e.CNPJ AS cnpj, e.TELEFONE AS empresa_telefone,
           CONCAT_WS(', ', NULLIF(e.ENDERECO, ''), NULLIF(e.ENDERECO_NUMERO, ''), NULLIF(e.CIDADE, ''), e.ESTADO) AS empresa_endereco
    FROM VENDA v
    LEFT JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    LEFT JOIN EMPRESA e ON e.ID = 1
    WHERE v.ID = p_id) _t;
END
$fn$;

-- ==========================================================================
-- 3. MESAS
--    LIVRE = sem pedidos abertos | OCUPADA = há pedido na cozinha
--    CONTA = todos os pedidos abertos já estão prontos (aguardando pagamento)
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_mesa_listar;
CREATE OR REPLACE FUNCTION sp_mesa_listar()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT m.ID AS id, m.NUMERO AS numero, m.LUGARES AS lugares, m.AREA AS area, m.STATUS AS status,
           COUNT(v.ID) AS pedidos,
           COALESCE(SUM(v.TOTAL), 0) AS total,
           COALESCE(SUM((v.ETAPA IN ('NOVO', 'PREPARO'))::int), 0) AS na_cozinha,
           FLOOR(EXTRACT(EPOCH FROM ((localtimestamp(0)) - (MIN(v.DATA_CADASTRO)))) / 60)::int AS minutos,
           CASE
               WHEN m.STATUS = 'INATIVO' THEN 'INATIVA'
               WHEN COUNT(v.ID) = 0 THEN 'LIVRE'
               WHEN SUM((v.ETAPA IN ('NOVO', 'PREPARO'))::int) = 0 THEN 'CONTA'
               ELSE 'OCUPADA'
           END AS situacao
    FROM MESA m
    LEFT JOIN VENDA v ON v.TIPO_PEDIDO = 'SALAO' AND v.MESA = m.NUMERO AND v.ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
    GROUP BY m.ID, m.NUMERO, m.LUGARES, m.AREA, m.STATUS
    ORDER BY fn_ord(m.AREA), CAST(m.NUMERO AS integer), m.NUMERO) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_mesa_obter;
CREATE OR REPLACE FUNCTION sp_mesa_obter(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT ID AS id, NUMERO AS numero, LUGARES AS lugares, AREA AS area, STATUS AS status
    FROM MESA WHERE ID = p_id) _t;
END
$fn$;

-- Mesas ativas para os formulários de pedido.
DROP FUNCTION IF EXISTS sp_mesa_opcoes;
CREATE OR REPLACE FUNCTION sp_mesa_opcoes()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT NUMERO AS numero, AREA AS area, LUGARES AS lugares
    FROM MESA WHERE STATUS = 'ATIVO'
    ORDER BY fn_ord(AREA), CAST(NUMERO AS integer), NUMERO) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_mesa_salvar;
CREATE OR REPLACE FUNCTION sp_mesa_salvar(p_id integer, p_numero varchar, p_lugares integer, p_area varchar, p_status varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_id integer DEFAULT COALESCE(p_id, 0);
    v_numero varchar DEFAULT TRIM(COALESCE(p_numero, ''));
    v_atual varchar DEFAULT NULL;
BEGIN




    IF v_numero = '' THEN
        RAISE EXCEPTION '%', 'Informe o número ou nome da mesa.';
    END IF;
    IF p_lugares IS NULL OR p_lugares < 1 OR p_lugares > 30 THEN
        RAISE EXCEPTION '%', 'A mesa deve ter de 1 a 30 lugares.';
    END IF;
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        p_status := 'ATIVO';
    END IF;
    IF EXISTS (SELECT 1 FROM MESA WHERE NUMERO = v_numero AND ID <> v_id) THEN
        RAISE EXCEPTION '%', 'Já existe uma mesa com esse número.';
    END IF;

    IF v_id = 0 THEN
        INSERT INTO MESA (NUMERO, LUGARES, AREA, STATUS)
        VALUES (v_numero, p_lugares, COALESCE(NULLIF(TRIM(p_area), ''), 'Salão'), p_status);
        v_id := lastval();
    ELSE
        SELECT NUMERO INTO v_atual FROM MESA WHERE ID = v_id;
        IF v_atual IS NULL THEN
            RAISE EXCEPTION '%', 'Mesa não encontrada.';
        END IF;
        IF (v_atual <> v_numero OR p_status = 'INATIVO') AND EXISTS (
            SELECT 1 FROM VENDA WHERE TIPO_PEDIDO = 'SALAO' AND MESA = v_atual AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
        ) THEN
            RAISE EXCEPTION '%', 'Feche a conta da mesa antes de renomeá-la ou desativá-la.';
        END IF;

        UPDATE MESA
        SET NUMERO = v_numero, LUGARES = p_lugares, AREA = COALESCE(NULLIF(TRIM(p_area), ''), 'Salão'), STATUS = p_status
        WHERE ID = v_id;
    END IF;

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v_id AS id) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_mesa_excluir;
CREATE OR REPLACE FUNCTION sp_mesa_excluir(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_numero varchar DEFAULT NULL;
BEGIN


    SELECT NUMERO INTO v_numero FROM MESA WHERE ID = p_id;
    IF v_numero IS NULL THEN
        RAISE EXCEPTION '%', 'Mesa não encontrada.';
    END IF;
    IF EXISTS (SELECT 1 FROM VENDA WHERE TIPO_PEDIDO = 'SALAO' AND MESA = v_numero AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')) THEN
        RAISE EXCEPTION '%', 'A mesa tem pedidos em aberto. Feche a conta antes de excluí-la.';
    END IF;

    DELETE FROM MESA WHERE ID = p_id;
END
$fn$;

-- Pedidos abertos de uma mesa (comanda).
DROP FUNCTION IF EXISTS sp_mesa_pedidos;
CREATE OR REPLACE FUNCTION sp_mesa_pedidos(p_numero varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v.ID AS id, v.ETAPA AS etapa, v.TOTAL AS total, v.DESCONTO AS desconto, u.NOME AS atendente,
           FLOOR(EXTRACT(EPOCH FROM ((localtimestamp(0)) - (v.DATA_CADASTRO))) / 60)::int AS minutos, to_char(v.DATA_CADASTRO, 'HH24:MI') AS hora
    FROM VENDA v
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    WHERE v.TIPO_PEDIDO = 'SALAO' AND v.MESA = p_numero AND v.ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
    ORDER BY v.DATA_CADASTRO) _t;
END
$fn$;

-- Itens somados de todos os pedidos abertos da mesa.
DROP FUNCTION IF EXISTS sp_mesa_comanda;
CREATE OR REPLACE FUNCTION sp_mesa_comanda(p_numero varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT p.NOME AS nome, SUM(vi.QUANTIDADE) AS quantidade, vi.PRECO_UNITARIO AS preco, SUM(vi.TOTAL) AS total
    FROM VENDA v
    JOIN VENDA_ITEM vi ON vi.VENDA_ID = v.ID
    JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID
    WHERE v.TIPO_PEDIDO = 'SALAO' AND v.MESA = p_numero AND v.ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
    GROUP BY vi.PRODUTO_ID, p.NOME, vi.PRECO_UNITARIO
    ORDER BY fn_ord(p.NOME)) _t;
END
$fn$;

-- Fecha a conta: todos os pedidos abertos da mesa são cobrados e entregues.
-- Só é possível quando nenhum pedido está na cozinha. Devolve qtd, total e troco.
DROP FUNCTION IF EXISTS sp_mesa_fechar_conta;
CREATE OR REPLACE FUNCTION sp_mesa_fechar_conta(p_numero varchar, p_forma varchar, p_valor_recebido numeric(10, 2))
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_fim integer DEFAULT 0;
    v_id integer DEFAULT 0;
    v_qtd integer DEFAULT 0;
    v_cozinha integer DEFAULT 0;
    v_total numeric(10, 2) DEFAULT 0;
BEGIN







    SELECT COUNT(*), COALESCE(SUM((ETAPA IN ('NOVO', 'PREPARO'))::int), 0), COALESCE(SUM(TOTAL), 0)
    INTO v_qtd, v_cozinha, v_total
    FROM VENDA
    WHERE TIPO_PEDIDO = 'SALAO' AND MESA = p_numero AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO');

    IF v_qtd = 0 THEN
        RAISE EXCEPTION '%', 'Não há pedidos em aberto nesta mesa.';
    END IF;
    IF v_cozinha > 0 THEN
        RAISE EXCEPTION '%', 'Ainda há pedidos desta mesa na cozinha. Marque-os como prontos antes de fechar a conta.';
    END IF;
    IF NOT (p_forma = ANY(string_to_array(fn_config(0, 'pagamentos', 'PIX,CREDITO,DEBITO,DINHEIRO'), ','))) THEN
        RAISE EXCEPTION '%', 'Forma de pagamento não aceita pela loja.';
    END IF;
    IF p_forma = 'DINHEIRO' AND p_valor_recebido IS NOT NULL AND p_valor_recebido < v_total THEN
        RAISE EXCEPTION '%', 'O valor recebido em dinheiro é menor que o total da mesa.';
    END IF;

    

    FOR v_id IN
        SELECT ID FROM VENDA
        WHERE TIPO_PEDIDO = 'SALAO' AND MESA = p_numero AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
    LOOP
        UPDATE VENDA
        SET FORMA_PAGAMENTO = p_forma, ETAPA = 'ENTREGUE', STATUS = 'FINALIZADA', DATA_ETAPA = localtimestamp(0)
        WHERE ID = v_id;
        UPDATE VENDA_ITEM SET STATUS = 'FINALIZADA' WHERE VENDA_ID = v_id;
        UPDATE CONTA_RECEBER
        SET STATUS = 'PAGO', DATA_PAGAMENTO = CURRENT_DATE
        WHERE VENDA_ID = v_id AND STATUS IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO');
        PERFORM sp_estoque_baixa_pedido(v_id, -1);
    END LOOP;

    

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v_qtd AS pedidos, v_total AS total,
           (CASE WHEN p_forma = 'DINHEIRO' AND p_valor_recebido IS NOT NULL THEN p_valor_recebido - v_total ELSE 0 END) AS troco) _t;
END
$fn$;

-- Leva os pedidos abertos de uma mesa para outra mesa livre.
DROP FUNCTION IF EXISTS sp_mesa_transferir;
CREATE OR REPLACE FUNCTION sp_mesa_transferir(p_origem varchar, p_destino varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF p_origem = p_destino THEN
        RAISE EXCEPTION '%', 'Escolha uma mesa diferente da atual.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM MESA WHERE NUMERO = p_destino AND STATUS = 'ATIVO') THEN
        RAISE EXCEPTION '%', 'Mesa de destino não encontrada.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM VENDA WHERE TIPO_PEDIDO = 'SALAO' AND MESA = p_origem AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')) THEN
        RAISE EXCEPTION '%', 'A mesa de origem não tem pedidos em aberto.';
    END IF;
    IF EXISTS (SELECT 1 FROM VENDA WHERE TIPO_PEDIDO = 'SALAO' AND MESA = p_destino AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')) THEN
        RAISE EXCEPTION '%', 'A mesa de destino está ocupada.';
    END IF;

    UPDATE VENDA SET MESA = p_destino
    WHERE TIPO_PEDIDO = 'SALAO' AND MESA = p_origem AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO');
END
$fn$;

-- ==========================================================================
-- 4. FORNECEDORES
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_fornecedor_listar;
CREATE OR REPLACE FUNCTION sp_fornecedor_listar(p_busca varchar, p_status varchar, p_limite integer, p_deslocamento integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    v_digitos varchar DEFAULT fn_somente_digitos(p_busca);
    v_numeros varchar DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');
BEGIN




    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT f.ID AS id, f.NOME AS nome, f.DOCUMENTO AS documento, f.TELEFONE AS telefone, f.CONTATO AS contato,
           f.EMAIL AS email, f.STATUS AS status, ci.NOME AS cidade, es.SIGLA AS estado,
           (SELECT COUNT(*) FROM COMPRA c WHERE c.FORNECEDOR_ID = f.ID AND c.STATUS = 'FINALIZADA') AS compras,
           (SELECT COALESCE(SUM(c.TOTAL), 0) FROM COMPRA c WHERE c.FORNECEDOR_ID = f.ID AND c.STATUS = 'FINALIZADA') AS total_comprado,
           (SELECT COUNT(*) FROM FORNECEDOR_PRODUTO fp WHERE fp.FORNECEDOR_ID = f.ID AND fp.STATUS = 'ATIVO') AS produtos,
           (SELECT to_char(MAX(c.DATA_CADASTRO), 'DD/MM/YYYY') FROM COMPRA c WHERE c.FORNECEDOR_ID = f.ID AND c.STATUS = 'FINALIZADA') AS ultima_compra
    FROM FORNECEDOR f
    JOIN CIDADE ci ON ci.ID = f.CIDADE_ID
    JOIN ESTADO es ON es.ID = ci.ESTADO_ID
    WHERE (TRIM(COALESCE(p_busca, '')) = ''
           OR fn_busca(f.NOME, v_texto) OR fn_busca(f.CONTATO, v_texto)
           OR (v_digitos <> '' AND (fn_busca(f.DOCUMENTO, v_numeros) OR fn_busca(f.TELEFONE, v_numeros))))
      AND (p_status NOT IN ('ATIVO', 'INATIVO') OR f.STATUS = p_status)
    ORDER BY fn_ord(f.NOME)
    LIMIT p_limite OFFSET p_deslocamento) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_fornecedor_contagem;
CREATE OR REPLACE FUNCTION sp_fornecedor_contagem(p_busca varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    v_digitos varchar DEFAULT fn_somente_digitos(p_busca);
    v_numeros varchar DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');
BEGIN




    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT COUNT(*) AS todos,
           COALESCE(SUM((f.STATUS = 'ATIVO')::int), 0) AS ativos,
           COALESCE(SUM((f.STATUS = 'INATIVO')::int), 0) AS inativos
    FROM FORNECEDOR f
    WHERE TRIM(COALESCE(p_busca, '')) = ''
       OR fn_busca(f.NOME, v_texto) OR fn_busca(f.CONTATO, v_texto)
       OR (v_digitos <> '' AND (fn_busca(f.DOCUMENTO, v_numeros) OR fn_busca(f.TELEFONE, v_numeros)))) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_fornecedor_obter;
CREATE OR REPLACE FUNCTION sp_fornecedor_obter(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT f.ID AS id, f.NOME AS nome, f.DOCUMENTO AS documento, f.ENDERECO AS endereco,
           f.ENDERECO_NUMERO AS endereco_numero, f.TELEFONE AS telefone, f.CONTATO AS contato, f.EMAIL AS email,
           f.STATUS AS status, ci.NOME AS cidade, es.SIGLA AS estado
    FROM FORNECEDOR f
    JOIN CIDADE ci ON ci.ID = f.CIDADE_ID
    JOIN ESTADO es ON es.ID = ci.ESTADO_ID
    WHERE f.ID = p_id) _t;
END
$fn$;

-- Produtos que o fornecedor entrega, com o último preço praticado.
DROP FUNCTION IF EXISTS sp_fornecedor_produtos;
CREATE OR REPLACE FUNCTION sp_fornecedor_produtos(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT p.ID AS id, p.NOME AS nome, p.UNIDADE_MEDIDA AS unidade, fp.PRECO_FORNECEDOR AS preco,
           fp.PRAZO_ENTREGA_DIAS AS prazo
    FROM FORNECEDOR_PRODUTO fp
    JOIN PRODUTO p ON p.ID = fp.PRODUTO_ID
    WHERE fp.FORNECEDOR_ID = p_id AND fp.STATUS = 'ATIVO'
    ORDER BY fn_ord(p.NOME)) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_fornecedor_salvar;
CREATE OR REPLACE FUNCTION sp_fornecedor_salvar(p_id integer, p_nome varchar, p_documento varchar, p_endereco varchar, p_numero varchar, p_telefone varchar, p_contato varchar, p_email varchar, p_status varchar, p_uf varchar, p_cidade varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_documento varchar DEFAULT fn_somente_digitos(p_documento);
    v_telefone varchar DEFAULT fn_somente_digitos(p_telefone);
    v_email varchar DEFAULT NULLIF(TRIM(COALESCE(p_email, '')), '');
    v_estado_id integer DEFAULT NULL;
    v_cidade_id integer DEFAULT NULL;
    v_id integer DEFAULT COALESCE(p_id, 0);
BEGIN







    IF TRIM(COALESCE(p_nome, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe o nome do fornecedor.';
    END IF;
    IF CHAR_LENGTH(v_documento) NOT IN (11, 14) THEN
        RAISE EXCEPTION '%', 'Informe um CNPJ (14 números) ou CPF (11 números) válido.';
    END IF;
    IF CHAR_LENGTH(v_telefone) NOT IN (10, 11) THEN
        RAISE EXCEPTION '%', 'Informe o telefone com DDD (10 ou 11 números).';
    END IF;
    IF v_email IS NOT NULL AND v_email NOT ILIKE '%_@_%.__%' THEN
        RAISE EXCEPTION '%', 'Informe um e-mail válido ou deixe o campo vazio.';
    END IF;
    IF TRIM(COALESCE(p_endereco, '')) = '' OR TRIM(COALESCE(p_numero, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe o endereço e o número.';
    END IF;
    IF TRIM(COALESCE(p_cidade, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe a cidade.';
    END IF;
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        p_status := 'ATIVO';
    END IF;

    SELECT ID INTO v_estado_id FROM ESTADO WHERE SIGLA = p_uf LIMIT 1;
    IF v_estado_id IS NULL THEN
        RAISE EXCEPTION '%', 'Estado inválido.';
    END IF;
    IF v_id > 0 AND NOT EXISTS (SELECT 1 FROM FORNECEDOR WHERE ID = v_id) THEN
        RAISE EXCEPTION '%', 'Fornecedor não encontrado.';
    END IF;
    IF EXISTS (SELECT 1 FROM FORNECEDOR WHERE DOCUMENTO = v_documento AND ID <> v_id) THEN
        RAISE EXCEPTION '%', 'Já existe um fornecedor com esse CNPJ/CPF.';
    END IF;

    SELECT ID INTO v_cidade_id FROM CIDADE WHERE NOME = TRIM(p_cidade) AND ESTADO_ID = v_estado_id LIMIT 1;
    IF v_cidade_id IS NULL THEN
        INSERT INTO CIDADE (NOME, ESTADO_ID) VALUES (TRIM(p_cidade), v_estado_id);
        v_cidade_id := lastval();
    END IF;

    IF v_id = 0 THEN
        INSERT INTO FORNECEDOR (NOME, DOCUMENTO, ENDERECO, ENDERECO_NUMERO, TELEFONE, CONTATO, EMAIL, STATUS, CIDADE_ID)
        VALUES (TRIM(p_nome), v_documento, TRIM(p_endereco), TRIM(p_numero), v_telefone,
                NULLIF(TRIM(COALESCE(p_contato, '')), ''), v_email, p_status, v_cidade_id);
        v_id := lastval();
    ELSE
        UPDATE FORNECEDOR
        SET NOME = TRIM(p_nome), DOCUMENTO = v_documento, ENDERECO = TRIM(p_endereco),
            ENDERECO_NUMERO = TRIM(p_numero), TELEFONE = v_telefone,
            CONTATO = NULLIF(TRIM(COALESCE(p_contato, '')), ''), EMAIL = v_email, STATUS = p_status,
            CIDADE_ID = v_cidade_id
        WHERE ID = v_id;
    END IF;

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v_id AS id) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_fornecedor_status;
CREATE OR REPLACE FUNCTION sp_fornecedor_status(p_id integer, p_status varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        RAISE EXCEPTION '%', 'Situação inválida.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM FORNECEDOR WHERE ID = p_id) THEN
        RAISE EXCEPTION '%', 'Fornecedor não encontrado.';
    END IF;

    UPDATE FORNECEDOR SET STATUS = p_status WHERE ID = p_id;
END
$fn$;

-- Exclui; com compras ou produtos vinculados apenas inativa. Devolve EXCLUIDO ou INATIVADO.
DROP FUNCTION IF EXISTS sp_fornecedor_excluir;
CREATE OR REPLACE FUNCTION sp_fornecedor_excluir(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM FORNECEDOR WHERE ID = p_id) THEN
        RAISE EXCEPTION '%', 'Fornecedor não encontrado.';
    END IF;

    IF EXISTS (SELECT 1 FROM COMPRA WHERE FORNECEDOR_ID = p_id)
       OR EXISTS (SELECT 1 FROM FORNECEDOR_PRODUTO WHERE FORNECEDOR_ID = p_id) THEN
        UPDATE FORNECEDOR SET STATUS = 'INATIVO' WHERE ID = p_id;
        RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT 'INATIVADO' AS resultado) _t;
    ELSE
        DELETE FROM FORNECEDOR WHERE ID = p_id;
        RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT 'EXCLUIDO' AS resultado) _t;
    END IF;
END
$fn$;

DROP FUNCTION IF EXISTS sp_fornecedor_opcoes;
CREATE OR REPLACE FUNCTION sp_fornecedor_opcoes()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT ID AS id, NOME AS nome FROM FORNECEDOR WHERE STATUS = 'ATIVO' ORDER BY fn_ord(NOME)) _t;
END
$fn$;

-- ==========================================================================
-- 5. COMPRAS (COMPRA + COMPRA_ITEM + CONTA_PAGAR)
--    Finalizar a compra dá entrada no estoque, atualiza o custo dos insumos
--    e gera as parcelas no Fluxo de Caixa. Criar/itens/finalizar rodam na
--    mesma transação do PHP; cancelar abre a própria transação.
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_compra_listar;
CREATE OR REPLACE FUNCTION sp_compra_listar(p_busca varchar, p_status varchar, p_de date, p_ate date, p_limite integer, p_deslocamento integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    v_numero integer DEFAULT CAST(NULLIF(fn_somente_digitos(p_busca), '') AS integer);
BEGIN



    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT c.ID AS id, to_char(c.DATA_CADASTRO, 'DD/MM/YYYY') AS data, f.NOME AS fornecedor, c.NUMERO_NF AS nf,
           c.VALOR AS valor, c.DESCONTO AS desconto, c.TOTAL AS total, c.STATUS AS status, u.NOME AS responsavel,
           (SELECT COUNT(*) FROM COMPRA_ITEM ci WHERE ci.COMPRA_ID = c.ID) AS itens,
           (SELECT COUNT(*) FROM CONTA_PAGAR cp WHERE cp.COMPRA_ID = c.ID) AS parcelas,
           (SELECT COUNT(*) FROM CONTA_PAGAR cp WHERE cp.COMPRA_ID = c.ID AND cp.STATUS = 'PAGO') AS parcelas_pagas
    FROM COMPRA c
    JOIN FORNECEDOR f ON f.ID = c.FORNECEDOR_ID
    JOIN USUARIOS u ON u.ID = c.FUNCIONARIO_ID
    WHERE c.DATA_CADASTRO >= p_de AND c.DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
      AND (TRIM(COALESCE(p_busca, '')) = '' OR fn_busca(f.NOME, v_texto) OR fn_busca(c.NUMERO_NF, v_texto) OR c.ID = v_numero)
      AND (p_status NOT IN ('FINALIZADA', 'CANCELADA') OR c.STATUS = p_status)
    ORDER BY c.DATA_CADASTRO DESC, c.ID DESC
    LIMIT p_limite OFFSET p_deslocamento) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_compra_contagem;
CREATE OR REPLACE FUNCTION sp_compra_contagem(p_busca varchar, p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    v_numero integer DEFAULT CAST(NULLIF(fn_somente_digitos(p_busca), '') AS integer);
BEGIN



    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT COUNT(*) AS todos,
           COALESCE(SUM((c.STATUS = 'FINALIZADA')::int), 0) AS finalizadas,
           COALESCE(SUM((c.STATUS = 'CANCELADA')::int), 0) AS canceladas
    FROM COMPRA c
    JOIN FORNECEDOR f ON f.ID = c.FORNECEDOR_ID
    WHERE c.DATA_CADASTRO >= p_de AND c.DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
      AND (TRIM(COALESCE(p_busca, '')) = '' OR fn_busca(f.NOME, v_texto) OR fn_busca(c.NUMERO_NF, v_texto) OR c.ID = v_numero)) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_compra_resumo;
CREATE OR REPLACE FUNCTION sp_compra_resumo(p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT
        (SELECT COALESCE(SUM(TOTAL), 0) FROM COMPRA
         WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')) AS total_comprado,
        (SELECT COUNT(*) FROM COMPRA
         WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')) AS compras,
        (SELECT COALESCE(SUM(TOTAL), 0) FROM CONTA_PAGAR WHERE STATUS IN ('PENDENTE', 'ATRASADO', 'AGENDADO')) AS a_pagar,
        (SELECT COALESCE(SUM(TOTAL), 0) FROM CONTA_PAGAR WHERE STATUS IN ('PENDENTE', 'ATRASADO') AND DATA_VENCIMENTO < CURRENT_DATE) AS vencido,
        (SELECT f.NOME FROM COMPRA c JOIN FORNECEDOR f ON f.ID = c.FORNECEDOR_ID
         WHERE c.STATUS = 'FINALIZADA' AND c.DATA_CADASTRO >= p_de AND c.DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
         GROUP BY f.ID, f.NOME ORDER BY SUM(c.TOTAL) DESC LIMIT 1) AS principal_fornecedor) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_compra_obter;
CREATE OR REPLACE FUNCTION sp_compra_obter(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT c.ID AS id, to_char(c.DATA_CADASTRO, 'DD/MM/YYYY HH24:MI') AS data, c.FORNECEDOR_ID AS fornecedor_id,
           f.NOME AS fornecedor, f.TELEFONE AS fornecedor_telefone, c.NUMERO_NF AS nf, c.OBSERVACAO AS observacao,
           c.VALOR AS valor, c.DESCONTO AS desconto, c.TOTAL AS total, c.STATUS AS status, u.NOME AS responsavel
    FROM COMPRA c
    JOIN FORNECEDOR f ON f.ID = c.FORNECEDOR_ID
    JOIN USUARIOS u ON u.ID = c.FUNCIONARIO_ID
    WHERE c.ID = p_id) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_compra_itens;
CREATE OR REPLACE FUNCTION sp_compra_itens(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT ci.PRODUTO_ID AS produto_id, p.NOME AS nome, p.UNIDADE_MEDIDA AS unidade, ci.QUANTIDADE AS quantidade,
           ci.PRECO_UNITARIO AS preco, ci.TOTAL AS total
    FROM COMPRA_ITEM ci
    JOIN PRODUTO p ON p.ID = ci.PRODUTO_ID
    WHERE ci.COMPRA_ID = p_id
    ORDER BY fn_ord(p.NOME)) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_compra_parcelas;
CREATE OR REPLACE FUNCTION sp_compra_parcelas(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT ID AS id, PARCELA_NUMERO AS numero, PARCELA_QUANTIDADE AS quantidade, DATA_VENCIMENTO AS vencimento,
           DATA_PAGAMENTO AS pagamento, TOTAL AS total,
           CASE WHEN STATUS = 'PENDENTE' AND DATA_VENCIMENTO < CURRENT_DATE THEN 'ATRASADO' ELSE STATUS END AS situacao
    FROM CONTA_PAGAR
    WHERE COMPRA_ID = p_id
    ORDER BY PARCELA_NUMERO) _t;
END
$fn$;

-- Produtos ativos que podem ser comprados (insumos e itens de revenda).
DROP FUNCTION IF EXISTS sp_compra_opcoes_produtos;
CREATE OR REPLACE FUNCTION sp_compra_opcoes_produtos()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT p.ID AS id, p.NOME AS nome, p.UNIDADE_MEDIDA AS unidade, p.PRECO_UNITARIO AS preco, p.ESTOQUE AS estoque,
           c.NOME AS categoria, c.TIPO AS tipo
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE p.STATUS = 'ATIVO' AND (c.TIPO = 'INSUMO' OR p.CONTROLA_ESTOQUE = 1)
    ORDER BY c.TIPO DESC, fn_ord(c.NOME), fn_ord(p.NOME)) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_compra_criar;
CREATE OR REPLACE FUNCTION sp_compra_criar(p_fornecedor_id integer, p_usuario_id integer, p_nf varchar, p_observacao varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM FORNECEDOR WHERE ID = p_fornecedor_id AND STATUS = 'ATIVO') THEN
        RAISE EXCEPTION '%', 'Escolha um fornecedor ativo.';
    END IF;

    INSERT INTO COMPRA (VALOR, DESCONTO, TOTAL, FORNECEDOR_ID, FUNCIONARIO_ID, STATUS, NUMERO_NF, OBSERVACAO)
    VALUES (0, 0, 0, p_fornecedor_id, p_usuario_id, 'EM_ABERTO',
            NULLIF(TRIM(COALESCE(p_nf, '')), ''), NULLIF(TRIM(COALESCE(p_observacao, '')), ''));

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT lastval() AS id) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_compra_item_adicionar;
CREATE OR REPLACE FUNCTION sp_compra_item_adicionar(p_compra_id integer, p_produto_id integer, p_quantidade integer, p_preco numeric(10, 2))
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF p_quantidade IS NULL OR p_quantidade < 1 OR p_quantidade > 999999 THEN
        RAISE EXCEPTION '%', 'Informe uma quantidade válida para cada item da compra.';
    END IF;
    IF p_preco IS NULL OR p_preco < 0.01 THEN
        RAISE EXCEPTION '%', 'Informe o preço unitário de cada item da compra.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM PRODUTO WHERE ID = p_produto_id AND STATUS = 'ATIVO') THEN
        RAISE EXCEPTION '%', 'Um dos produtos da compra não está ativo.';
    END IF;

    INSERT INTO COMPRA_ITEM (COMPRA_ID, PRODUTO_ID, QUANTIDADE, PRECO_UNITARIO, VALOR, DESCONTO, TOTAL, STATUS)
    VALUES (p_compra_id, p_produto_id, p_quantidade, p_preco, p_quantidade * p_preco, 0, p_quantidade * p_preco, 'EM_ABERTO')
    ON CONFLICT (compra_id, produto_id) DO UPDATE SET
        QUANTIDADE = COMPRA_ITEM.QUANTIDADE + p_quantidade,
        PRECO_UNITARIO = p_preco,
        VALOR = (COMPRA_ITEM.QUANTIDADE + p_quantidade) * p_preco,
        TOTAL = (COMPRA_ITEM.QUANTIDADE + p_quantidade) * p_preco;
END
$fn$;

DROP FUNCTION IF EXISTS sp_compra_finalizar;
CREATE OR REPLACE FUNCTION sp_compra_finalizar(p_compra_id integer, p_desconto numeric(10, 2), p_parcelas integer, p_primeiro_vencimento date, p_pago smallint, p_usuario_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_valor numeric(10, 2) DEFAULT 0;
    v_itens integer DEFAULT 0;
    v_desconto numeric(10, 2) DEFAULT COALESCE(p_desconto, 0);
    v_total numeric(10, 2) DEFAULT 0;
    v_parcela numeric(10, 2) DEFAULT 0;
    v_acumulado numeric(10, 2) DEFAULT 0;
    v_i integer DEFAULT 1;
    v_fornecedor integer DEFAULT NULL;
BEGIN









    SELECT FORNECEDOR_ID INTO v_fornecedor FROM COMPRA WHERE ID = p_compra_id AND STATUS = 'EM_ABERTO';
    IF v_fornecedor IS NULL THEN
        RAISE EXCEPTION '%', 'Compra não encontrada ou já finalizada.';
    END IF;

    SELECT COALESCE(SUM(TOTAL), 0), COUNT(*) INTO v_valor, v_itens FROM COMPRA_ITEM WHERE COMPRA_ID = p_compra_id;

    IF v_itens = 0 THEN
        RAISE EXCEPTION '%', 'Adicione ao menos um item à compra.';
    END IF;
    IF v_desconto < 0 OR v_desconto >= v_valor THEN
        RAISE EXCEPTION '%', 'O desconto deve ser menor que o valor dos itens.';
    END IF;
    IF p_parcelas IS NULL OR p_parcelas < 1 OR p_parcelas > 12 THEN
        RAISE EXCEPTION '%', 'Escolha de 1 a 12 parcelas.';
    END IF;
    IF p_primeiro_vencimento IS NULL THEN
        RAISE EXCEPTION '%', 'Informe o vencimento da primeira parcela.';
    END IF;

    v_total := v_valor - v_desconto;

    UPDATE COMPRA SET VALOR = v_valor, DESCONTO = v_desconto, TOTAL = v_total, STATUS = 'FINALIZADA' WHERE ID = p_compra_id;
    UPDATE COMPRA_ITEM SET STATUS = 'FINALIZADA' WHERE COMPRA_ID = p_compra_id;

    -- parcelas mensais; a última absorve a diferença de centavos
    v_parcela := ROUND(v_total / p_parcelas, 2);
    WHILE v_i <= p_parcelas LOOP
        IF v_i = p_parcelas THEN
            v_parcela := v_total - v_acumulado;
        END IF;
        INSERT INTO CONTA_PAGAR (COMPRA_ID, PARCELA_QUANTIDADE, PARCELA_NUMERO, DATA_VENCIMENTO, DATA_PAGAMENTO,
                                 VALOR, DESCONTO, TOTAL, STATUS)
        VALUES (p_compra_id, p_parcelas, v_i, (p_primeiro_vencimento + (v_i - 1) * INTERVAL '1 month'),
                (CASE WHEN p_pago = 1 AND v_i = 1 THEN CURRENT_DATE ELSE NULL END), v_parcela, 0, v_parcela,
                (CASE WHEN p_pago = 1 AND v_i = 1 THEN 'PAGO' ELSE 'PENDENTE' END));
        v_acumulado := v_acumulado + v_parcela;
        v_i := v_i + 1;
    END LOOP;

    -- entrada no estoque
    UPDATE PRODUTO p
    SET ESTOQUE = p.ESTOQUE + ci.QUANTIDADE
    FROM COMPRA_ITEM ci
    WHERE ci.PRODUTO_ID = p.ID AND ci.COMPRA_ID = p_compra_id;

    INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, OBSERVACAO)
    SELECT ci.PRODUTO_ID, p_usuario_id, 'ENTRADA', ci.QUANTIDADE, CONCAT('Compra #', p_compra_id)
    FROM COMPRA_ITEM ci WHERE ci.COMPRA_ID = p_compra_id;

    -- o custo do insumo passa a ser o último preço pago
    UPDATE PRODUTO p
    SET PRECO_UNITARIO = ci.PRECO_UNITARIO
    FROM COMPRA_ITEM ci, PRODUTO_CATEGORIA cat
    WHERE ci.PRODUTO_ID = p.ID AND ci.COMPRA_ID = p_compra_id
      AND cat.ID = p.CATEGORIA_ID AND cat.TIPO = 'INSUMO';

    -- tabela de preços do fornecedor
    INSERT INTO FORNECEDOR_PRODUTO (FORNECEDOR_ID, PRODUTO_ID, PRECO_FORNECEDOR, PRAZO_ENTREGA_DIAS, STATUS)
    SELECT v_fornecedor, ci.PRODUTO_ID, ci.PRECO_UNITARIO, 0, 'ATIVO'
    FROM COMPRA_ITEM ci WHERE ci.COMPRA_ID = p_compra_id
    ON CONFLICT (fornecedor_id, produto_id) DO UPDATE SET PRECO_FORNECEDOR = EXCLUDED.PRECO_FORNECEDOR, STATUS = 'ATIVO';
END
$fn$;

-- Cancela uma compra finalizada: estorna o estoque e cancela as parcelas em aberto.
DROP FUNCTION IF EXISTS sp_compra_cancelar;
CREATE OR REPLACE FUNCTION sp_compra_cancelar(p_compra_id integer, p_usuario_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_status varchar DEFAULT NULL;
BEGIN


    /* (transação implícita) */

    SELECT STATUS INTO v_status FROM COMPRA WHERE ID = p_compra_id;

    IF v_status IS NULL THEN
        RAISE EXCEPTION '%', 'Compra não encontrada.';
    END IF;
    IF v_status = 'CANCELADA' THEN
        RAISE EXCEPTION '%', 'Esta compra já está cancelada.';
    END IF;
    IF EXISTS (SELECT 1 FROM CONTA_PAGAR WHERE COMPRA_ID = p_compra_id AND STATUS = 'PAGO') THEN
        RAISE EXCEPTION '%', 'Há parcelas pagas desta compra. Desfaça os pagamentos no Fluxo de Caixa antes de cancelar.';
    END IF;

    

    IF v_status = 'FINALIZADA' THEN
        UPDATE PRODUTO p
        SET ESTOQUE = GREATEST(0, p.ESTOQUE - ci.QUANTIDADE)
        FROM COMPRA_ITEM ci
        WHERE ci.PRODUTO_ID = p.ID AND ci.COMPRA_ID = p_compra_id;

        INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, OBSERVACAO)
        SELECT ci.PRODUTO_ID, p_usuario_id, 'SAIDA', ci.QUANTIDADE, CONCAT('Estorno da compra #', p_compra_id)
        FROM COMPRA_ITEM ci WHERE ci.COMPRA_ID = p_compra_id;
    END IF;

    UPDATE CONTA_PAGAR SET STATUS = 'CANCELADO', DATA_PAGAMENTO = NULL WHERE COMPRA_ID = p_compra_id;
    UPDATE COMPRA_ITEM SET STATUS = 'CANCELADA' WHERE COMPRA_ID = p_compra_id;
    UPDATE COMPRA SET STATUS = 'CANCELADA' WHERE ID = p_compra_id;
END
$fn$;

-- ==========================================================================
-- 6. RELATÓRIOS (período por data de cadastro da venda)
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_rel_resumo;
CREATE OR REPLACE FUNCTION sp_rel_resumo(p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT a.*, b.itens, b.cmv, d.despesas_pagas, d.despesas_operacionais, d.compras_pagas
    FROM (
        SELECT COALESCE(SUM(CASE WHEN v.STATUS = 'FINALIZADA' THEN v.TOTAL END), 0) AS faturamento,
               COALESCE(SUM((v.STATUS = 'FINALIZADA')::int), 0) AS pedidos,
               COALESCE(SUM((v.STATUS = 'CANCELADA')::int), 0) AS cancelados,
               COALESCE(SUM(CASE WHEN v.STATUS = 'CANCELADA' THEN v.TOTAL END), 0) AS valor_cancelado,
               COALESCE(SUM(CASE WHEN v.STATUS = 'FINALIZADA' THEN v.DESCONTO END), 0) AS descontos,
               COUNT(DISTINCT CASE WHEN v.STATUS = 'FINALIZADA' THEN v.CLIENTE_ID END) AS clientes
        FROM VENDA v
        WHERE v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    ) a
    CROSS JOIN (
        SELECT COALESCE(SUM(vi.QUANTIDADE), 0) AS itens,
               COALESCE(SUM(vi.QUANTIDADE * COALESCE(fn_produto_custo(vi.PRODUTO_ID), 0)), 0) AS cmv
        FROM VENDA_ITEM vi
        JOIN VENDA v ON v.ID = vi.VENDA_ID
        WHERE v.STATUS = 'FINALIZADA' AND v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    ) b
    CROSS JOIN (
        SELECT COALESCE(SUM(total), 0) AS despesas_pagas,
               COALESCE(SUM(CASE WHEN origem = 'MANUAL' THEN total END), 0) AS despesas_operacionais,
               COALESCE(SUM(CASE WHEN origem = 'COMPRA' THEN total END), 0) AS compras_pagas
        FROM vw_fluxo_caixa
        WHERE tipo = 'SAIDA' AND status = 'PAGO' AND pagamento BETWEEN p_de AND p_ate
    ) d) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_rel_vendas_dia;
CREATE OR REPLACE FUNCTION sp_rel_vendas_dia(p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT (DATA_CADASTRO)::date AS dia, COUNT(*) AS pedidos, SUM(TOTAL) AS faturamento
    FROM VENDA
    WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    GROUP BY (DATA_CADASTRO)::date
    ORDER BY dia) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_rel_formas;
CREATE OR REPLACE FUNCTION sp_rel_formas(p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT FORMA_PAGAMENTO AS forma, COUNT(*) AS pedidos, SUM(TOTAL) AS total
    FROM VENDA
    WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    GROUP BY FORMA_PAGAMENTO
    ORDER BY total DESC) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_rel_tipos;
CREATE OR REPLACE FUNCTION sp_rel_tipos(p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT TIPO_PEDIDO AS tipo, COUNT(*) AS pedidos, SUM(TOTAL) AS total, AVG(TOTAL) AS ticket
    FROM VENDA
    WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    GROUP BY TIPO_PEDIDO
    ORDER BY total DESC) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_rel_horas;
CREATE OR REPLACE FUNCTION sp_rel_horas(p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT EXTRACT(HOUR FROM DATA_CADASTRO)::int AS hora, COUNT(*) AS pedidos, SUM(TOTAL) AS total
    FROM VENDA
    WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    GROUP BY EXTRACT(HOUR FROM DATA_CADASTRO)::int
    ORDER BY hora) _t;
END
$fn$;

-- 1 = domingo ... 7 = sábado
DROP FUNCTION IF EXISTS sp_rel_dias_semana;
CREATE OR REPLACE FUNCTION sp_rel_dias_semana(p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT (EXTRACT(DOW FROM DATA_CADASTRO)::int + 1) AS dia, COUNT(*) AS pedidos, SUM(TOTAL) AS total
    FROM VENDA
    WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    GROUP BY (EXTRACT(DOW FROM DATA_CADASTRO)::int + 1)
    ORDER BY dia) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_rel_produtos;
CREATE OR REPLACE FUNCTION sp_rel_produtos(p_de date, p_ate date, p_limite integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT p.ID AS id, p.NOME AS nome, c.NOME AS categoria, SUM(vi.QUANTIDADE) AS quantidade, SUM(vi.TOTAL) AS receita,
           SUM(vi.QUANTIDADE * COALESCE(fn_produto_custo(p.ID), 0)) AS custo
    FROM VENDA_ITEM vi
    JOIN VENDA v ON v.ID = vi.VENDA_ID
    JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE v.STATUS = 'FINALIZADA' AND v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    GROUP BY p.ID, p.NOME, c.NOME
    ORDER BY receita DESC
    LIMIT p_limite) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_rel_categorias;
CREATE OR REPLACE FUNCTION sp_rel_categorias(p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT c.NOME AS categoria, SUM(vi.QUANTIDADE) AS quantidade, SUM(vi.TOTAL) AS receita
    FROM VENDA_ITEM vi
    JOIN VENDA v ON v.ID = vi.VENDA_ID
    JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE v.STATUS = 'FINALIZADA' AND v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    GROUP BY c.ID, c.NOME
    ORDER BY receita DESC) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_rel_atendentes;
CREATE OR REPLACE FUNCTION sp_rel_atendentes(p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT u.NOME AS nome, COALESCE(u.CARGO, 'FUNCIONARIO') AS cargo, COUNT(*) AS pedidos, SUM(v.TOTAL) AS total,
           AVG(v.TOTAL) AS ticket
    FROM VENDA v
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    WHERE v.STATUS = 'FINALIZADA' AND v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    GROUP BY u.ID, u.NOME, u.CARGO
    ORDER BY total DESC) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_rel_clientes;
CREATE OR REPLACE FUNCTION sp_rel_clientes(p_de date, p_ate date, p_limite integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT cl.NOME AS nome, COUNT(*) AS pedidos, SUM(v.TOTAL) AS total,
           to_char(MAX(v.DATA_CADASTRO), 'DD/MM/YYYY') AS ultima_compra
    FROM VENDA v
    JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    WHERE v.STATUS = 'FINALIZADA' AND v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    GROUP BY cl.ID, cl.NOME
    ORDER BY total DESC
    LIMIT p_limite) _t;
END
$fn$;

-- Lista completa de vendas do período (exportação CSV / Excel).
DROP FUNCTION IF EXISTS sp_rel_vendas_lista;
CREATE OR REPLACE FUNCTION sp_rel_vendas_lista(p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v.ID AS id, to_char(v.DATA_CADASTRO, 'DD/MM/YYYY HH24:MI') AS data_hora, v.TIPO_PEDIDO AS tipo,
           v.MESA AS mesa, COALESCE(cl.NOME, '') AS cliente, u.NOME AS atendente, v.FORMA_PAGAMENTO AS forma,
           v.VALOR AS valor, v.DESCONTO AS desconto, v.TOTAL AS total, v.STATUS AS status, v.ETAPA AS etapa,
           (SELECT string_agg(CONCAT(vi.QUANTIDADE, 'x ', p.NOME), ', ' ORDER BY fn_ord(p.NOME))
              FROM VENDA_ITEM vi JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID WHERE vi.VENDA_ID = v.ID) AS itens
    FROM VENDA v
    LEFT JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    WHERE v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < (p_ate + (1) * INTERVAL '1 day')
    ORDER BY v.DATA_CADASTRO, v.ID) _t;
END
$fn$;

-- ==========================================================================
-- 7. VISÃO GERAL (dashboard)
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_dash_resumo;
CREATE OR REPLACE FUNCTION sp_dash_resumo()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_limite integer DEFAULT CAST(fn_config(0, 'tempo_preparo', '20') AS integer);
BEGIN


    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT
        (SELECT COALESCE(SUM(TOTAL), 0) FROM VENDA
         WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= CURRENT_DATE) AS faturamento_hoje,
        (SELECT COALESCE(SUM(TOTAL), 0) FROM VENDA
         WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= CURRENT_DATE - (1) * INTERVAL '1 day'
           AND DATA_CADASTRO < localtimestamp(0) - (1) * INTERVAL '1 day') AS faturamento_ontem_ate_agora,
        (SELECT COALESCE(SUM(TOTAL), 0) FROM VENDA
         WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= CURRENT_DATE - (1) * INTERVAL '1 day' AND DATA_CADASTRO < CURRENT_DATE) AS faturamento_ontem,
        (SELECT COUNT(*) FROM VENDA WHERE STATUS <> 'CANCELADA' AND DATA_CADASTRO >= CURRENT_DATE) AS pedidos_hoje,
        (SELECT COUNT(*) FROM VENDA WHERE STATUS <> 'CANCELADA' AND DATA_CADASTRO >= CURRENT_DATE - (1) * INTERVAL '1 day'
           AND DATA_CADASTRO < localtimestamp(0) - (1) * INTERVAL '1 day') AS pedidos_ontem_ate_agora,
        (SELECT COUNT(*) FROM VENDA WHERE STATUS <> 'CANCELADA' AND DATA_CADASTRO >= localtimestamp(0) - (1) * INTERVAL '1 hour') AS pedidos_ultima_hora,
        (SELECT AVG(TOTAL) FROM VENDA WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= CURRENT_DATE) AS ticket_hoje,
        (SELECT AVG(TOTAL) FROM VENDA WHERE STATUS = 'FINALIZADA'
           AND DATA_CADASTRO >= CURRENT_DATE - (30) * INTERVAL '1 day' AND DATA_CADASTRO < CURRENT_DATE) AS ticket_30dias,
        (SELECT COUNT(*) FROM VENDA WHERE ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')) AS em_andamento,
        (SELECT COUNT(*) FROM VENDA WHERE ETAPA IN ('NOVO', 'PREPARO')
           AND DATA_CADASTRO < localtimestamp(0) - (v_limite) * INTERVAL '1 minute') AS atrasados,
        (SELECT COUNT(*) FROM VENDA WHERE ETAPA = 'PRONTO' AND TIPO_PEDIDO = 'DELIVERY') AS em_entrega,
        (SELECT COUNT(*) FROM VENDA WHERE STATUS = 'CANCELADA' AND DATA_CADASTRO >= CURRENT_DATE) AS cancelados_hoje,
        (SELECT COUNT(DISTINCT MESA) FROM VENDA WHERE TIPO_PEDIDO = 'SALAO' AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')) AS mesas_ocupadas,
        (SELECT COUNT(*) FROM MESA WHERE STATUS = 'ATIVO') AS mesas_total,
        v_limite AS tempo_preparo) _t;
END
$fn$;

-- Faturamento por hora: hoje x mesmo dia da semana passada.
DROP FUNCTION IF EXISTS sp_dash_horas;
CREATE OR REPLACE FUNCTION sp_dash_horas()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT (CASE WHEN (DATA_CADASTRO)::date = CURRENT_DATE THEN 'HOJE' ELSE 'SEMANA_PASSADA' END) AS dia, EXTRACT(HOUR FROM DATA_CADASTRO)::int AS hora,
           COUNT(*) AS pedidos, SUM(TOTAL) AS total
    FROM VENDA
    WHERE STATUS = 'FINALIZADA'
      AND (DATA_CADASTRO >= CURRENT_DATE
           OR (DATA_CADASTRO >= CURRENT_DATE - (7) * INTERVAL '1 day' AND DATA_CADASTRO < CURRENT_DATE - (6) * INTERVAL '1 day'))
    GROUP BY dia, hora) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_dash_semana;
CREATE OR REPLACE FUNCTION sp_dash_semana()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT (DATA_CADASTRO)::date AS dia, COUNT(*) AS pedidos, COALESCE(SUM(CASE WHEN STATUS = 'FINALIZADA' THEN TOTAL END), 0) AS faturamento
    FROM VENDA
    WHERE STATUS <> 'CANCELADA' AND DATA_CADASTRO >= CURRENT_DATE - (13) * INTERVAL '1 day'
    GROUP BY (DATA_CADASTRO)::date
    ORDER BY dia) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_dash_etapas;
CREATE OR REPLACE FUNCTION sp_dash_etapas()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT
        COALESCE(SUM((ETAPA = 'NOVO')::int), 0) AS novos,
        COALESCE(SUM((ETAPA = 'PREPARO')::int), 0) AS preparo,
        COALESCE(SUM((ETAPA = 'PRONTO')::int), 0) AS prontos,
        (SELECT COUNT(*) FROM VENDA WHERE ETAPA = 'ENTREGUE' AND DATA_ETAPA >= CURRENT_DATE) AS entregues
    FROM VENDA
    WHERE ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_dash_ultimos;
CREATE OR REPLACE FUNCTION sp_dash_ultimos(p_limite integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v.ID AS id, v.TOTAL AS total, v.ETAPA AS etapa, v.TIPO_PEDIDO AS tipo, v.MESA AS mesa,
           cl.NOME AS cliente, to_char(v.DATA_CADASTRO, 'HH24:MI') AS hora,
           (SELECT string_agg(CONCAT(vi.QUANTIDADE, 'x ', p.NOME), ', ' ORDER BY fn_ord(p.NOME))
              FROM VENDA_ITEM vi JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID WHERE vi.VENDA_ID = v.ID) AS itens
    FROM VENDA v
    LEFT JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    ORDER BY v.DATA_CADASTRO DESC, v.ID DESC
    LIMIT p_limite) _t;
END
$fn$;

-- Receitas x despesas do mês corrente (Fluxo de Caixa).
DROP FUNCTION IF EXISTS sp_dash_mes;
CREATE OR REPLACE FUNCTION sp_dash_mes()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_inicio date DEFAULT to_char(CURRENT_DATE, 'YYYY-MM-01');
BEGIN


    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT
        COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' AND status = 'PAGO' AND pagamento >= v_inicio THEN total END), 0) AS receitas,
        COALESCE(SUM(CASE WHEN tipo = 'SAIDA' AND status = 'PAGO' AND pagamento >= v_inicio THEN total END), 0) AS despesas,
        COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' AND situacao IN ('PENDENTE', 'ATRASADO') THEN total END), 0) AS a_receber,
        COALESCE(SUM(CASE WHEN tipo = 'SAIDA' AND situacao IN ('PENDENTE', 'ATRASADO') THEN total END), 0) AS a_pagar,
        COALESCE(SUM(CASE WHEN tipo = 'SAIDA' AND situacao = 'ATRASADO' THEN total END), 0) AS vencido
    FROM vw_fluxo_caixa) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_dash_alertas_estoque;
CREATE OR REPLACE FUNCTION sp_dash_alertas_estoque(p_dias integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT p.ID AS id, p.NOME AS nome, p.ESTOQUE AS estoque, p.ESTOQUE_MINIMO AS minimo, p.UNIDADE_MEDIDA AS unidade,
           p.DATA_VALIDADE AS validade, ((p.DATA_VALIDADE)::date - (CURRENT_DATE)::date) AS dias,
           fn_produto_situacao(p.STATUS, p.ESTOQUE, p.ESTOQUE_MINIMO, p.DATA_VALIDADE, p_dias) AS situacao
    FROM PRODUTO p
    WHERE p.STATUS = 'ATIVO'
      AND fn_produto_situacao(p.STATUS, p.ESTOQUE, p.ESTOQUE_MINIMO, p.DATA_VALIDADE, p_dias) IN ('VENCIDO', 'CRITICO', 'VENCENDO')
    ORDER BY array_position(ARRAY['VENCIDO', 'CRITICO', 'VENCENDO'], fn_produto_situacao(p.STATUS, p.ESTOQUE, p.ESTOQUE_MINIMO, p.DATA_VALIDADE, p_dias)), fn_ord(p.NOME)
    LIMIT 30) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_dash_equipe_online;
CREATE OR REPLACE FUNCTION sp_dash_equipe_online()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT NOME AS nome, COALESCE(CARGO, 'FUNCIONARIO') AS cargo, NIVEL AS nivel,
           FLOOR(EXTRACT(EPOCH FROM ((localtimestamp(0)) - (COALESCE(ULTIMO_ACESSO, ULTIMO_LOGIN)))) / 60)::int AS minutos
    FROM USUARIOS
    WHERE ATIVO = 'ATIVO' AND COALESCE(ULTIMO_ACESSO, ULTIMO_LOGIN) >= localtimestamp(0) - (15) * INTERVAL '1 minute'
    ORDER BY fn_ord(NOME)) _t;
END
$fn$;

-- ==========================================================================
-- 8. NOTIFICAÇÕES (calculadas na hora, respeitando as preferências do usuário)
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_notificacoes;
CREATE OR REPLACE FUNCTION sp_notificacoes(p_usuario_id integer, p_estoque smallint, p_financeiro smallint)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_pedido smallint DEFAULT ((fn_config(p_usuario_id, 'notif_pedido', '1') = '1')::int);
    v_minimo smallint DEFAULT ((fn_config(p_usuario_id, 'notif_minimo', '1') = '1' AND p_estoque = 1)::int);
    v_validade smallint DEFAULT ((fn_config(p_usuario_id, 'notif_validade', '1') = '1' AND p_estoque = 1)::int);
    v_contas smallint DEFAULT ((fn_config(p_usuario_id, 'notif_contas', '0') = '1' AND p_financeiro = 1)::int);
    v_dias integer DEFAULT CAST(fn_config(p_usuario_id, 'dias_validade', '3') AS integer);
    v_limite integer DEFAULT CAST(fn_config(0, 'tempo_preparo', '20') AS integer);
BEGIN







    RETURN QUERY SELECT to_jsonb(_t) FROM (
    (SELECT 'PEDIDO' AS tipo, 'alerta' AS nivel, CONCAT('Pedido #', ID, ' atrasado') AS titulo,
            CONCAT('Há ', FLOOR(EXTRACT(EPOCH FROM ((localtimestamp(0)) - (DATA_CADASTRO))) / 60)::int, ' min · ', (CASE WHEN ETAPA = 'NOVO' THEN 'aguardando aceite' ELSE 'em preparo' END)) AS detalhe,
            'pedidos' AS link, DATA_CADASTRO AS data
     FROM VENDA
     WHERE v_pedido = 1 AND ETAPA IN ('NOVO', 'PREPARO') AND DATA_CADASTRO < localtimestamp(0) - (v_limite) * INTERVAL '1 minute'
     ORDER BY DATA_CADASTRO LIMIT 10)
    UNION ALL
    (SELECT 'PEDIDO', 'info', CONCAT('Novo pedido #', ID),
            CASE TIPO_PEDIDO WHEN 'SALAO' THEN CONCAT('Mesa ', MESA) WHEN 'DELIVERY' THEN 'Delivery' ELSE 'Balcão' END,
            'pedidos', DATA_CADASTRO
     FROM VENDA
     WHERE v_pedido = 1 AND ETAPA = 'NOVO' AND DATA_CADASTRO >= localtimestamp(0) - (v_limite) * INTERVAL '1 minute'
     ORDER BY DATA_CADASTRO DESC LIMIT 10)
    UNION ALL
    (SELECT 'ESTOQUE', 'alerta', CONCAT(NOME, ' abaixo do mínimo'),
            CONCAT(ESTOQUE, ' ', UNIDADE_MEDIDA, ' em estoque · mínimo ', ESTOQUE_MINIMO),
            'estoque?situacao=CRITICO', localtimestamp(0)
     FROM PRODUTO
     WHERE v_minimo = 1 AND STATUS = 'ATIVO' AND ESTOQUE_MINIMO > 0 AND ESTOQUE <= ESTOQUE_MINIMO
     ORDER BY ESTOQUE::numeric / GREATEST(ESTOQUE_MINIMO, 1) LIMIT 10)
    UNION ALL
    (SELECT 'VALIDADE', (CASE WHEN DATA_VALIDADE < CURRENT_DATE THEN 'alerta' ELSE 'aviso' END),
            (CASE WHEN DATA_VALIDADE < CURRENT_DATE THEN CONCAT(NOME, ' vencido') ELSE CONCAT(NOME, ' vence em breve') END),
            CONCAT('Validade ', to_char(DATA_VALIDADE, 'DD/MM/YYYY')),
            'estoque?situacao=VENCENDO', localtimestamp(0)
     FROM PRODUTO
     WHERE v_validade = 1 AND STATUS = 'ATIVO' AND DATA_VALIDADE IS NOT NULL
       AND DATA_VALIDADE <= CURRENT_DATE + (v_dias) * INTERVAL '1 day'
     ORDER BY DATA_VALIDADE LIMIT 10)
    UNION ALL
    (SELECT 'CONTA', (CASE WHEN f.vencimento < CURRENT_DATE THEN 'alerta' ELSE 'aviso' END),
            (CASE WHEN f.vencimento < CURRENT_DATE THEN CONCAT('Conta vencida: ', f.contraparte) ELSE CONCAT('Conta vence ', (CASE WHEN f.vencimento = CURRENT_DATE THEN 'hoje' ELSE to_char(f.vencimento, 'DD/MM') END), ': ', f.contraparte) END),
            CONCAT('R$ ', fn_brl(f.total)),
            'fluxo_de_caixa?tipo=SAIDA&situacao=PENDENTE', localtimestamp(0)
     FROM vw_fluxo_caixa f
     WHERE v_contas = 1 AND f.tipo = 'SAIDA' AND f.situacao IN ('PENDENTE', 'ATRASADO') AND f.vencimento <= CURRENT_DATE + (3) * INTERVAL '1 day'
     ORDER BY f.vencimento LIMIT 10)
    ) _t;
END
$fn$;

-- ==========================================================================
-- 9. BUSCA GLOBAL (Ctrl + K)
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_busca_global;
CREATE OR REPLACE FUNCTION sp_busca_global(p_termo varchar, p_financeiro smallint)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_termo, '')), '%');
    v_digitos varchar DEFAULT fn_somente_digitos(p_termo);
BEGIN



    RETURN QUERY SELECT to_jsonb(_t) FROM (
    (SELECT 'CLIENTE' AS tipo, c.ID AS id, c.NOME AS titulo, CONCAT(ci.NOME, ' · ', c.TELEFONE) AS detalhe, '' AS extra
     FROM CLIENTE c JOIN CIDADE ci ON ci.ID = c.CIDADE_ID
     WHERE fn_busca(c.NOME, v_texto) OR (v_digitos <> '' AND CHAR_LENGTH(v_digitos) >= 4 AND (fn_busca(c.TELEFONE, CONCAT('%', v_digitos, '%')) OR fn_busca(c.DOCUMENTO, CONCAT('%', v_digitos, '%'))))
     ORDER BY fn_ord(c.NOME) LIMIT 5)
    UNION ALL
    (SELECT 'PRODUTO', p.ID, p.NOME, CONCAT(cat.NOME, ' · R$ ', fn_brl(p.PRECO_UNITARIO)), cat.TIPO
     FROM PRODUTO p JOIN PRODUTO_CATEGORIA cat ON cat.ID = p.CATEGORIA_ID
     WHERE fn_busca(p.NOME, v_texto) OR (v_digitos <> '' AND CHAR_LENGTH(v_digitos) >= 4 AND fn_busca(p.CODIGO_BARRAS, CONCAT('%', v_digitos, '%')))
     ORDER BY fn_ord(p.NOME) LIMIT 6)
    UNION ALL
    (SELECT 'PEDIDO', v.ID, CONCAT('Pedido #', v.ID),
            CONCAT(CASE v.ETAPA WHEN 'NOVO' THEN 'Novo' WHEN 'PREPARO' THEN 'Em preparo' WHEN 'PRONTO' THEN 'Pronto'
                                WHEN 'ENTREGUE' THEN 'Entregue' ELSE 'Cancelado' END,
                   ' · R$ ', fn_brl(v.TOTAL), ' · ', to_char(v.DATA_CADASTRO, 'DD/MM HH24:MI')),
            v.ETAPA
     FROM VENDA v
     WHERE v_digitos <> '' AND CHAR_LENGTH(v_digitos) <= 9 AND v.ID = CAST(v_digitos AS integer)
     LIMIT 1)
    UNION ALL
    (SELECT 'FORNECEDOR', f.ID, f.NOME, CONCAT('Fornecedor · ', f.TELEFONE), ''
     FROM FORNECEDOR f
     WHERE p_financeiro = 1 AND fn_busca(f.NOME, v_texto)
     ORDER BY fn_ord(f.NOME) LIMIT 4)
    ) _t;
END
$fn$;

-- ==========================================================================
-- 10. AUDITORIA
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_atividade_registrar;
CREATE OR REPLACE FUNCTION sp_atividade_registrar(p_usuario_id integer, p_usuario_nome varchar, p_modulo varchar, p_acao varchar, p_descricao varchar, p_ip varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    INSERT INTO LOG_ATIVIDADE (USUARIO_ID, USUARIO_NOME, MODULO, ACAO, DESCRICAO, IP)
    VALUES (NULLIF(p_usuario_id, 0), LEFT(COALESCE(p_usuario_nome, ''), 255), LEFT(p_modulo, 40),
            LEFT(COALESCE(p_acao, ''), 40), LEFT(p_descricao, 500), LEFT(COALESCE(p_ip, ''), 45));
END
$fn$;

DROP FUNCTION IF EXISTS sp_atividade_listar;
CREATE OR REPLACE FUNCTION sp_atividade_listar(p_usuario_id integer, p_modulo varchar, p_busca varchar, p_de date, p_ate date, p_limite integer, p_deslocamento integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
BEGIN


    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT l.ID AS id, to_char(l.DATA, 'DD/MM/YYYY') AS data, to_char(l.DATA, 'HH24:MI:SS') AS hora,
           l.USUARIO_ID AS usuario_id, l.USUARIO_NOME AS usuario, l.MODULO AS modulo, l.ACAO AS acao,
           l.DESCRICAO AS descricao, l.IP AS ip
    FROM LOG_ATIVIDADE l
    WHERE l.DATA >= p_de AND l.DATA < (p_ate + (1) * INTERVAL '1 day')
      AND (p_usuario_id = 0 OR l.USUARIO_ID = p_usuario_id)
      AND (TRIM(COALESCE(p_modulo, '')) = '' OR l.MODULO = p_modulo)
      AND (TRIM(COALESCE(p_busca, '')) = '' OR fn_busca(l.DESCRICAO, v_texto) OR fn_busca(l.USUARIO_NOME, v_texto))
    ORDER BY l.ID DESC
    LIMIT p_limite OFFSET p_deslocamento) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_atividade_contagem;
CREATE OR REPLACE FUNCTION sp_atividade_contagem(p_usuario_id integer, p_modulo varchar, p_busca varchar, p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
BEGIN


    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT COUNT(*) AS total,
           COUNT(DISTINCT l.USUARIO_ID) AS usuarios,
           COALESCE(SUM((l.ACAO = 'login')::int), 0) AS acessos
    FROM LOG_ATIVIDADE l
    WHERE l.DATA >= p_de AND l.DATA < (p_ate + (1) * INTERVAL '1 day')
      AND (p_usuario_id = 0 OR l.USUARIO_ID = p_usuario_id)
      AND (TRIM(COALESCE(p_modulo, '')) = '' OR l.MODULO = p_modulo)
      AND (TRIM(COALESCE(p_busca, '')) = '' OR fn_busca(l.DESCRICAO, v_texto) OR fn_busca(l.USUARIO_NOME, v_texto))) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_atividade_modulos;
CREATE OR REPLACE FUNCTION sp_atividade_modulos()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT DISTINCT MODULO AS modulo FROM LOG_ATIVIDADE ORDER BY MODULO) _t;
END
$fn$;

-- ==========================================================================
-- 11. LOGIN SEGURO
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_login_buscar;
CREATE OR REPLACE FUNCTION sp_login_buscar(p_email varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT ID AS id, NOME AS nome, EMAIL AS email, SENHA AS senha, NIVEL AS nivel,
           COALESCE(CARGO, 'FUNCIONARIO') AS cargo, ATIVO AS ativo
    FROM USUARIOS
    WHERE EMAIL = TRIM(p_email)
    LIMIT 1) _t;
END
$fn$;

-- Falhas recentes do e-mail ou do IP (janela de 15 minutos).
DROP FUNCTION IF EXISTS sp_login_falhas;
CREATE OR REPLACE FUNCTION sp_login_falhas(p_email varchar, p_ip varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT
        (SELECT COUNT(*) FROM LOGIN_TENTATIVA
         WHERE EMAIL = TRIM(p_email) AND SUCESSO = 0 AND DATA >= localtimestamp(0) - (15) * INTERVAL '1 minute') AS falhas_email,
        (SELECT COUNT(*) FROM LOGIN_TENTATIVA
         WHERE IP = p_ip AND SUCESSO = 0 AND DATA >= localtimestamp(0) - (15) * INTERVAL '1 minute') AS falhas_ip) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_login_registrar;
CREATE OR REPLACE FUNCTION sp_login_registrar(p_email varchar, p_ip varchar, p_sucesso smallint)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    INSERT INTO LOGIN_TENTATIVA (EMAIL, IP, SUCESSO) VALUES (LEFT(TRIM(p_email), 255), LEFT(p_ip, 45), (CASE WHEN p_sucesso = 1 THEN 1 ELSE 0 END));

    -- acesso certo zera o contador desse e-mail
    IF p_sucesso = 1 THEN
        DELETE FROM LOGIN_TENTATIVA WHERE EMAIL = TRIM(p_email) AND SUCESSO = 0;
    END IF;
    DELETE FROM LOGIN_TENTATIVA WHERE DATA < localtimestamp(0) - (30) * INTERVAL '1 day';
END
$fn$;

-- Registra o acesso e, se veio um hash novo (senha antiga em texto puro ou custo
-- desatualizado), troca a senha gravada pelo hash seguro.
DROP FUNCTION IF EXISTS sp_login_sucesso;
CREATE OR REPLACE FUNCTION sp_login_sucesso(p_usuario_id integer, p_novo_hash varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    UPDATE USUARIOS
    SET ULTIMO_LOGIN = localtimestamp(0), ULTIMO_ACESSO = localtimestamp(0),
        SENHA = (CASE WHEN COALESCE(p_novo_hash, '') = '' THEN SENHA ELSE p_novo_hash END)
    WHERE ID = p_usuario_id;
END
$fn$;

-- Chamado a cada página (no máximo 1x por minuto): mantém a "equipe online" e
-- devolve os dados atuais do usuário, para a sessão refletir mudanças de cargo.
DROP FUNCTION IF EXISTS sp_usuario_acesso;
CREATE OR REPLACE FUNCTION sp_usuario_acesso(p_usuario_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    UPDATE USUARIOS SET ULTIMO_ACESSO = localtimestamp(0) WHERE ID = p_usuario_id;
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT NOME AS nome, NIVEL AS nivel, COALESCE(CARGO, 'FUNCIONARIO') AS cargo, ATIVO AS ativo
    FROM USUARIOS WHERE ID = p_usuario_id) _t;
END
$fn$;

-- ==========================================================================
-- 12. CONVITES DA EQUIPE
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_convite_criar;
CREATE OR REPLACE FUNCTION sp_convite_criar(p_codigo varchar, p_cargo varchar, p_nivel varchar, p_criado_por integer, p_dias integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF p_cargo NOT IN ('Atendente / Caixa', 'Cozinheiro', 'Entregador', 'Gerente') THEN
        RAISE EXCEPTION '%', 'Cargo inválido.';
    END IF;
    IF p_nivel NOT IN ('ADMIN', 'FUNCIONARIO') THEN
        RAISE EXCEPTION '%', 'Nível de acesso inválido.';
    END IF;
    IF p_dias IS NULL OR p_dias < 1 OR p_dias > 30 THEN
        p_dias := 7;
    END IF;

    INSERT INTO convites_equipe (id_empresa, codigo, cargo, nivel_acesso, nivel_sistema, status, expira_em, criado_por)
    VALUES (1, p_codigo, p_cargo, (CASE WHEN p_nivel = 'ADMIN' THEN 3 ELSE 1 END), p_nivel, 'pendente',
            localtimestamp(0) + (p_dias) * INTERVAL '1 day', NULLIF(p_criado_por, 0));

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT lastval() AS id, p_codigo AS codigo, to_char(localtimestamp(0) + (p_dias) * INTERVAL '1 day', 'DD/MM/YYYY') AS expira) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_convite_listar;
CREATE OR REPLACE FUNCTION sp_convite_listar()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT c.id, c.codigo, c.cargo, c.nivel_sistema AS nivel, c.status,
           to_char(c.data_criacao, 'DD/MM/YYYY') AS criado_em,
           to_char(c.expira_em, 'DD/MM/YYYY') AS expira_em,
           (c.status = 'pendente' AND c.expira_em < localtimestamp(0)) AS expirado,
           u.NOME AS criado_por, us.NOME AS usado_por
    FROM convites_equipe c
    LEFT JOIN USUARIOS u ON u.ID = c.criado_por
    LEFT JOIN USUARIOS us ON us.ID = c.usado_por
    ORDER BY c.data_criacao DESC
    LIMIT 15) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_convite_revogar;
CREATE OR REPLACE FUNCTION sp_convite_revogar(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM convites_equipe WHERE id = p_id AND status = 'pendente') THEN
        RAISE EXCEPTION '%', 'Convite não encontrado ou já utilizado.';
    END IF;

    UPDATE convites_equipe SET status = 'revogado' WHERE id = p_id;
END
$fn$;

-- Cadastro do funcionário com o código do convite. Devolve o id do novo usuário.
DROP FUNCTION IF EXISTS sp_convite_usar;
CREATE OR REPLACE FUNCTION sp_convite_usar(p_codigo varchar, p_nome varchar, p_email varchar, p_senha_hash varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_convite integer DEFAULT NULL;
    v_cargo varchar DEFAULT NULL;
    v_nivel varchar DEFAULT NULL;
    v_usuario integer DEFAULT NULL;
BEGIN





    /* (transação implícita) */

    IF TRIM(COALESCE(p_nome, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe o seu nome completo.';
    END IF;
    IF TRIM(COALESCE(p_email, '')) = '' OR p_email NOT ILIKE '%_@_%.__%' THEN
        RAISE EXCEPTION '%', 'Informe um e-mail válido.';
    END IF;

    

    SELECT id, cargo, nivel_sistema INTO v_convite, v_cargo, v_nivel
    FROM convites_equipe
    WHERE codigo = UPPER(TRIM(p_codigo)) AND status = 'pendente' AND (expira_em IS NULL OR expira_em >= localtimestamp(0))
    LIMIT 1
    FOR UPDATE;

    IF v_convite IS NULL THEN
        RAISE EXCEPTION '%', 'Código de convite inválido, expirado ou já utilizado.';
    END IF;
    IF EXISTS (SELECT 1 FROM USUARIOS WHERE EMAIL = TRIM(p_email)) THEN
        RAISE EXCEPTION '%', 'Já existe uma conta com esse e-mail.';
    END IF;

    INSERT INTO USUARIOS (NOME, EMAIL, SENHA, NIVEL, CARGO, ATIVO)
    VALUES (TRIM(p_nome), LOWER(TRIM(p_email)), p_senha_hash, v_nivel, v_cargo, 'ATIVO');
    v_usuario := lastval();

    UPDATE convites_equipe SET status = 'usado', usado_por = v_usuario WHERE id = v_convite;

    

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v_usuario AS id, v_cargo AS cargo) _t;
END
$fn$;

-- Para cada item do cardápio, vendas nas duas metades da janela (2 x p_janela
-- dias) e as somas da regressão linear (x = dia, 0 = mais antigo; y = unidades).
DROP FUNCTION IF EXISTS sp_prev_produtos;
CREATE OR REPLACE FUNCTION sp_prev_produtos(p_janela integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (WITH RECURSIVE dias AS (
        SELECT 0 AS n
        UNION ALL
        SELECT n + 1 FROM dias WHERE n < p_janela * 2 - 1
    ),
    calendario AS (
        SELECT ((CURRENT_DATE - (n + 1) * INTERVAL '1 day'))::date AS dia, p_janela * 2 - 1 - n AS x FROM dias
    ),
    vendido AS (
        SELECT vi.PRODUTO_ID AS pid, (v.DATA_CADASTRO)::date AS dia, SUM(vi.QUANTIDADE) AS q, SUM(vi.TOTAL) AS r
        FROM VENDA_ITEM vi
        JOIN VENDA v ON v.ID = vi.VENDA_ID
        WHERE v.STATUS = 'FINALIZADA'
          AND v.DATA_CADASTRO >= CURRENT_DATE - (p_janela * 2) * INTERVAL '1 day' AND v.DATA_CADASTRO < CURRENT_DATE
        GROUP BY vi.PRODUTO_ID, (v.DATA_CADASTRO)::date
    ),
    base AS (
        SELECT p.ID AS pid, p.NOME AS nome, c.NOME AS categoria, p.PRECO_UNITARIO AS preco,
               cal.x, COALESCE(vd.q, 0) AS q, COALESCE(vd.r, 0) AS r
        FROM PRODUTO p
        JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
        CROSS JOIN calendario cal
        LEFT JOIN vendido vd ON vd.pid = p.ID AND vd.dia = cal.dia
        WHERE c.TIPO = 'CARDAPIO' AND p.STATUS = 'ATIVO'
    )
    SELECT pid AS id, nome, categoria, preco,
           SUM(CASE WHEN x >= p_janela THEN q ELSE 0 END) AS atual,
           SUM(CASE WHEN x < p_janela THEN q ELSE 0 END) AS anterior,
           SUM(CASE WHEN x >= p_janela THEN r ELSE 0 END) AS receita,
           COUNT(*) AS n, SUM(x) AS sx, SUM(q) AS sy, SUM(x * q) AS sxy, SUM(x * x) AS sxx
    FROM base
    GROUP BY pid, nome, categoria, preco
    ORDER BY atual DESC) _t;
END
$fn$;

-- Unidades vendidas por produto e por dia (para os minigráficos).
DROP FUNCTION IF EXISTS sp_prev_serie_produtos;
CREATE OR REPLACE FUNCTION sp_prev_serie_produtos(p_dias integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT vi.PRODUTO_ID AS id, (v.DATA_CADASTRO)::date AS dia, SUM(vi.QUANTIDADE) AS quantidade
    FROM VENDA_ITEM vi
    JOIN VENDA v ON v.ID = vi.VENDA_ID
    WHERE v.STATUS = 'FINALIZADA'
      AND v.DATA_CADASTRO >= CURRENT_DATE - (p_dias) * INTERVAL '1 day' AND v.DATA_CADASTRO < CURRENT_DATE
    GROUP BY vi.PRODUTO_ID, (v.DATA_CADASTRO)::date) _t;
END
$fn$;

-- Pedidos e faturamento de cada dia completo (base da previsão de faturamento).
DROP FUNCTION IF EXISTS sp_prev_faturamento;
CREATE OR REPLACE FUNCTION sp_prev_faturamento(p_dias integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT (DATA_CADASTRO)::date AS dia, COUNT(*) AS pedidos, SUM(TOTAL) AS faturamento
    FROM VENDA
    WHERE STATUS = 'FINALIZADA'
      AND DATA_CADASTRO >= CURRENT_DATE - (p_dias) * INTERVAL '1 day' AND DATA_CADASTRO < CURRENT_DATE
    GROUP BY (DATA_CADASTRO)::date
    ORDER BY dia) _t;
END
$fn$;

-- Consumo diário médio de cada insumo (pela ficha técnica) e dos itens de
-- revenda (pelas próprias vendas), com o estoque atual.
DROP FUNCTION IF EXISTS sp_prev_insumos;
CREATE OR REPLACE FUNCTION sp_prev_insumos(p_dias integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT i.ID AS id, i.NOME AS nome, i.UNIDADE_MEDIDA AS unidade, i.ESTOQUE AS estoque,
           i.ESTOQUE_MINIMO AS minimo, i.PRECO_UNITARIO AS custo, c.NOME AS categoria,
           COALESCE(cons.total, 0)::numeric / p_dias AS consumo_dia
    FROM PRODUTO i
    JOIN PRODUTO_CATEGORIA c ON c.ID = i.CATEGORIA_ID
    LEFT JOIN (
        SELECT f.INSUMO_ID AS pid, SUM(vi.QUANTIDADE * f.QUANTIDADE) AS total
        FROM VENDA_ITEM vi
        JOIN VENDA v ON v.ID = vi.VENDA_ID
        JOIN FICHA_TECNICA f ON f.PRODUTO_ID = vi.PRODUTO_ID
        WHERE v.STATUS = 'FINALIZADA' AND v.DATA_CADASTRO >= CURRENT_DATE - (p_dias) * INTERVAL '1 day' AND v.DATA_CADASTRO < CURRENT_DATE
        GROUP BY f.INSUMO_ID
    ) cons ON cons.pid = i.ID
    WHERE i.STATUS = 'ATIVO' AND c.TIPO = 'INSUMO'
    UNION ALL
    SELECT p.ID, p.NOME, p.UNIDADE_MEDIDA, p.ESTOQUE, p.ESTOQUE_MINIMO,
           COALESCE((SELECT MIN(PRECO_FORNECEDOR) FROM FORNECEDOR_PRODUTO fp WHERE fp.PRODUTO_ID = p.ID), 0),
           c.NOME,
           COALESCE((SELECT SUM(vi.QUANTIDADE) FROM VENDA_ITEM vi JOIN VENDA v ON v.ID = vi.VENDA_ID
                     WHERE vi.PRODUTO_ID = p.ID AND v.STATUS = 'FINALIZADA'
                       AND v.DATA_CADASTRO >= CURRENT_DATE - (p_dias) * INTERVAL '1 day' AND v.DATA_CADASTRO < CURRENT_DATE), 0)::numeric / p_dias
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE p.STATUS = 'ATIVO' AND c.TIPO = 'CARDAPIO' AND p.CONTROLA_ESTOQUE = 1
      AND NOT EXISTS (SELECT 1 FROM FICHA_TECNICA f WHERE f.PRODUTO_ID = p.ID)) _t;
END
$fn$;

-- Média de pedidos por dia da semana e hora (1 = domingo ... 7 = sábado).
DROP FUNCTION IF EXISTS sp_prev_mapa_calor;
CREATE OR REPLACE FUNCTION sp_prev_mapa_calor(p_semanas integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT (EXTRACT(DOW FROM DATA_CADASTRO)::int + 1) AS dia, EXTRACT(HOUR FROM DATA_CADASTRO)::int AS hora, COUNT(*)::numeric / p_semanas AS media
    FROM VENDA
    WHERE STATUS = 'FINALIZADA'
      AND DATA_CADASTRO >= CURRENT_DATE - (p_semanas * 7) * INTERVAL '1 day' AND DATA_CADASTRO < CURRENT_DATE
    GROUP BY (EXTRACT(DOW FROM DATA_CADASTRO)::int + 1), EXTRACT(HOUR FROM DATA_CADASTRO)::int) _t;
END
$fn$;
