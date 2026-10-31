-- ==========================================================================
-- FUNÇÕES E PROCEDURES DOS MÓDULOS FINAIS
--
-- Ficha técnica e baixa automática de estoque, PDV, Mesas, Fornecedores,
-- Compras, Relatórios, Visão Geral, Notificações, Busca global, Auditoria,
-- Login seguro e Convites da equipe.
--
-- Ordem de execução (ou use o instalador em /instalar.php):
--   1. bancofeitonamoraltmjamovcs.sql
--   2. migracao_dashboard.sql
--   3. migracao_crud.sql
--   4. migracao_modulos.sql
--   5. procedures_crud.sql
--   6. procedures_modulos.sql   (este arquivo)
--   7. dados_demonstracao.sql   (opcional)
--
-- Mesmas convenções de procedures_crud.sql: fn_* funções, sp_* procedures,
-- SIGNAL SQLSTATE '45000' para mensagens de regra de negócio.
-- ==========================================================================

USE tcc;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //

-- ==========================================================================
-- 1. FICHA TÉCNICA, CUSTO E BAIXA AUTOMÁTICA
-- ==========================================================================

-- Custo estimado de uma unidade do item: soma dos insumos da ficha técnica
-- ou, para itens de revenda, o menor preço de fornecedor. NULL se desconhecido.
DROP FUNCTION IF EXISTS fn_produto_custo//
CREATE FUNCTION fn_produto_custo(p_produto_id INT)
RETURNS DECIMAL(12, 2)
READS SQL DATA
BEGIN
    DECLARE v_custo DECIMAL(12, 2) DEFAULT NULL;

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
END//

-- Quantas unidades do item ainda dá para produzir com o estoque atual.
-- NULL quando o item não tem ficha técnica nem controla o próprio estoque.
DROP FUNCTION IF EXISTS fn_produto_produziveis//
CREATE FUNCTION fn_produto_produziveis(p_produto_id INT)
RETURNS INT
READS SQL DATA
BEGIN
    DECLARE v_qtd INT DEFAULT NULL;
    DECLARE v_controla TINYINT DEFAULT 0;

    IF EXISTS (SELECT 1 FROM FICHA_TECNICA WHERE PRODUTO_ID = p_produto_id) THEN
        SELECT MIN(IF(i.STATUS = 'ATIVO', FLOOR(i.ESTOQUE / GREATEST(f.QUANTIDADE, 1)), 0)) INTO v_qtd
        FROM FICHA_TECNICA f
        JOIN PRODUTO i ON i.ID = f.INSUMO_ID
        WHERE f.PRODUTO_ID = p_produto_id;
        RETURN v_qtd;
    END IF;

    SELECT CONTROLA_ESTOQUE, ESTOQUE INTO v_controla, v_qtd FROM PRODUTO WHERE ID = p_produto_id;
    RETURN IF(v_controla = 1, v_qtd, NULL);
END//

DROP PROCEDURE IF EXISTS sp_ficha_listar//
CREATE PROCEDURE sp_ficha_listar(IN p_produto_id INT)
BEGIN
    SELECT f.INSUMO_ID AS insumo_id, i.NOME AS nome, f.QUANTIDADE AS quantidade, i.UNIDADE_MEDIDA AS unidade,
           i.PRECO_UNITARIO AS custo_unitario, f.QUANTIDADE * i.PRECO_UNITARIO AS custo, i.ESTOQUE AS estoque
    FROM FICHA_TECNICA f
    JOIN PRODUTO i ON i.ID = f.INSUMO_ID
    WHERE f.PRODUTO_ID = p_produto_id
    ORDER BY i.NOME;
END//

-- Insumos ativos que podem entrar em uma ficha técnica.
DROP PROCEDURE IF EXISTS sp_ficha_insumos//
CREATE PROCEDURE sp_ficha_insumos()
BEGIN
    SELECT p.ID AS id, p.NOME AS nome, p.UNIDADE_MEDIDA AS unidade, p.PRECO_UNITARIO AS preco, c.NOME AS categoria
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE p.STATUS = 'ATIVO' AND c.TIPO = 'INSUMO'
    ORDER BY c.NOME, p.NOME;
END//

DROP PROCEDURE IF EXISTS sp_ficha_limpar//
CREATE PROCEDURE sp_ficha_limpar(IN p_produto_id INT)
BEGIN
    DELETE FROM FICHA_TECNICA WHERE PRODUTO_ID = p_produto_id;
END//

DROP PROCEDURE IF EXISTS sp_ficha_adicionar//
CREATE PROCEDURE sp_ficha_adicionar(IN p_produto_id INT, IN p_insumo_id INT, IN p_quantidade INT)
BEGIN
    IF p_quantidade IS NULL OR p_quantidade < 1 OR p_quantidade > 9999 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a quantidade de cada insumo da ficha técnica (1 a 9999).';
    END IF;
    IF p_produto_id = p_insumo_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Um item não pode ser insumo dele mesmo.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM PRODUTO p JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
        WHERE p.ID = p_insumo_id AND c.TIPO = 'INSUMO'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Um dos insumos da ficha técnica não foi encontrado.';
    END IF;

    INSERT INTO FICHA_TECNICA (PRODUTO_ID, INSUMO_ID, QUANTIDADE)
    VALUES (p_produto_id, p_insumo_id, p_quantidade)
    ON DUPLICATE KEY UPDATE QUANTIDADE = LEAST(9999, QUANTIDADE + p_quantidade);
END//

-- Item de revenda (sem ficha técnica) que desconta o próprio estoque ao ser vendido.
DROP PROCEDURE IF EXISTS sp_produto_controle_estoque//
CREATE PROCEDURE sp_produto_controle_estoque(IN p_produto_id INT, IN p_controla TINYINT)
BEGIN
    UPDATE PRODUTO SET CONTROLA_ESTOQUE = IF(p_controla = 1, 1, 0) WHERE ID = p_produto_id;
END//

-- p_sinal = -1 dá baixa nos insumos de um pedido entregue; +1 devolve (estorno).
-- A coluna VENDA.ESTOQUE_BAIXADO impede baixa ou estorno em dobro.
-- Não abre transação: roda dentro da transação de quem chama.
DROP PROCEDURE IF EXISTS sp_estoque_baixa_pedido//
CREATE PROCEDURE sp_estoque_baixa_pedido(IN p_venda_id INT, IN p_sinal INT)
proc: BEGIN
    DECLARE v_baixado TINYINT DEFAULT NULL;
    DECLARE v_usuario INT DEFAULT NULL;

    SELECT ESTOQUE_BAIXADO, FUNCIONARIO_ID INTO v_baixado, v_usuario FROM VENDA WHERE ID = p_venda_id;

    IF v_baixado IS NULL OR (p_sinal < 0 AND v_baixado = 1) OR (p_sinal > 0 AND v_baixado = 0) THEN
        LEAVE proc;
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_xtec_baixa;
    CREATE TEMPORARY TABLE tmp_xtec_baixa (PRODUTO_ID INT PRIMARY KEY, QTD INT NOT NULL) ENGINE = MEMORY;

    INSERT INTO tmp_xtec_baixa (PRODUTO_ID, QTD)
    SELECT x.pid, SUM(x.q)
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
    GROUP BY x.pid;

    UPDATE PRODUTO p
    JOIN tmp_xtec_baixa t ON t.PRODUTO_ID = p.ID
    SET p.ESTOQUE = GREATEST(0, p.ESTOQUE + p_sinal * t.QTD);

    INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, OBSERVACAO)
    SELECT t.PRODUTO_ID, v_usuario, IF(p_sinal < 0, 'SAIDA', 'ENTRADA'), t.QTD,
           CONCAT(IF(p_sinal < 0, 'Baixa automática', 'Estorno'), ' · Pedido #', p_venda_id)
    FROM tmp_xtec_baixa t
    WHERE t.QTD > 0;

    DROP TEMPORARY TABLE IF EXISTS tmp_xtec_baixa;

    UPDATE VENDA SET ESTOQUE_BAIXADO = IF(p_sinal < 0, 1, 0) WHERE ID = p_venda_id;
END//

-- ==========================================================================
-- 2. PDV: venda direta (cria, cobra e entrega na mesma operação)
--    Rode na mesma transação do PHP que chamou sp_pedido_criar/itens/totais.
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_pedido_finalizar//
CREATE PROCEDURE sp_pedido_finalizar(IN p_id INT, IN p_valor_recebido DECIMAL(10, 2))
BEGIN
    DECLARE v_etapa VARCHAR(10) DEFAULT NULL;
    DECLARE v_total DECIMAL(10, 2) DEFAULT 0;
    DECLARE v_forma VARCHAR(10) DEFAULT NULL;

    SELECT ETAPA, TOTAL, FORMA_PAGAMENTO INTO v_etapa, v_total, v_forma FROM VENDA WHERE ID = p_id;

    IF v_etapa IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pedido não encontrado.';
    END IF;
    IF v_etapa NOT IN ('NOVO', 'PREPARO', 'PRONTO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este pedido já foi encerrado.';
    END IF;
    IF v_forma = 'DINHEIRO' AND p_valor_recebido IS NOT NULL AND p_valor_recebido < v_total THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O valor recebido em dinheiro é menor que o total.';
    END IF;

    UPDATE VENDA
    SET ETAPA = 'ENTREGUE', STATUS = 'FINALIZADA', DATA_ETAPA = NOW(),
        VALOR_RECEBIDO = IF(v_forma = 'DINHEIRO', p_valor_recebido, NULL)
    WHERE ID = p_id;
    UPDATE VENDA_ITEM SET STATUS = 'FINALIZADA' WHERE VENDA_ID = p_id;
    UPDATE CONTA_RECEBER
    SET STATUS = 'PAGO', DATA_PAGAMENTO = CURDATE()
    WHERE VENDA_ID = p_id AND STATUS IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO');

    CALL sp_estoque_baixa_pedido(p_id, -1);
END//

-- Dados completos para o comprovante (cupom não fiscal).
DROP PROCEDURE IF EXISTS sp_pedido_comprovante//
CREATE PROCEDURE sp_pedido_comprovante(IN p_id INT)
BEGIN
    SELECT v.ID AS id, v.TIPO_PEDIDO AS tipo, v.MESA AS mesa, cl.NOME AS cliente, cl.TELEFONE AS cliente_telefone,
           CONCAT(cl.ENDERECO, ', ', cl.ENDERECO_NUMERO) AS cliente_endereco,
           v.ETAPA AS etapa, v.STATUS AS status, v.FORMA_PAGAMENTO AS forma_pagamento,
           v.VALOR AS valor, v.DESCONTO AS desconto, v.TOTAL AS total, v.VALOR_RECEBIDO AS valor_recebido,
           v.OBSERVACAO AS observacao, u.NOME AS atendente,
           DATE_FORMAT(v.DATA_CADASTRO, '%d/%m/%Y %H:%i') AS data_hora,
           e.NOME_FANTASIA AS empresa, e.RAZAO_SOCIAL AS razao_social, e.CNPJ AS cnpj, e.TELEFONE AS empresa_telefone,
           CONCAT_WS(', ', NULLIF(e.ENDERECO, ''), NULLIF(e.ENDERECO_NUMERO, ''), NULLIF(e.CIDADE, ''), e.ESTADO) AS empresa_endereco
    FROM VENDA v
    LEFT JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    LEFT JOIN EMPRESA e ON e.ID = 1
    WHERE v.ID = p_id;
END//

-- ==========================================================================
-- 3. MESAS
--    LIVRE = sem pedidos abertos | OCUPADA = há pedido na cozinha
--    CONTA = todos os pedidos abertos já estão prontos (aguardando pagamento)
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_mesa_listar//
CREATE PROCEDURE sp_mesa_listar()
BEGIN
    SELECT m.ID AS id, m.NUMERO AS numero, m.LUGARES AS lugares, m.AREA AS area, m.STATUS AS status,
           COUNT(v.ID) AS pedidos,
           COALESCE(SUM(v.TOTAL), 0) AS total,
           COALESCE(SUM(v.ETAPA IN ('NOVO', 'PREPARO')), 0) AS na_cozinha,
           TIMESTAMPDIFF(MINUTE, MIN(v.DATA_CADASTRO), NOW()) AS minutos,
           CASE
               WHEN m.STATUS = 'INATIVO' THEN 'INATIVA'
               WHEN COUNT(v.ID) = 0 THEN 'LIVRE'
               WHEN SUM(v.ETAPA IN ('NOVO', 'PREPARO')) = 0 THEN 'CONTA'
               ELSE 'OCUPADA'
           END AS situacao
    FROM MESA m
    LEFT JOIN VENDA v ON v.TIPO_PEDIDO = 'SALAO' AND v.MESA = m.NUMERO AND v.ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
    GROUP BY m.ID, m.NUMERO, m.LUGARES, m.AREA, m.STATUS
    ORDER BY m.AREA, CAST(m.NUMERO AS UNSIGNED), m.NUMERO;
END//

DROP PROCEDURE IF EXISTS sp_mesa_obter//
CREATE PROCEDURE sp_mesa_obter(IN p_id INT)
BEGIN
    SELECT ID AS id, NUMERO AS numero, LUGARES AS lugares, AREA AS area, STATUS AS status
    FROM MESA WHERE ID = p_id;
END//

-- Mesas ativas para os formulários de pedido.
DROP PROCEDURE IF EXISTS sp_mesa_opcoes//
CREATE PROCEDURE sp_mesa_opcoes()
BEGIN
    SELECT NUMERO AS numero, AREA AS area, LUGARES AS lugares
    FROM MESA WHERE STATUS = 'ATIVO'
    ORDER BY AREA, CAST(NUMERO AS UNSIGNED), NUMERO;
END//

DROP PROCEDURE IF EXISTS sp_mesa_salvar//
CREATE PROCEDURE sp_mesa_salvar(
    IN p_id INT,
    IN p_numero VARCHAR(20),
    IN p_lugares INT,
    IN p_area VARCHAR(50),
    IN p_status VARCHAR(10)
)
BEGIN
    DECLARE v_id INT DEFAULT COALESCE(p_id, 0);
    DECLARE v_numero VARCHAR(20) DEFAULT TRIM(COALESCE(p_numero, ''));
    DECLARE v_atual VARCHAR(20) DEFAULT NULL;

    IF v_numero = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o número ou nome da mesa.';
    END IF;
    IF p_lugares IS NULL OR p_lugares < 1 OR p_lugares > 30 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A mesa deve ter de 1 a 30 lugares.';
    END IF;
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        SET p_status = 'ATIVO';
    END IF;
    IF EXISTS (SELECT 1 FROM MESA WHERE NUMERO = v_numero AND ID <> v_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe uma mesa com esse número.';
    END IF;

    IF v_id = 0 THEN
        INSERT INTO MESA (NUMERO, LUGARES, AREA, STATUS)
        VALUES (v_numero, p_lugares, COALESCE(NULLIF(TRIM(p_area), ''), 'Salão'), p_status);
        SET v_id = LAST_INSERT_ID();
    ELSE
        SELECT NUMERO INTO v_atual FROM MESA WHERE ID = v_id;
        IF v_atual IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mesa não encontrada.';
        END IF;
        IF (v_atual <> v_numero OR p_status = 'INATIVO') AND EXISTS (
            SELECT 1 FROM VENDA WHERE TIPO_PEDIDO = 'SALAO' AND MESA = v_atual AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Feche a conta da mesa antes de renomeá-la ou desativá-la.';
        END IF;

        UPDATE MESA
        SET NUMERO = v_numero, LUGARES = p_lugares, AREA = COALESCE(NULLIF(TRIM(p_area), ''), 'Salão'), STATUS = p_status
        WHERE ID = v_id;
    END IF;

    SELECT v_id AS id;
END//

DROP PROCEDURE IF EXISTS sp_mesa_excluir//
CREATE PROCEDURE sp_mesa_excluir(IN p_id INT)
BEGIN
    DECLARE v_numero VARCHAR(20) DEFAULT NULL;

    SELECT NUMERO INTO v_numero FROM MESA WHERE ID = p_id;
    IF v_numero IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mesa não encontrada.';
    END IF;
    IF EXISTS (SELECT 1 FROM VENDA WHERE TIPO_PEDIDO = 'SALAO' AND MESA = v_numero AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A mesa tem pedidos em aberto. Feche a conta antes de excluí-la.';
    END IF;

    DELETE FROM MESA WHERE ID = p_id;
END//

-- Pedidos abertos de uma mesa (comanda).
DROP PROCEDURE IF EXISTS sp_mesa_pedidos//
CREATE PROCEDURE sp_mesa_pedidos(IN p_numero VARCHAR(20))
BEGIN
    SELECT v.ID AS id, v.ETAPA AS etapa, v.TOTAL AS total, v.DESCONTO AS desconto, u.NOME AS atendente,
           TIMESTAMPDIFF(MINUTE, v.DATA_CADASTRO, NOW()) AS minutos, DATE_FORMAT(v.DATA_CADASTRO, '%H:%i') AS hora
    FROM VENDA v
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    WHERE v.TIPO_PEDIDO = 'SALAO' AND v.MESA = p_numero AND v.ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
    ORDER BY v.DATA_CADASTRO;
END//

-- Itens somados de todos os pedidos abertos da mesa.
DROP PROCEDURE IF EXISTS sp_mesa_comanda//
CREATE PROCEDURE sp_mesa_comanda(IN p_numero VARCHAR(20))
BEGIN
    SELECT p.NOME AS nome, SUM(vi.QUANTIDADE) AS quantidade, vi.PRECO_UNITARIO AS preco, SUM(vi.TOTAL) AS total
    FROM VENDA v
    JOIN VENDA_ITEM vi ON vi.VENDA_ID = v.ID
    JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID
    WHERE v.TIPO_PEDIDO = 'SALAO' AND v.MESA = p_numero AND v.ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
    GROUP BY vi.PRODUTO_ID, p.NOME, vi.PRECO_UNITARIO
    ORDER BY p.NOME;
END//

-- Fecha a conta: todos os pedidos abertos da mesa são cobrados e entregues.
-- Só é possível quando nenhum pedido está na cozinha. Devolve qtd, total e troco.
DROP PROCEDURE IF EXISTS sp_mesa_fechar_conta//
CREATE PROCEDURE sp_mesa_fechar_conta(IN p_numero VARCHAR(20), IN p_forma VARCHAR(10), IN p_valor_recebido DECIMAL(10, 2))
BEGIN
    DECLARE v_fim INT DEFAULT 0;
    DECLARE v_id INT DEFAULT 0;
    DECLARE v_qtd INT DEFAULT 0;
    DECLARE v_cozinha INT DEFAULT 0;
    DECLARE v_total DECIMAL(10, 2) DEFAULT 0;
    DECLARE c_pedidos CURSOR FOR
        SELECT ID FROM VENDA
        WHERE TIPO_PEDIDO = 'SALAO' AND MESA = p_numero AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO');
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT COUNT(*), COALESCE(SUM(ETAPA IN ('NOVO', 'PREPARO')), 0), COALESCE(SUM(TOTAL), 0)
    INTO v_qtd, v_cozinha, v_total
    FROM VENDA
    WHERE TIPO_PEDIDO = 'SALAO' AND MESA = p_numero AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO');

    IF v_qtd = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Não há pedidos em aberto nesta mesa.';
    END IF;
    IF v_cozinha > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ainda há pedidos desta mesa na cozinha. Marque-os como prontos antes de fechar a conta.';
    END IF;
    IF FIND_IN_SET(p_forma, fn_config(0, 'pagamentos', 'PIX,CREDITO,DEBITO,DINHEIRO')) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forma de pagamento não aceita pela loja.';
    END IF;
    IF p_forma = 'DINHEIRO' AND p_valor_recebido IS NOT NULL AND p_valor_recebido < v_total THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O valor recebido em dinheiro é menor que o total da mesa.';
    END IF;

    START TRANSACTION;

    OPEN c_pedidos;
    leitura: LOOP
        FETCH c_pedidos INTO v_id;
        IF v_fim = 1 THEN
            LEAVE leitura;
        END IF;

        UPDATE VENDA
        SET FORMA_PAGAMENTO = p_forma, ETAPA = 'ENTREGUE', STATUS = 'FINALIZADA', DATA_ETAPA = NOW()
        WHERE ID = v_id;
        UPDATE VENDA_ITEM SET STATUS = 'FINALIZADA' WHERE VENDA_ID = v_id;
        UPDATE CONTA_RECEBER
        SET STATUS = 'PAGO', DATA_PAGAMENTO = CURDATE()
        WHERE VENDA_ID = v_id AND STATUS IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO');
        CALL sp_estoque_baixa_pedido(v_id, -1);
    END LOOP;
    CLOSE c_pedidos;

    COMMIT;

    SELECT v_qtd AS pedidos, v_total AS total,
           IF(p_forma = 'DINHEIRO' AND p_valor_recebido IS NOT NULL, p_valor_recebido - v_total, 0) AS troco;
END//

-- Leva os pedidos abertos de uma mesa para outra mesa livre.
DROP PROCEDURE IF EXISTS sp_mesa_transferir//
CREATE PROCEDURE sp_mesa_transferir(IN p_origem VARCHAR(20), IN p_destino VARCHAR(20))
BEGIN
    IF p_origem = p_destino THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha uma mesa diferente da atual.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM MESA WHERE NUMERO = p_destino AND STATUS = 'ATIVO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mesa de destino não encontrada.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM VENDA WHERE TIPO_PEDIDO = 'SALAO' AND MESA = p_origem AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A mesa de origem não tem pedidos em aberto.';
    END IF;
    IF EXISTS (SELECT 1 FROM VENDA WHERE TIPO_PEDIDO = 'SALAO' AND MESA = p_destino AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A mesa de destino está ocupada.';
    END IF;

    UPDATE VENDA SET MESA = p_destino
    WHERE TIPO_PEDIDO = 'SALAO' AND MESA = p_origem AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO');
END//

-- ==========================================================================
-- 4. FORNECEDORES
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_fornecedor_listar//
CREATE PROCEDURE sp_fornecedor_listar(
    IN p_busca VARCHAR(255),
    IN p_status VARCHAR(10),
    IN p_limite INT,
    IN p_deslocamento INT
)
BEGIN
    DECLARE v_texto VARCHAR(257) DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    DECLARE v_digitos VARCHAR(255) DEFAULT fn_somente_digitos(p_busca);
    DECLARE v_numeros VARCHAR(257) DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');

    SELECT f.ID AS id, f.NOME AS nome, f.DOCUMENTO AS documento, f.TELEFONE AS telefone, f.CONTATO AS contato,
           f.EMAIL AS email, f.STATUS AS status, ci.NOME AS cidade, es.SIGLA AS estado,
           (SELECT COUNT(*) FROM COMPRA c WHERE c.FORNECEDOR_ID = f.ID AND c.STATUS = 'FINALIZADA') AS compras,
           (SELECT COALESCE(SUM(c.TOTAL), 0) FROM COMPRA c WHERE c.FORNECEDOR_ID = f.ID AND c.STATUS = 'FINALIZADA') AS total_comprado,
           (SELECT COUNT(*) FROM FORNECEDOR_PRODUTO fp WHERE fp.FORNECEDOR_ID = f.ID AND fp.STATUS = 'ATIVO') AS produtos,
           (SELECT DATE_FORMAT(MAX(c.DATA_CADASTRO), '%d/%m/%Y') FROM COMPRA c WHERE c.FORNECEDOR_ID = f.ID AND c.STATUS = 'FINALIZADA') AS ultima_compra
    FROM FORNECEDOR f
    JOIN CIDADE ci ON ci.ID = f.CIDADE_ID
    JOIN ESTADO es ON es.ID = ci.ESTADO_ID
    WHERE (TRIM(COALESCE(p_busca, '')) = ''
           OR f.NOME LIKE v_texto OR f.CONTATO LIKE v_texto
           OR (v_digitos <> '' AND (f.DOCUMENTO LIKE v_numeros OR f.TELEFONE LIKE v_numeros)))
      AND (p_status NOT IN ('ATIVO', 'INATIVO') OR f.STATUS = p_status)
    ORDER BY f.NOME
    LIMIT p_limite OFFSET p_deslocamento;
END//

DROP PROCEDURE IF EXISTS sp_fornecedor_contagem//
CREATE PROCEDURE sp_fornecedor_contagem(IN p_busca VARCHAR(255))
BEGIN
    DECLARE v_texto VARCHAR(257) DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    DECLARE v_digitos VARCHAR(255) DEFAULT fn_somente_digitos(p_busca);
    DECLARE v_numeros VARCHAR(257) DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');

    SELECT COUNT(*) AS todos,
           COALESCE(SUM(f.STATUS = 'ATIVO'), 0) AS ativos,
           COALESCE(SUM(f.STATUS = 'INATIVO'), 0) AS inativos
    FROM FORNECEDOR f
    WHERE TRIM(COALESCE(p_busca, '')) = ''
       OR f.NOME LIKE v_texto OR f.CONTATO LIKE v_texto
       OR (v_digitos <> '' AND (f.DOCUMENTO LIKE v_numeros OR f.TELEFONE LIKE v_numeros));
END//

DROP PROCEDURE IF EXISTS sp_fornecedor_obter//
CREATE PROCEDURE sp_fornecedor_obter(IN p_id INT)
BEGIN
    SELECT f.ID AS id, f.NOME AS nome, f.DOCUMENTO AS documento, f.ENDERECO AS endereco,
           f.ENDERECO_NUMERO AS endereco_numero, f.TELEFONE AS telefone, f.CONTATO AS contato, f.EMAIL AS email,
           f.STATUS AS status, ci.NOME AS cidade, es.SIGLA AS estado
    FROM FORNECEDOR f
    JOIN CIDADE ci ON ci.ID = f.CIDADE_ID
    JOIN ESTADO es ON es.ID = ci.ESTADO_ID
    WHERE f.ID = p_id;
END//

-- Produtos que o fornecedor entrega, com o último preço praticado.
DROP PROCEDURE IF EXISTS sp_fornecedor_produtos//
CREATE PROCEDURE sp_fornecedor_produtos(IN p_id INT)
BEGIN
    SELECT p.ID AS id, p.NOME AS nome, p.UNIDADE_MEDIDA AS unidade, fp.PRECO_FORNECEDOR AS preco,
           fp.PRAZO_ENTREGA_DIAS AS prazo
    FROM FORNECEDOR_PRODUTO fp
    JOIN PRODUTO p ON p.ID = fp.PRODUTO_ID
    WHERE fp.FORNECEDOR_ID = p_id AND fp.STATUS = 'ATIVO'
    ORDER BY p.NOME;
END//

DROP PROCEDURE IF EXISTS sp_fornecedor_salvar//
CREATE PROCEDURE sp_fornecedor_salvar(
    IN p_id INT,
    IN p_nome VARCHAR(255),
    IN p_documento VARCHAR(30),
    IN p_endereco VARCHAR(255),
    IN p_numero VARCHAR(20),
    IN p_telefone VARCHAR(30),
    IN p_contato VARCHAR(255),
    IN p_email VARCHAR(255),
    IN p_status VARCHAR(10),
    IN p_uf CHAR(2),
    IN p_cidade VARCHAR(200)
)
BEGIN
    DECLARE v_documento VARCHAR(255) DEFAULT fn_somente_digitos(p_documento);
    DECLARE v_telefone VARCHAR(255) DEFAULT fn_somente_digitos(p_telefone);
    DECLARE v_email VARCHAR(255) DEFAULT NULLIF(TRIM(COALESCE(p_email, '')), '');
    DECLARE v_estado_id INT DEFAULT NULL;
    DECLARE v_cidade_id INT DEFAULT NULL;
    DECLARE v_id INT DEFAULT COALESCE(p_id, 0);

    IF TRIM(COALESCE(p_nome, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome do fornecedor.';
    END IF;
    IF CHAR_LENGTH(v_documento) NOT IN (11, 14) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe um CNPJ (14 números) ou CPF (11 números) válido.';
    END IF;
    IF CHAR_LENGTH(v_telefone) NOT IN (10, 11) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o telefone com DDD (10 ou 11 números).';
    END IF;
    IF v_email IS NOT NULL AND v_email NOT LIKE '%_@_%.__%' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe um e-mail válido ou deixe o campo vazio.';
    END IF;
    IF TRIM(COALESCE(p_endereco, '')) = '' OR TRIM(COALESCE(p_numero, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o endereço e o número.';
    END IF;
    IF TRIM(COALESCE(p_cidade, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a cidade.';
    END IF;
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        SET p_status = 'ATIVO';
    END IF;

    SELECT ID INTO v_estado_id FROM ESTADO WHERE SIGLA = p_uf LIMIT 1;
    IF v_estado_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Estado inválido.';
    END IF;
    IF v_id > 0 AND NOT EXISTS (SELECT 1 FROM FORNECEDOR WHERE ID = v_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fornecedor não encontrado.';
    END IF;
    IF EXISTS (SELECT 1 FROM FORNECEDOR WHERE DOCUMENTO = v_documento AND ID <> v_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe um fornecedor com esse CNPJ/CPF.';
    END IF;

    SELECT ID INTO v_cidade_id FROM CIDADE WHERE NOME = TRIM(p_cidade) AND ESTADO_ID = v_estado_id LIMIT 1;
    IF v_cidade_id IS NULL THEN
        INSERT INTO CIDADE (NOME, ESTADO_ID) VALUES (TRIM(p_cidade), v_estado_id);
        SET v_cidade_id = LAST_INSERT_ID();
    END IF;

    IF v_id = 0 THEN
        INSERT INTO FORNECEDOR (NOME, DOCUMENTO, ENDERECO, ENDERECO_NUMERO, TELEFONE, CONTATO, EMAIL, STATUS, CIDADE_ID)
        VALUES (TRIM(p_nome), v_documento, TRIM(p_endereco), TRIM(p_numero), v_telefone,
                NULLIF(TRIM(COALESCE(p_contato, '')), ''), v_email, p_status, v_cidade_id);
        SET v_id = LAST_INSERT_ID();
    ELSE
        UPDATE FORNECEDOR
        SET NOME = TRIM(p_nome), DOCUMENTO = v_documento, ENDERECO = TRIM(p_endereco),
            ENDERECO_NUMERO = TRIM(p_numero), TELEFONE = v_telefone,
            CONTATO = NULLIF(TRIM(COALESCE(p_contato, '')), ''), EMAIL = v_email, STATUS = p_status,
            CIDADE_ID = v_cidade_id
        WHERE ID = v_id;
    END IF;

    SELECT v_id AS id;
END//

DROP PROCEDURE IF EXISTS sp_fornecedor_status//
CREATE PROCEDURE sp_fornecedor_status(IN p_id INT, IN p_status VARCHAR(10))
BEGIN
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Situação inválida.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM FORNECEDOR WHERE ID = p_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fornecedor não encontrado.';
    END IF;

    UPDATE FORNECEDOR SET STATUS = p_status WHERE ID = p_id;
END//

-- Exclui; com compras ou produtos vinculados apenas inativa. Devolve EXCLUIDO ou INATIVADO.
DROP PROCEDURE IF EXISTS sp_fornecedor_excluir//
CREATE PROCEDURE sp_fornecedor_excluir(IN p_id INT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM FORNECEDOR WHERE ID = p_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fornecedor não encontrado.';
    END IF;

    IF EXISTS (SELECT 1 FROM COMPRA WHERE FORNECEDOR_ID = p_id)
       OR EXISTS (SELECT 1 FROM FORNECEDOR_PRODUTO WHERE FORNECEDOR_ID = p_id) THEN
        UPDATE FORNECEDOR SET STATUS = 'INATIVO' WHERE ID = p_id;
        SELECT 'INATIVADO' AS resultado;
    ELSE
        DELETE FROM FORNECEDOR WHERE ID = p_id;
        SELECT 'EXCLUIDO' AS resultado;
    END IF;
END//

DROP PROCEDURE IF EXISTS sp_fornecedor_opcoes//
CREATE PROCEDURE sp_fornecedor_opcoes()
BEGIN
    SELECT ID AS id, NOME AS nome FROM FORNECEDOR WHERE STATUS = 'ATIVO' ORDER BY NOME;
END//

-- ==========================================================================
-- 5. COMPRAS (COMPRA + COMPRA_ITEM + CONTA_PAGAR)
--    Finalizar a compra dá entrada no estoque, atualiza o custo dos insumos
--    e gera as parcelas no Fluxo de Caixa. Criar/itens/finalizar rodam na
--    mesma transação do PHP; cancelar abre a própria transação.
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_compra_listar//
CREATE PROCEDURE sp_compra_listar(
    IN p_busca VARCHAR(255),
    IN p_status VARCHAR(12),
    IN p_de DATE,
    IN p_ate DATE,
    IN p_limite INT,
    IN p_deslocamento INT
)
BEGIN
    DECLARE v_texto VARCHAR(257) DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    DECLARE v_numero INT DEFAULT CAST(NULLIF(fn_somente_digitos(p_busca), '') AS UNSIGNED);

    SELECT c.ID AS id, DATE_FORMAT(c.DATA_CADASTRO, '%d/%m/%Y') AS data, f.NOME AS fornecedor, c.NUMERO_NF AS nf,
           c.VALOR AS valor, c.DESCONTO AS desconto, c.TOTAL AS total, c.STATUS AS status, u.NOME AS responsavel,
           (SELECT COUNT(*) FROM COMPRA_ITEM ci WHERE ci.COMPRA_ID = c.ID) AS itens,
           (SELECT COUNT(*) FROM CONTA_PAGAR cp WHERE cp.COMPRA_ID = c.ID) AS parcelas,
           (SELECT COUNT(*) FROM CONTA_PAGAR cp WHERE cp.COMPRA_ID = c.ID AND cp.STATUS = 'PAGO') AS parcelas_pagas
    FROM COMPRA c
    JOIN FORNECEDOR f ON f.ID = c.FORNECEDOR_ID
    JOIN USUARIOS u ON u.ID = c.FUNCIONARIO_ID
    WHERE c.DATA_CADASTRO >= p_de AND c.DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
      AND (TRIM(COALESCE(p_busca, '')) = '' OR f.NOME LIKE v_texto OR c.NUMERO_NF LIKE v_texto OR c.ID = v_numero)
      AND (p_status NOT IN ('FINALIZADA', 'CANCELADA') OR c.STATUS = p_status)
    ORDER BY c.DATA_CADASTRO DESC, c.ID DESC
    LIMIT p_limite OFFSET p_deslocamento;
END//

DROP PROCEDURE IF EXISTS sp_compra_contagem//
CREATE PROCEDURE sp_compra_contagem(IN p_busca VARCHAR(255), IN p_de DATE, IN p_ate DATE)
BEGIN
    DECLARE v_texto VARCHAR(257) DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    DECLARE v_numero INT DEFAULT CAST(NULLIF(fn_somente_digitos(p_busca), '') AS UNSIGNED);

    SELECT COUNT(*) AS todos,
           COALESCE(SUM(c.STATUS = 'FINALIZADA'), 0) AS finalizadas,
           COALESCE(SUM(c.STATUS = 'CANCELADA'), 0) AS canceladas
    FROM COMPRA c
    JOIN FORNECEDOR f ON f.ID = c.FORNECEDOR_ID
    WHERE c.DATA_CADASTRO >= p_de AND c.DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
      AND (TRIM(COALESCE(p_busca, '')) = '' OR f.NOME LIKE v_texto OR c.NUMERO_NF LIKE v_texto OR c.ID = v_numero);
END//

DROP PROCEDURE IF EXISTS sp_compra_resumo//
CREATE PROCEDURE sp_compra_resumo(IN p_de DATE, IN p_ate DATE)
BEGIN
    SELECT
        (SELECT COALESCE(SUM(TOTAL), 0) FROM COMPRA
         WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)) AS total_comprado,
        (SELECT COUNT(*) FROM COMPRA
         WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)) AS compras,
        (SELECT COALESCE(SUM(TOTAL), 0) FROM CONTA_PAGAR WHERE STATUS IN ('PENDENTE', 'ATRASADO', 'AGENDADO')) AS a_pagar,
        (SELECT COALESCE(SUM(TOTAL), 0) FROM CONTA_PAGAR WHERE STATUS IN ('PENDENTE', 'ATRASADO') AND DATA_VENCIMENTO < CURDATE()) AS vencido,
        (SELECT f.NOME FROM COMPRA c JOIN FORNECEDOR f ON f.ID = c.FORNECEDOR_ID
         WHERE c.STATUS = 'FINALIZADA' AND c.DATA_CADASTRO >= p_de AND c.DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
         GROUP BY f.ID, f.NOME ORDER BY SUM(c.TOTAL) DESC LIMIT 1) AS principal_fornecedor;
END//

DROP PROCEDURE IF EXISTS sp_compra_obter//
CREATE PROCEDURE sp_compra_obter(IN p_id INT)
BEGIN
    SELECT c.ID AS id, DATE_FORMAT(c.DATA_CADASTRO, '%d/%m/%Y %H:%i') AS data, c.FORNECEDOR_ID AS fornecedor_id,
           f.NOME AS fornecedor, f.TELEFONE AS fornecedor_telefone, c.NUMERO_NF AS nf, c.OBSERVACAO AS observacao,
           c.VALOR AS valor, c.DESCONTO AS desconto, c.TOTAL AS total, c.STATUS AS status, u.NOME AS responsavel
    FROM COMPRA c
    JOIN FORNECEDOR f ON f.ID = c.FORNECEDOR_ID
    JOIN USUARIOS u ON u.ID = c.FUNCIONARIO_ID
    WHERE c.ID = p_id;
END//

DROP PROCEDURE IF EXISTS sp_compra_itens//
CREATE PROCEDURE sp_compra_itens(IN p_id INT)
BEGIN
    SELECT ci.PRODUTO_ID AS produto_id, p.NOME AS nome, p.UNIDADE_MEDIDA AS unidade, ci.QUANTIDADE AS quantidade,
           ci.PRECO_UNITARIO AS preco, ci.TOTAL AS total
    FROM COMPRA_ITEM ci
    JOIN PRODUTO p ON p.ID = ci.PRODUTO_ID
    WHERE ci.COMPRA_ID = p_id
    ORDER BY p.NOME;
END//

DROP PROCEDURE IF EXISTS sp_compra_parcelas//
CREATE PROCEDURE sp_compra_parcelas(IN p_id INT)
BEGIN
    SELECT ID AS id, PARCELA_NUMERO AS numero, PARCELA_QUANTIDADE AS quantidade, DATA_VENCIMENTO AS vencimento,
           DATA_PAGAMENTO AS pagamento, TOTAL AS total,
           CASE WHEN STATUS = 'PENDENTE' AND DATA_VENCIMENTO < CURDATE() THEN 'ATRASADO' ELSE STATUS END AS situacao
    FROM CONTA_PAGAR
    WHERE COMPRA_ID = p_id
    ORDER BY PARCELA_NUMERO;
END//

-- Produtos ativos que podem ser comprados (insumos e itens de revenda).
DROP PROCEDURE IF EXISTS sp_compra_opcoes_produtos//
CREATE PROCEDURE sp_compra_opcoes_produtos()
BEGIN
    SELECT p.ID AS id, p.NOME AS nome, p.UNIDADE_MEDIDA AS unidade, p.PRECO_UNITARIO AS preco, p.ESTOQUE AS estoque,
           c.NOME AS categoria, c.TIPO AS tipo
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE p.STATUS = 'ATIVO' AND (c.TIPO = 'INSUMO' OR p.CONTROLA_ESTOQUE = 1)
    ORDER BY c.TIPO DESC, c.NOME, p.NOME;
END//

DROP PROCEDURE IF EXISTS sp_compra_criar//
CREATE PROCEDURE sp_compra_criar(
    IN p_fornecedor_id INT,
    IN p_usuario_id INT,
    IN p_nf VARCHAR(30),
    IN p_observacao VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM FORNECEDOR WHERE ID = p_fornecedor_id AND STATUS = 'ATIVO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha um fornecedor ativo.';
    END IF;

    INSERT INTO COMPRA (VALOR, DESCONTO, TOTAL, FORNECEDOR_ID, FUNCIONARIO_ID, STATUS, NUMERO_NF, OBSERVACAO)
    VALUES (0, 0, 0, p_fornecedor_id, p_usuario_id, 'EM_ABERTO',
            NULLIF(TRIM(COALESCE(p_nf, '')), ''), NULLIF(TRIM(COALESCE(p_observacao, '')), ''));

    SELECT LAST_INSERT_ID() AS id;
END//

DROP PROCEDURE IF EXISTS sp_compra_item_adicionar//
CREATE PROCEDURE sp_compra_item_adicionar(IN p_compra_id INT, IN p_produto_id INT, IN p_quantidade INT, IN p_preco DECIMAL(10, 2))
BEGIN
    IF p_quantidade IS NULL OR p_quantidade < 1 OR p_quantidade > 999999 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe uma quantidade válida para cada item da compra.';
    END IF;
    IF p_preco IS NULL OR p_preco < 0.01 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o preço unitário de cada item da compra.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM PRODUTO WHERE ID = p_produto_id AND STATUS = 'ATIVO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Um dos produtos da compra não está ativo.';
    END IF;

    INSERT INTO COMPRA_ITEM (COMPRA_ID, PRODUTO_ID, QUANTIDADE, PRECO_UNITARIO, VALOR, DESCONTO, TOTAL, STATUS)
    VALUES (p_compra_id, p_produto_id, p_quantidade, p_preco, p_quantidade * p_preco, 0, p_quantidade * p_preco, 'EM_ABERTO')
    ON DUPLICATE KEY UPDATE
        QUANTIDADE = QUANTIDADE + p_quantidade,
        PRECO_UNITARIO = p_preco,
        VALOR = (QUANTIDADE) * p_preco,
        TOTAL = (QUANTIDADE) * p_preco;
END//

DROP PROCEDURE IF EXISTS sp_compra_finalizar//
CREATE PROCEDURE sp_compra_finalizar(
    IN p_compra_id INT,
    IN p_desconto DECIMAL(10, 2),
    IN p_parcelas INT,
    IN p_primeiro_vencimento DATE,
    IN p_pago TINYINT,
    IN p_usuario_id INT
)
BEGIN
    DECLARE v_valor DECIMAL(10, 2) DEFAULT 0;
    DECLARE v_itens INT DEFAULT 0;
    DECLARE v_desconto DECIMAL(10, 2) DEFAULT COALESCE(p_desconto, 0);
    DECLARE v_total DECIMAL(10, 2) DEFAULT 0;
    DECLARE v_parcela DECIMAL(10, 2) DEFAULT 0;
    DECLARE v_acumulado DECIMAL(10, 2) DEFAULT 0;
    DECLARE v_i INT DEFAULT 1;
    DECLARE v_fornecedor INT DEFAULT NULL;

    SELECT FORNECEDOR_ID INTO v_fornecedor FROM COMPRA WHERE ID = p_compra_id AND STATUS = 'EM_ABERTO';
    IF v_fornecedor IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Compra não encontrada ou já finalizada.';
    END IF;

    SELECT COALESCE(SUM(TOTAL), 0), COUNT(*) INTO v_valor, v_itens FROM COMPRA_ITEM WHERE COMPRA_ID = p_compra_id;

    IF v_itens = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adicione ao menos um item à compra.';
    END IF;
    IF v_desconto < 0 OR v_desconto >= v_valor THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O desconto deve ser menor que o valor dos itens.';
    END IF;
    IF p_parcelas IS NULL OR p_parcelas < 1 OR p_parcelas > 12 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha de 1 a 12 parcelas.';
    END IF;
    IF p_primeiro_vencimento IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o vencimento da primeira parcela.';
    END IF;

    SET v_total = v_valor - v_desconto;

    UPDATE COMPRA SET VALOR = v_valor, DESCONTO = v_desconto, TOTAL = v_total, STATUS = 'FINALIZADA' WHERE ID = p_compra_id;
    UPDATE COMPRA_ITEM SET STATUS = 'FINALIZADA' WHERE COMPRA_ID = p_compra_id;

    -- parcelas mensais; a última absorve a diferença de centavos
    SET v_parcela = ROUND(v_total / p_parcelas, 2);
    WHILE v_i <= p_parcelas DO
        IF v_i = p_parcelas THEN
            SET v_parcela = v_total - v_acumulado;
        END IF;
        INSERT INTO CONTA_PAGAR (COMPRA_ID, PARCELA_QUANTIDADE, PARCELA_NUMERO, DATA_VENCIMENTO, DATA_PAGAMENTO,
                                 VALOR, DESCONTO, TOTAL, STATUS)
        VALUES (p_compra_id, p_parcelas, v_i, DATE_ADD(p_primeiro_vencimento, INTERVAL v_i - 1 MONTH),
                IF(p_pago = 1 AND v_i = 1, CURDATE(), NULL), v_parcela, 0, v_parcela,
                IF(p_pago = 1 AND v_i = 1, 'PAGO', 'PENDENTE'));
        SET v_acumulado = v_acumulado + v_parcela;
        SET v_i = v_i + 1;
    END WHILE;

    -- entrada no estoque
    UPDATE PRODUTO p
    JOIN COMPRA_ITEM ci ON ci.PRODUTO_ID = p.ID AND ci.COMPRA_ID = p_compra_id
    SET p.ESTOQUE = p.ESTOQUE + ci.QUANTIDADE;

    INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, OBSERVACAO)
    SELECT ci.PRODUTO_ID, p_usuario_id, 'ENTRADA', ci.QUANTIDADE, CONCAT('Compra #', p_compra_id)
    FROM COMPRA_ITEM ci WHERE ci.COMPRA_ID = p_compra_id;

    -- o custo do insumo passa a ser o último preço pago
    UPDATE PRODUTO p
    JOIN COMPRA_ITEM ci ON ci.PRODUTO_ID = p.ID AND ci.COMPRA_ID = p_compra_id
    JOIN PRODUTO_CATEGORIA cat ON cat.ID = p.CATEGORIA_ID
    SET p.PRECO_UNITARIO = ci.PRECO_UNITARIO
    WHERE cat.TIPO = 'INSUMO';

    -- tabela de preços do fornecedor
    INSERT INTO FORNECEDOR_PRODUTO (FORNECEDOR_ID, PRODUTO_ID, PRECO_FORNECEDOR, PRAZO_ENTREGA_DIAS, STATUS)
    SELECT v_fornecedor, ci.PRODUTO_ID, ci.PRECO_UNITARIO, 0, 'ATIVO'
    FROM COMPRA_ITEM ci WHERE ci.COMPRA_ID = p_compra_id
    ON DUPLICATE KEY UPDATE PRECO_FORNECEDOR = VALUES(PRECO_FORNECEDOR), STATUS = 'ATIVO';
END//

-- Cancela uma compra finalizada: estorna o estoque e cancela as parcelas em aberto.
DROP PROCEDURE IF EXISTS sp_compra_cancelar//
CREATE PROCEDURE sp_compra_cancelar(IN p_compra_id INT, IN p_usuario_id INT)
BEGIN
    DECLARE v_status VARCHAR(12) DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT STATUS INTO v_status FROM COMPRA WHERE ID = p_compra_id;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Compra não encontrada.';
    END IF;
    IF v_status = 'CANCELADA' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta compra já está cancelada.';
    END IF;
    IF EXISTS (SELECT 1 FROM CONTA_PAGAR WHERE COMPRA_ID = p_compra_id AND STATUS = 'PAGO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Há parcelas pagas desta compra. Desfaça os pagamentos no Fluxo de Caixa antes de cancelar.';
    END IF;

    START TRANSACTION;

    IF v_status = 'FINALIZADA' THEN
        UPDATE PRODUTO p
        JOIN COMPRA_ITEM ci ON ci.PRODUTO_ID = p.ID AND ci.COMPRA_ID = p_compra_id
        SET p.ESTOQUE = GREATEST(0, p.ESTOQUE - ci.QUANTIDADE);

        INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, OBSERVACAO)
        SELECT ci.PRODUTO_ID, p_usuario_id, 'SAIDA', ci.QUANTIDADE, CONCAT('Estorno da compra #', p_compra_id)
        FROM COMPRA_ITEM ci WHERE ci.COMPRA_ID = p_compra_id;
    END IF;

    UPDATE CONTA_PAGAR SET STATUS = 'CANCELADO', DATA_PAGAMENTO = NULL WHERE COMPRA_ID = p_compra_id;
    UPDATE COMPRA_ITEM SET STATUS = 'CANCELADA' WHERE COMPRA_ID = p_compra_id;
    UPDATE COMPRA SET STATUS = 'CANCELADA' WHERE ID = p_compra_id;

    COMMIT;
END//

-- ==========================================================================
-- 6. RELATÓRIOS (período por data de cadastro da venda)
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_rel_resumo//
CREATE PROCEDURE sp_rel_resumo(IN p_de DATE, IN p_ate DATE)
BEGIN
    SELECT a.*, b.itens, b.cmv, d.despesas_pagas
    FROM (
        SELECT COALESCE(SUM(CASE WHEN v.STATUS = 'FINALIZADA' THEN v.TOTAL END), 0) AS faturamento,
               COALESCE(SUM(v.STATUS = 'FINALIZADA'), 0) AS pedidos,
               COALESCE(SUM(v.STATUS = 'CANCELADA'), 0) AS cancelados,
               COALESCE(SUM(CASE WHEN v.STATUS = 'CANCELADA' THEN v.TOTAL END), 0) AS valor_cancelado,
               COALESCE(SUM(CASE WHEN v.STATUS = 'FINALIZADA' THEN v.DESCONTO END), 0) AS descontos,
               COUNT(DISTINCT CASE WHEN v.STATUS = 'FINALIZADA' THEN v.CLIENTE_ID END) AS clientes
        FROM VENDA v
        WHERE v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    ) a
    CROSS JOIN (
        SELECT COALESCE(SUM(vi.QUANTIDADE), 0) AS itens,
               COALESCE(SUM(vi.QUANTIDADE * COALESCE(fn_produto_custo(vi.PRODUTO_ID), 0)), 0) AS cmv
        FROM VENDA_ITEM vi
        JOIN VENDA v ON v.ID = vi.VENDA_ID
        WHERE v.STATUS = 'FINALIZADA' AND v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    ) b
    CROSS JOIN (
        SELECT COALESCE(SUM(total), 0) AS despesas_pagas
        FROM vw_fluxo_caixa
        WHERE tipo = 'SAIDA' AND status = 'PAGO' AND pagamento BETWEEN p_de AND p_ate
    ) d;
END//

DROP PROCEDURE IF EXISTS sp_rel_vendas_dia//
CREATE PROCEDURE sp_rel_vendas_dia(IN p_de DATE, IN p_ate DATE)
BEGIN
    SELECT DATE(DATA_CADASTRO) AS dia, COUNT(*) AS pedidos, SUM(TOTAL) AS faturamento
    FROM VENDA
    WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    GROUP BY DATE(DATA_CADASTRO)
    ORDER BY dia;
END//

DROP PROCEDURE IF EXISTS sp_rel_formas//
CREATE PROCEDURE sp_rel_formas(IN p_de DATE, IN p_ate DATE)
BEGIN
    SELECT FORMA_PAGAMENTO AS forma, COUNT(*) AS pedidos, SUM(TOTAL) AS total
    FROM VENDA
    WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    GROUP BY FORMA_PAGAMENTO
    ORDER BY total DESC;
END//

DROP PROCEDURE IF EXISTS sp_rel_tipos//
CREATE PROCEDURE sp_rel_tipos(IN p_de DATE, IN p_ate DATE)
BEGIN
    SELECT TIPO_PEDIDO AS tipo, COUNT(*) AS pedidos, SUM(TOTAL) AS total, AVG(TOTAL) AS ticket
    FROM VENDA
    WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    GROUP BY TIPO_PEDIDO
    ORDER BY total DESC;
END//

DROP PROCEDURE IF EXISTS sp_rel_horas//
CREATE PROCEDURE sp_rel_horas(IN p_de DATE, IN p_ate DATE)
BEGIN
    SELECT HOUR(DATA_CADASTRO) AS hora, COUNT(*) AS pedidos, SUM(TOTAL) AS total
    FROM VENDA
    WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    GROUP BY HOUR(DATA_CADASTRO)
    ORDER BY hora;
END//

-- 1 = domingo ... 7 = sábado
DROP PROCEDURE IF EXISTS sp_rel_dias_semana//
CREATE PROCEDURE sp_rel_dias_semana(IN p_de DATE, IN p_ate DATE)
BEGIN
    SELECT DAYOFWEEK(DATA_CADASTRO) AS dia, COUNT(*) AS pedidos, SUM(TOTAL) AS total
    FROM VENDA
    WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= p_de AND DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    GROUP BY DAYOFWEEK(DATA_CADASTRO)
    ORDER BY dia;
END//

DROP PROCEDURE IF EXISTS sp_rel_produtos//
CREATE PROCEDURE sp_rel_produtos(IN p_de DATE, IN p_ate DATE, IN p_limite INT)
BEGIN
    SELECT p.ID AS id, p.NOME AS nome, c.NOME AS categoria, SUM(vi.QUANTIDADE) AS quantidade, SUM(vi.TOTAL) AS receita,
           SUM(vi.QUANTIDADE * COALESCE(fn_produto_custo(p.ID), 0)) AS custo
    FROM VENDA_ITEM vi
    JOIN VENDA v ON v.ID = vi.VENDA_ID
    JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE v.STATUS = 'FINALIZADA' AND v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    GROUP BY p.ID, p.NOME, c.NOME
    ORDER BY receita DESC
    LIMIT p_limite;
END//

DROP PROCEDURE IF EXISTS sp_rel_categorias//
CREATE PROCEDURE sp_rel_categorias(IN p_de DATE, IN p_ate DATE)
BEGIN
    SELECT c.NOME AS categoria, SUM(vi.QUANTIDADE) AS quantidade, SUM(vi.TOTAL) AS receita
    FROM VENDA_ITEM vi
    JOIN VENDA v ON v.ID = vi.VENDA_ID
    JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE v.STATUS = 'FINALIZADA' AND v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    GROUP BY c.ID, c.NOME
    ORDER BY receita DESC;
END//

DROP PROCEDURE IF EXISTS sp_rel_atendentes//
CREATE PROCEDURE sp_rel_atendentes(IN p_de DATE, IN p_ate DATE)
BEGIN
    SELECT u.NOME AS nome, COALESCE(u.CARGO, 'FUNCIONARIO') AS cargo, COUNT(*) AS pedidos, SUM(v.TOTAL) AS total,
           AVG(v.TOTAL) AS ticket
    FROM VENDA v
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    WHERE v.STATUS = 'FINALIZADA' AND v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    GROUP BY u.ID, u.NOME, u.CARGO
    ORDER BY total DESC;
END//

DROP PROCEDURE IF EXISTS sp_rel_clientes//
CREATE PROCEDURE sp_rel_clientes(IN p_de DATE, IN p_ate DATE, IN p_limite INT)
BEGIN
    SELECT cl.NOME AS nome, COUNT(*) AS pedidos, SUM(v.TOTAL) AS total,
           DATE_FORMAT(MAX(v.DATA_CADASTRO), '%d/%m/%Y') AS ultima_compra
    FROM VENDA v
    JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    WHERE v.STATUS = 'FINALIZADA' AND v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    GROUP BY cl.ID, cl.NOME
    ORDER BY total DESC
    LIMIT p_limite;
END//

-- Lista completa de vendas do período (exportação CSV / Excel).
DROP PROCEDURE IF EXISTS sp_rel_vendas_lista//
CREATE PROCEDURE sp_rel_vendas_lista(IN p_de DATE, IN p_ate DATE)
BEGIN
    SELECT v.ID AS id, DATE_FORMAT(v.DATA_CADASTRO, '%d/%m/%Y %H:%i') AS data_hora, v.TIPO_PEDIDO AS tipo,
           v.MESA AS mesa, COALESCE(cl.NOME, '') AS cliente, u.NOME AS atendente, v.FORMA_PAGAMENTO AS forma,
           v.VALOR AS valor, v.DESCONTO AS desconto, v.TOTAL AS total, v.STATUS AS status, v.ETAPA AS etapa,
           (SELECT GROUP_CONCAT(CONCAT(vi.QUANTIDADE, 'x ', p.NOME) ORDER BY p.NOME SEPARATOR ', ')
              FROM VENDA_ITEM vi JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID WHERE vi.VENDA_ID = v.ID) AS itens
    FROM VENDA v
    LEFT JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    WHERE v.DATA_CADASTRO >= p_de AND v.DATA_CADASTRO < DATE_ADD(p_ate, INTERVAL 1 DAY)
    ORDER BY v.DATA_CADASTRO, v.ID;
END//

-- ==========================================================================
-- 7. VISÃO GERAL (dashboard)
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_dash_resumo//
CREATE PROCEDURE sp_dash_resumo()
BEGIN
    DECLARE v_limite INT DEFAULT CAST(fn_config(0, 'tempo_preparo', '20') AS UNSIGNED);

    SELECT
        (SELECT COALESCE(SUM(TOTAL), 0) FROM VENDA
         WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= CURDATE()) AS faturamento_hoje,
        (SELECT COALESCE(SUM(TOTAL), 0) FROM VENDA
         WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= CURDATE() - INTERVAL 1 DAY
           AND DATA_CADASTRO < NOW() - INTERVAL 1 DAY) AS faturamento_ontem_ate_agora,
        (SELECT COALESCE(SUM(TOTAL), 0) FROM VENDA
         WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= CURDATE() - INTERVAL 1 DAY AND DATA_CADASTRO < CURDATE()) AS faturamento_ontem,
        (SELECT COUNT(*) FROM VENDA WHERE STATUS <> 'CANCELADA' AND DATA_CADASTRO >= CURDATE()) AS pedidos_hoje,
        (SELECT COUNT(*) FROM VENDA WHERE STATUS <> 'CANCELADA' AND DATA_CADASTRO >= CURDATE() - INTERVAL 1 DAY
           AND DATA_CADASTRO < NOW() - INTERVAL 1 DAY) AS pedidos_ontem_ate_agora,
        (SELECT COUNT(*) FROM VENDA WHERE STATUS <> 'CANCELADA' AND DATA_CADASTRO >= NOW() - INTERVAL 1 HOUR) AS pedidos_ultima_hora,
        (SELECT AVG(TOTAL) FROM VENDA WHERE STATUS = 'FINALIZADA' AND DATA_CADASTRO >= CURDATE()) AS ticket_hoje,
        (SELECT AVG(TOTAL) FROM VENDA WHERE STATUS = 'FINALIZADA'
           AND DATA_CADASTRO >= CURDATE() - INTERVAL 30 DAY AND DATA_CADASTRO < CURDATE()) AS ticket_30dias,
        (SELECT COUNT(*) FROM VENDA WHERE ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')) AS em_andamento,
        (SELECT COUNT(*) FROM VENDA WHERE ETAPA IN ('NOVO', 'PREPARO')
           AND DATA_CADASTRO < NOW() - INTERVAL v_limite MINUTE) AS atrasados,
        (SELECT COUNT(*) FROM VENDA WHERE ETAPA = 'PRONTO' AND TIPO_PEDIDO = 'DELIVERY') AS em_entrega,
        (SELECT COUNT(*) FROM VENDA WHERE STATUS = 'CANCELADA' AND DATA_CADASTRO >= CURDATE()) AS cancelados_hoje,
        (SELECT COUNT(DISTINCT MESA) FROM VENDA WHERE TIPO_PEDIDO = 'SALAO' AND ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')) AS mesas_ocupadas,
        (SELECT COUNT(*) FROM MESA WHERE STATUS = 'ATIVO') AS mesas_total,
        v_limite AS tempo_preparo;
END//

-- Faturamento por hora: hoje x mesmo dia da semana passada.
DROP PROCEDURE IF EXISTS sp_dash_horas//
CREATE PROCEDURE sp_dash_horas()
BEGIN
    SELECT IF(DATE(DATA_CADASTRO) = CURDATE(), 'HOJE', 'SEMANA_PASSADA') AS dia, HOUR(DATA_CADASTRO) AS hora,
           COUNT(*) AS pedidos, SUM(TOTAL) AS total
    FROM VENDA
    WHERE STATUS = 'FINALIZADA'
      AND (DATA_CADASTRO >= CURDATE()
           OR (DATA_CADASTRO >= CURDATE() - INTERVAL 7 DAY AND DATA_CADASTRO < CURDATE() - INTERVAL 6 DAY))
    GROUP BY dia, hora;
END//

DROP PROCEDURE IF EXISTS sp_dash_semana//
CREATE PROCEDURE sp_dash_semana()
BEGIN
    SELECT DATE(DATA_CADASTRO) AS dia, COUNT(*) AS pedidos, COALESCE(SUM(CASE WHEN STATUS = 'FINALIZADA' THEN TOTAL END), 0) AS faturamento
    FROM VENDA
    WHERE STATUS <> 'CANCELADA' AND DATA_CADASTRO >= CURDATE() - INTERVAL 13 DAY
    GROUP BY DATE(DATA_CADASTRO)
    ORDER BY dia;
END//

DROP PROCEDURE IF EXISTS sp_dash_etapas//
CREATE PROCEDURE sp_dash_etapas()
BEGIN
    SELECT
        COALESCE(SUM(ETAPA = 'NOVO'), 0) AS novos,
        COALESCE(SUM(ETAPA = 'PREPARO'), 0) AS preparo,
        COALESCE(SUM(ETAPA = 'PRONTO'), 0) AS prontos,
        (SELECT COUNT(*) FROM VENDA WHERE ETAPA = 'ENTREGUE' AND DATA_ETAPA >= CURDATE()) AS entregues
    FROM VENDA
    WHERE ETAPA IN ('NOVO', 'PREPARO', 'PRONTO');
END//

DROP PROCEDURE IF EXISTS sp_dash_ultimos//
CREATE PROCEDURE sp_dash_ultimos(IN p_limite INT)
BEGIN
    SELECT v.ID AS id, v.TOTAL AS total, v.ETAPA AS etapa, v.TIPO_PEDIDO AS tipo, v.MESA AS mesa,
           cl.NOME AS cliente, DATE_FORMAT(v.DATA_CADASTRO, '%H:%i') AS hora,
           (SELECT GROUP_CONCAT(CONCAT(vi.QUANTIDADE, 'x ', p.NOME) ORDER BY p.NOME SEPARATOR ', ')
              FROM VENDA_ITEM vi JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID WHERE vi.VENDA_ID = v.ID) AS itens
    FROM VENDA v
    LEFT JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    ORDER BY v.DATA_CADASTRO DESC, v.ID DESC
    LIMIT p_limite;
END//

-- Receitas x despesas do mês corrente (Fluxo de Caixa).
DROP PROCEDURE IF EXISTS sp_dash_mes//
CREATE PROCEDURE sp_dash_mes()
BEGIN
    DECLARE v_inicio DATE DEFAULT DATE_FORMAT(CURDATE(), '%Y-%m-01');

    SELECT
        COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' AND status = 'PAGO' AND pagamento >= v_inicio THEN total END), 0) AS receitas,
        COALESCE(SUM(CASE WHEN tipo = 'SAIDA' AND status = 'PAGO' AND pagamento >= v_inicio THEN total END), 0) AS despesas,
        COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' AND situacao IN ('PENDENTE', 'ATRASADO') THEN total END), 0) AS a_receber,
        COALESCE(SUM(CASE WHEN tipo = 'SAIDA' AND situacao IN ('PENDENTE', 'ATRASADO') THEN total END), 0) AS a_pagar,
        COALESCE(SUM(CASE WHEN tipo = 'SAIDA' AND situacao = 'ATRASADO' THEN total END), 0) AS vencido
    FROM vw_fluxo_caixa;
END//

DROP PROCEDURE IF EXISTS sp_dash_alertas_estoque//
CREATE PROCEDURE sp_dash_alertas_estoque(IN p_dias INT)
BEGIN
    SELECT p.ID AS id, p.NOME AS nome, p.ESTOQUE AS estoque, p.ESTOQUE_MINIMO AS minimo, p.UNIDADE_MEDIDA AS unidade,
           p.DATA_VALIDADE AS validade, DATEDIFF(p.DATA_VALIDADE, CURDATE()) AS dias,
           fn_produto_situacao(p.STATUS, p.ESTOQUE, p.ESTOQUE_MINIMO, p.DATA_VALIDADE, p_dias) AS situacao
    FROM PRODUTO p
    WHERE p.STATUS = 'ATIVO'
      AND fn_produto_situacao(p.STATUS, p.ESTOQUE, p.ESTOQUE_MINIMO, p.DATA_VALIDADE, p_dias) IN ('VENCIDO', 'CRITICO', 'VENCENDO')
    ORDER BY FIELD(fn_produto_situacao(p.STATUS, p.ESTOQUE, p.ESTOQUE_MINIMO, p.DATA_VALIDADE, p_dias), 'VENCIDO', 'CRITICO', 'VENCENDO'), p.NOME
    LIMIT 30;
END//

DROP PROCEDURE IF EXISTS sp_dash_equipe_online//
CREATE PROCEDURE sp_dash_equipe_online()
BEGIN
    SELECT NOME AS nome, COALESCE(CARGO, 'FUNCIONARIO') AS cargo, NIVEL AS nivel,
           TIMESTAMPDIFF(MINUTE, COALESCE(ULTIMO_ACESSO, ULTIMO_LOGIN), NOW()) AS minutos
    FROM USUARIOS
    WHERE ATIVO = 'ATIVO' AND COALESCE(ULTIMO_ACESSO, ULTIMO_LOGIN) >= NOW() - INTERVAL 15 MINUTE
    ORDER BY NOME;
END//

-- ==========================================================================
-- 8. NOTIFICAÇÕES (calculadas na hora, respeitando as preferências do usuário)
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_notificacoes//
CREATE PROCEDURE sp_notificacoes(IN p_usuario_id INT, IN p_estoque TINYINT, IN p_financeiro TINYINT)
BEGIN
    DECLARE v_pedido TINYINT DEFAULT fn_config(p_usuario_id, 'notif_pedido', '1') = '1';
    DECLARE v_minimo TINYINT DEFAULT fn_config(p_usuario_id, 'notif_minimo', '1') = '1' AND p_estoque = 1;
    DECLARE v_validade TINYINT DEFAULT fn_config(p_usuario_id, 'notif_validade', '1') = '1' AND p_estoque = 1;
    DECLARE v_contas TINYINT DEFAULT fn_config(p_usuario_id, 'notif_contas', '0') = '1' AND p_financeiro = 1;
    DECLARE v_dias INT DEFAULT CAST(fn_config(p_usuario_id, 'dias_validade', '3') AS UNSIGNED);
    DECLARE v_limite INT DEFAULT CAST(fn_config(0, 'tempo_preparo', '20') AS UNSIGNED);

    (SELECT 'PEDIDO' AS tipo, 'alerta' AS nivel, CONCAT('Pedido #', ID, ' atrasado') AS titulo,
            CONCAT('Há ', TIMESTAMPDIFF(MINUTE, DATA_CADASTRO, NOW()), ' min · ', IF(ETAPA = 'NOVO', 'aguardando aceite', 'em preparo')) AS detalhe,
            'pedidos.php' AS link, DATA_CADASTRO AS data
     FROM VENDA
     WHERE v_pedido = 1 AND ETAPA IN ('NOVO', 'PREPARO') AND DATA_CADASTRO < NOW() - INTERVAL v_limite MINUTE
     ORDER BY DATA_CADASTRO LIMIT 10)
    UNION ALL
    (SELECT 'PEDIDO', 'info', CONCAT('Novo pedido #', ID),
            CASE TIPO_PEDIDO WHEN 'SALAO' THEN CONCAT('Mesa ', MESA) WHEN 'DELIVERY' THEN 'Delivery' ELSE 'Balcão' END,
            'pedidos.php', DATA_CADASTRO
     FROM VENDA
     WHERE v_pedido = 1 AND ETAPA = 'NOVO' AND DATA_CADASTRO >= NOW() - INTERVAL v_limite MINUTE
     ORDER BY DATA_CADASTRO DESC LIMIT 10)
    UNION ALL
    (SELECT 'ESTOQUE', 'alerta', CONCAT(NOME, ' abaixo do mínimo'),
            CONCAT(ESTOQUE, ' ', UNIDADE_MEDIDA, ' em estoque · mínimo ', ESTOQUE_MINIMO),
            'estoque.php?situacao=CRITICO', NOW()
     FROM PRODUTO
     WHERE v_minimo = 1 AND STATUS = 'ATIVO' AND ESTOQUE <= ESTOQUE_MINIMO
     ORDER BY ESTOQUE / GREATEST(ESTOQUE_MINIMO, 1) LIMIT 10)
    UNION ALL
    (SELECT 'VALIDADE', IF(DATA_VALIDADE < CURDATE(), 'alerta', 'aviso'),
            IF(DATA_VALIDADE < CURDATE(), CONCAT(NOME, ' vencido'), CONCAT(NOME, ' vence em breve')),
            CONCAT('Validade ', DATE_FORMAT(DATA_VALIDADE, '%d/%m/%Y')),
            'estoque.php?situacao=VENCENDO', NOW()
     FROM PRODUTO
     WHERE v_validade = 1 AND STATUS = 'ATIVO' AND DATA_VALIDADE IS NOT NULL
       AND DATA_VALIDADE <= CURDATE() + INTERVAL v_dias DAY
     ORDER BY DATA_VALIDADE LIMIT 10)
    UNION ALL
    (SELECT 'CONTA', IF(f.vencimento < CURDATE(), 'alerta', 'aviso'),
            IF(f.vencimento < CURDATE(), CONCAT('Conta vencida: ', f.contraparte), CONCAT('Conta vence ', IF(f.vencimento = CURDATE(), 'hoje', DATE_FORMAT(f.vencimento, '%d/%m')), ': ', f.contraparte)),
            CONCAT('R$ ', FORMAT(f.total, 2, 'pt_BR')),
            'fluxo_de_caixa.php?tipo=SAIDA&situacao=PENDENTE', NOW()
     FROM vw_fluxo_caixa f
     WHERE v_contas = 1 AND f.tipo = 'SAIDA' AND f.situacao IN ('PENDENTE', 'ATRASADO') AND f.vencimento <= CURDATE() + INTERVAL 3 DAY
     ORDER BY f.vencimento LIMIT 10);
END//

-- ==========================================================================
-- 9. BUSCA GLOBAL (Ctrl + K)
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_busca_global//
CREATE PROCEDURE sp_busca_global(IN p_termo VARCHAR(100), IN p_financeiro TINYINT)
BEGIN
    DECLARE v_texto VARCHAR(102) DEFAULT CONCAT('%', TRIM(COALESCE(p_termo, '')), '%');
    DECLARE v_digitos VARCHAR(100) DEFAULT fn_somente_digitos(p_termo);

    (SELECT 'CLIENTE' AS tipo, c.ID AS id, c.NOME AS titulo, CONCAT(ci.NOME, ' · ', c.TELEFONE) AS detalhe, '' AS extra
     FROM CLIENTE c JOIN CIDADE ci ON ci.ID = c.CIDADE_ID
     WHERE c.NOME LIKE v_texto OR (v_digitos <> '' AND CHAR_LENGTH(v_digitos) >= 4 AND (c.TELEFONE LIKE CONCAT('%', v_digitos, '%') OR c.DOCUMENTO LIKE CONCAT('%', v_digitos, '%')))
     ORDER BY c.NOME LIMIT 5)
    UNION ALL
    (SELECT 'PRODUTO', p.ID, p.NOME, CONCAT(cat.NOME, ' · R$ ', FORMAT(p.PRECO_UNITARIO, 2, 'pt_BR')), cat.TIPO
     FROM PRODUTO p JOIN PRODUTO_CATEGORIA cat ON cat.ID = p.CATEGORIA_ID
     WHERE p.NOME LIKE v_texto OR (v_digitos <> '' AND CHAR_LENGTH(v_digitos) >= 4 AND p.CODIGO_BARRAS LIKE CONCAT('%', v_digitos, '%'))
     ORDER BY p.NOME LIMIT 6)
    UNION ALL
    (SELECT 'PEDIDO', v.ID, CONCAT('Pedido #', v.ID),
            CONCAT(CASE v.ETAPA WHEN 'NOVO' THEN 'Novo' WHEN 'PREPARO' THEN 'Em preparo' WHEN 'PRONTO' THEN 'Pronto'
                                WHEN 'ENTREGUE' THEN 'Entregue' ELSE 'Cancelado' END,
                   ' · R$ ', FORMAT(v.TOTAL, 2, 'pt_BR'), ' · ', DATE_FORMAT(v.DATA_CADASTRO, '%d/%m %H:%i')),
            v.ETAPA
     FROM VENDA v
     WHERE v_digitos <> '' AND CHAR_LENGTH(v_digitos) <= 9 AND v.ID = CAST(v_digitos AS UNSIGNED)
     LIMIT 1)
    UNION ALL
    (SELECT 'FORNECEDOR', f.ID, f.NOME, CONCAT('Fornecedor · ', f.TELEFONE), ''
     FROM FORNECEDOR f
     WHERE p_financeiro = 1 AND f.NOME LIKE v_texto
     ORDER BY f.NOME LIMIT 4);
END//

-- ==========================================================================
-- 10. AUDITORIA
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_atividade_registrar//
CREATE PROCEDURE sp_atividade_registrar(
    IN p_usuario_id INT,
    IN p_usuario_nome VARCHAR(255),
    IN p_modulo VARCHAR(40),
    IN p_acao VARCHAR(40),
    IN p_descricao VARCHAR(500),
    IN p_ip VARCHAR(45)
)
BEGIN
    INSERT INTO LOG_ATIVIDADE (USUARIO_ID, USUARIO_NOME, MODULO, ACAO, DESCRICAO, IP)
    VALUES (NULLIF(p_usuario_id, 0), LEFT(COALESCE(p_usuario_nome, ''), 255), LEFT(p_modulo, 40),
            LEFT(COALESCE(p_acao, ''), 40), LEFT(p_descricao, 500), LEFT(COALESCE(p_ip, ''), 45));
END//

DROP PROCEDURE IF EXISTS sp_atividade_listar//
CREATE PROCEDURE sp_atividade_listar(
    IN p_usuario_id INT,
    IN p_modulo VARCHAR(40),
    IN p_busca VARCHAR(255),
    IN p_de DATE,
    IN p_ate DATE,
    IN p_limite INT,
    IN p_deslocamento INT
)
BEGIN
    DECLARE v_texto VARCHAR(257) DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');

    SELECT l.ID AS id, DATE_FORMAT(l.DATA, '%d/%m/%Y') AS data, DATE_FORMAT(l.DATA, '%H:%i:%s') AS hora,
           l.USUARIO_ID AS usuario_id, l.USUARIO_NOME AS usuario, l.MODULO AS modulo, l.ACAO AS acao,
           l.DESCRICAO AS descricao, l.IP AS ip
    FROM LOG_ATIVIDADE l
    WHERE l.DATA >= p_de AND l.DATA < DATE_ADD(p_ate, INTERVAL 1 DAY)
      AND (p_usuario_id = 0 OR l.USUARIO_ID = p_usuario_id)
      AND (TRIM(COALESCE(p_modulo, '')) = '' OR l.MODULO = p_modulo)
      AND (TRIM(COALESCE(p_busca, '')) = '' OR l.DESCRICAO LIKE v_texto OR l.USUARIO_NOME LIKE v_texto)
    ORDER BY l.ID DESC
    LIMIT p_limite OFFSET p_deslocamento;
END//

DROP PROCEDURE IF EXISTS sp_atividade_contagem//
CREATE PROCEDURE sp_atividade_contagem(
    IN p_usuario_id INT,
    IN p_modulo VARCHAR(40),
    IN p_busca VARCHAR(255),
    IN p_de DATE,
    IN p_ate DATE
)
BEGIN
    DECLARE v_texto VARCHAR(257) DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');

    SELECT COUNT(*) AS total,
           COUNT(DISTINCT l.USUARIO_ID) AS usuarios,
           COALESCE(SUM(l.ACAO = 'login'), 0) AS acessos
    FROM LOG_ATIVIDADE l
    WHERE l.DATA >= p_de AND l.DATA < DATE_ADD(p_ate, INTERVAL 1 DAY)
      AND (p_usuario_id = 0 OR l.USUARIO_ID = p_usuario_id)
      AND (TRIM(COALESCE(p_modulo, '')) = '' OR l.MODULO = p_modulo)
      AND (TRIM(COALESCE(p_busca, '')) = '' OR l.DESCRICAO LIKE v_texto OR l.USUARIO_NOME LIKE v_texto);
END//

DROP PROCEDURE IF EXISTS sp_atividade_modulos//
CREATE PROCEDURE sp_atividade_modulos()
BEGIN
    SELECT DISTINCT MODULO AS modulo FROM LOG_ATIVIDADE ORDER BY MODULO;
END//

-- ==========================================================================
-- 11. LOGIN SEGURO
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_login_buscar//
CREATE PROCEDURE sp_login_buscar(IN p_email VARCHAR(255))
BEGIN
    SELECT ID AS id, NOME AS nome, EMAIL AS email, SENHA AS senha, NIVEL AS nivel,
           COALESCE(CARGO, 'FUNCIONARIO') AS cargo, ATIVO AS ativo
    FROM USUARIOS
    WHERE EMAIL = TRIM(p_email)
    LIMIT 1;
END//

-- Falhas recentes do e-mail ou do IP (janela de 15 minutos).
DROP PROCEDURE IF EXISTS sp_login_falhas//
CREATE PROCEDURE sp_login_falhas(IN p_email VARCHAR(255), IN p_ip VARCHAR(45))
BEGIN
    SELECT
        (SELECT COUNT(*) FROM LOGIN_TENTATIVA
         WHERE EMAIL = TRIM(p_email) AND SUCESSO = 0 AND DATA >= NOW() - INTERVAL 15 MINUTE) AS falhas_email,
        (SELECT COUNT(*) FROM LOGIN_TENTATIVA
         WHERE IP = p_ip AND SUCESSO = 0 AND DATA >= NOW() - INTERVAL 15 MINUTE) AS falhas_ip;
END//

DROP PROCEDURE IF EXISTS sp_login_registrar//
CREATE PROCEDURE sp_login_registrar(IN p_email VARCHAR(255), IN p_ip VARCHAR(45), IN p_sucesso TINYINT)
BEGIN
    INSERT INTO LOGIN_TENTATIVA (EMAIL, IP, SUCESSO) VALUES (LEFT(TRIM(p_email), 255), LEFT(p_ip, 45), IF(p_sucesso = 1, 1, 0));

    -- acesso certo zera o contador desse e-mail
    IF p_sucesso = 1 THEN
        DELETE FROM LOGIN_TENTATIVA WHERE EMAIL = TRIM(p_email) AND SUCESSO = 0;
    END IF;
    DELETE FROM LOGIN_TENTATIVA WHERE DATA < NOW() - INTERVAL 30 DAY;
END//

-- Registra o acesso e, se veio um hash novo (senha antiga em texto puro ou custo
-- desatualizado), troca a senha gravada pelo hash seguro.
DROP PROCEDURE IF EXISTS sp_login_sucesso//
CREATE PROCEDURE sp_login_sucesso(IN p_usuario_id INT, IN p_novo_hash VARCHAR(255))
BEGIN
    UPDATE USUARIOS
    SET ULTIMO_LOGIN = NOW(), ULTIMO_ACESSO = NOW(),
        SENHA = IF(COALESCE(p_novo_hash, '') = '', SENHA, p_novo_hash)
    WHERE ID = p_usuario_id;
END//

-- Chamado a cada página (no máximo 1x por minuto): mantém a "equipe online" e
-- devolve os dados atuais do usuário, para a sessão refletir mudanças de cargo.
DROP PROCEDURE IF EXISTS sp_usuario_acesso//
CREATE PROCEDURE sp_usuario_acesso(IN p_usuario_id INT)
BEGIN
    UPDATE USUARIOS SET ULTIMO_ACESSO = NOW() WHERE ID = p_usuario_id;
    SELECT NOME AS nome, NIVEL AS nivel, COALESCE(CARGO, 'FUNCIONARIO') AS cargo, ATIVO AS ativo
    FROM USUARIOS WHERE ID = p_usuario_id;
END//

-- ==========================================================================
-- 12. CONVITES DA EQUIPE
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_convite_criar//
CREATE PROCEDURE sp_convite_criar(
    IN p_codigo VARCHAR(20),
    IN p_cargo VARCHAR(50),
    IN p_nivel VARCHAR(12),
    IN p_criado_por INT,
    IN p_dias INT
)
BEGIN
    IF p_cargo NOT IN ('Atendente / Caixa', 'Cozinheiro', 'Entregador', 'Gerente') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cargo inválido.';
    END IF;
    IF p_nivel NOT IN ('ADMIN', 'FUNCIONARIO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nível de acesso inválido.';
    END IF;
    IF p_dias IS NULL OR p_dias < 1 OR p_dias > 30 THEN
        SET p_dias = 7;
    END IF;

    INSERT INTO convites_equipe (id_empresa, codigo, cargo, nivel_acesso, nivel_sistema, status, expira_em, criado_por)
    VALUES (1, p_codigo, p_cargo, IF(p_nivel = 'ADMIN', 3, 1), p_nivel, 'pendente',
            NOW() + INTERVAL p_dias DAY, NULLIF(p_criado_por, 0));

    SELECT LAST_INSERT_ID() AS id, p_codigo AS codigo, DATE_FORMAT(NOW() + INTERVAL p_dias DAY, '%d/%m/%Y') AS expira;
END//

DROP PROCEDURE IF EXISTS sp_convite_listar//
CREATE PROCEDURE sp_convite_listar()
BEGIN
    SELECT c.id, c.codigo, c.cargo, c.nivel_sistema AS nivel, c.status,
           DATE_FORMAT(c.data_criacao, '%d/%m/%Y') AS criado_em,
           DATE_FORMAT(c.expira_em, '%d/%m/%Y') AS expira_em,
           (c.status = 'pendente' AND c.expira_em < NOW()) AS expirado,
           u.NOME AS criado_por, us.NOME AS usado_por
    FROM convites_equipe c
    LEFT JOIN USUARIOS u ON u.ID = c.criado_por
    LEFT JOIN USUARIOS us ON us.ID = c.usado_por
    ORDER BY c.data_criacao DESC
    LIMIT 15;
END//

DROP PROCEDURE IF EXISTS sp_convite_revogar//
CREATE PROCEDURE sp_convite_revogar(IN p_id INT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM convites_equipe WHERE id = p_id AND status = 'pendente') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Convite não encontrado ou já utilizado.';
    END IF;

    UPDATE convites_equipe SET status = 'revogado' WHERE id = p_id;
END//

-- Cadastro do funcionário com o código do convite. Devolve o id do novo usuário.
DROP PROCEDURE IF EXISTS sp_convite_usar//
CREATE PROCEDURE sp_convite_usar(
    IN p_codigo VARCHAR(20),
    IN p_nome VARCHAR(255),
    IN p_email VARCHAR(255),
    IN p_senha_hash VARCHAR(255)
)
BEGIN
    DECLARE v_convite INT DEFAULT NULL;
    DECLARE v_cargo VARCHAR(50) DEFAULT NULL;
    DECLARE v_nivel VARCHAR(12) DEFAULT NULL;
    DECLARE v_usuario INT DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF TRIM(COALESCE(p_nome, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o seu nome completo.';
    END IF;
    IF TRIM(COALESCE(p_email, '')) = '' OR p_email NOT LIKE '%_@_%.__%' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe um e-mail válido.';
    END IF;

    START TRANSACTION;

    SELECT id, cargo, nivel_sistema INTO v_convite, v_cargo, v_nivel
    FROM convites_equipe
    WHERE codigo = UPPER(TRIM(p_codigo)) AND status = 'pendente' AND (expira_em IS NULL OR expira_em >= NOW())
    LIMIT 1
    FOR UPDATE;

    IF v_convite IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Código de convite inválido, expirado ou já utilizado.';
    END IF;
    IF EXISTS (SELECT 1 FROM USUARIOS WHERE EMAIL = TRIM(p_email)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe uma conta com esse e-mail.';
    END IF;

    INSERT INTO USUARIOS (NOME, EMAIL, SENHA, NIVEL, CARGO, ATIVO)
    VALUES (TRIM(p_nome), LOWER(TRIM(p_email)), p_senha_hash, v_nivel, v_cargo, 'ATIVO');
    SET v_usuario = LAST_INSERT_ID();

    UPDATE convites_equipe SET status = 'usado', usado_por = v_usuario WHERE id = v_convite;

    COMMIT;

    SELECT v_usuario AS id, v_cargo AS cargo;
END//

DELIMITER ;
