-- ==========================================================================
-- DADOS DE DEMONSTRAÇÃO (opcional)
--
-- Gera um restaurante "vivo" para apresentar o sistema: 60 dias de vendas com
-- sazonalidade (segunda fechado, fim de semana mais cheio), pedidos em
-- andamento agora, mesas ocupadas, compras com parcelas, despesas fixas,
-- ficha técnica dos lanches e alertas de estoque e validade.
--
-- ATENÇÃO: apaga as vendas, compras, lançamentos e movimentações atuais
-- (os cadastros de clientes, produtos, fornecedores e usuários são mantidos).
-- Todas as datas são relativas ao dia em que o script é executado.
-- ==========================================================================

USE tcc;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- 1. Limpa o movimento
-- --------------------------------------------------------------------------
DELETE FROM CONTA_RECEBER;
DELETE FROM VENDA_ITEM;
DELETE FROM VENDA;
DELETE FROM CONTA_PAGAR;
DELETE FROM COMPRA_ITEM;
DELETE FROM COMPRA;
DELETE FROM MOVIMENTACAO_CAIXA;
DELETE FROM MOVIMENTACAO_ESTOQUE;
DELETE FROM LOG_ATIVIDADE;
DELETE FROM LOGIN_TENTATIVA;
DELETE FROM FICHA_TECNICA;
ALTER TABLE VENDA AUTO_INCREMENT = 1;
ALTER TABLE COMPRA AUTO_INCREMENT = 1;
ALTER TABLE CONTA_RECEBER AUTO_INCREMENT = 1;
ALTER TABLE CONTA_PAGAR AUTO_INCREMENT = 1;
ALTER TABLE MOVIMENTACAO_CAIXA AUTO_INCREMENT = 1;
ALTER TABLE MOVIMENTACAO_ESTOQUE AUTO_INCREMENT = 1;
ALTER TABLE LOG_ATIVIDADE AUTO_INCREMENT = 1;

-- --------------------------------------------------------------------------
-- 2. Empresa, equipe e preferências
-- --------------------------------------------------------------------------
INSERT INTO EMPRESA (ID, NOME_FANTASIA, RAZAO_SOCIAL, CNPJ, TELEFONE, EMAIL, ENDERECO, ENDERECO_NUMERO, ESTADO, CIDADE)
VALUES (1, 'XTEC Burger & Grill', 'XTEC Alimentação LTDA', '12.345.678/0001-90', '11987654321',
        'contato@xtec.com.br', 'Av. Paulista, Bela Vista', '1500', 'SP', 'São Paulo')
ON DUPLICATE KEY UPDATE NOME_FANTASIA = VALUES(NOME_FANTASIA), RAZAO_SOCIAL = VALUES(RAZAO_SOCIAL), CNPJ = VALUES(CNPJ),
    TELEFONE = VALUES(TELEFONE), EMAIL = VALUES(EMAIL), ENDERECO = VALUES(ENDERECO),
    ENDERECO_NUMERO = VALUES(ENDERECO_NUMERO), ESTADO = VALUES(ESTADO), CIDADE = VALUES(CIDADE);

UPDATE USUARIOS SET NOME = 'João Pereira', CARGO = 'Atendente / Caixa' WHERE EMAIL = 'func1@tcc.com';
UPDATE USUARIOS SET NOME = 'Maria Oliveira', CARGO = 'Atendente / Caixa' WHERE EMAIL = 'func2@tcc.com';
UPDATE USUARIOS SET NOME = 'Carlos Mendes', CARGO = 'Gerente' WHERE EMAIL = 'func3@tcc.com';
UPDATE USUARIOS SET NOME = 'Ana Souza', CARGO = 'Cozinheiro' WHERE EMAIL = 'func4@tcc.com';
UPDATE USUARIOS SET NOME = 'Pedro Lima', CARGO = 'Entregador' WHERE EMAIL = 'func5@tcc.com';
UPDATE USUARIOS SET ULTIMO_ACESSO = NOW() - INTERVAL 4 MINUTE WHERE EMAIL IN ('func1@tcc.com', 'func4@tcc.com');

INSERT INTO CONFIGURACAO (USUARIO_ID, CHAVE, VALOR) VALUES (1, 'notif_contas', '1')
ON DUPLICATE KEY UPDATE VALOR = VALUES(VALOR);

-- --------------------------------------------------------------------------
-- 3. Estoque: níveis e validades relativos a hoje
-- --------------------------------------------------------------------------
UPDATE PRODUTO p
JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
SET p.ESTOQUE = p.ESTOQUE_MINIMO * 3,
    p.DATA_VALIDADE = IF(p.DATA_VALIDADE IS NULL, NULL, CURDATE() + INTERVAL (8 + (p.ID * 7) % 40) DAY)
WHERE c.TIPO = 'INSUMO';

-- Lanches e sobremesas são preparados na hora: sem estoque próprio
UPDATE PRODUTO p
JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
SET p.ESTOQUE = 0, p.ESTOQUE_MINIMO = 0, p.DATA_VALIDADE = NULL, p.CONTROLA_ESTOQUE = 0
WHERE c.NOME IN ('Lanches', 'Sobremesas');

-- Bebidas são revenda: descontam o próprio estoque
UPDATE PRODUTO p
JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
SET p.CONTROLA_ESTOQUE = 1, p.ESTOQUE = p.ESTOQUE_MINIMO * 3,
    p.DATA_VALIDADE = CURDATE() + INTERVAL (60 + p.ID * 5) DAY
WHERE c.NOME = 'Bebidas';

-- Situações para os alertas aparecerem na apresentação
UPDATE PRODUTO SET ESTOQUE = 28 WHERE NOME = 'Alface Americana';                       -- abaixo do mínimo (50)
UPDATE PRODUTO SET ESTOQUE = 22 WHERE NOME = 'Bacon em Tiras';                         -- abaixo do mínimo (30)
UPDATE PRODUTO SET ESTOQUE = 35 WHERE NOME = 'Guaraná Antarctica Lata';                -- abaixo do mínimo (40)
UPDATE PRODUTO SET DATA_VALIDADE = CURDATE() + INTERVAL 2 DAY WHERE NOME = 'Tomate';    -- vence em breve
UPDATE PRODUTO SET DATA_VALIDADE = CURDATE() + INTERVAL 1 DAY WHERE NOME = 'Pão de Forma';
UPDATE PRODUTO SET DATA_VALIDADE = CURDATE() - INTERVAL 1 DAY WHERE NOME = 'Suco de Laranja 500ml'; -- vencido

-- --------------------------------------------------------------------------
-- 4. Ficha técnica (baixa automática de insumos)
-- --------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_ficha;
CREATE TEMPORARY TABLE tmp_ficha (produto VARCHAR(200), insumo VARCHAR(200), qtd INT);
INSERT INTO tmp_ficha VALUES
('X-Burguer Clássico', 'Pão de Hambúrguer', 1), ('X-Burguer Clássico', 'Carne Bovina Blend 150g', 1),
('X-Burguer Clássico', 'Queijo Cheddar Fatia', 1), ('X-Burguer Clássico', 'Alface Americana', 1),
('X-Burguer Clássico', 'Tomate', 1), ('X-Burguer Clássico', 'Molho Especial XTEC', 1),
('X-Burguer Clássico', 'Caixa Hambúrguer Média', 1),
('X-Burguer Duplo', 'Pão de Hambúrguer', 1), ('X-Burguer Duplo', 'Carne Bovina Blend 150g', 2),
('X-Burguer Duplo', 'Queijo Cheddar Fatia', 2), ('X-Burguer Duplo', 'Bacon em Tiras', 2),
('X-Burguer Duplo', 'Molho Especial XTEC', 1), ('X-Burguer Duplo', 'Caixa Hambúrguer Média', 1),
('Combo XTEC Especial', 'Pão de Hambúrguer', 1), ('Combo XTEC Especial', 'Carne Bovina Blend 150g', 1),
('Combo XTEC Especial', 'Queijo Cheddar Fatia', 1), ('Combo XTEC Especial', 'Alface Americana', 1),
('Combo XTEC Especial', 'Tomate', 1), ('Combo XTEC Especial', 'Molho Especial XTEC', 1),
('Combo XTEC Especial', 'Caixa Hambúrguer Média', 1), ('Combo XTEC Especial', 'Caixa Batata Frita', 1),
('Combo XTEC Especial', 'Copo Descartável 300ml', 1),
('Batata Frita GG', 'Caixa Batata Frita', 1), ('Batata Frita P', 'Caixa Batata Frita', 1),
('Misto Quente', 'Presunto Fatia', 2), ('Misto Quente', 'Queijo Mussarela Fatia', 2),
('X-Salada', 'Pão de Hambúrguer', 1), ('X-Salada', 'Carne Bovina Blend 150g', 1),
('X-Salada', 'Queijo Mussarela Fatia', 1), ('X-Salada', 'Alface Americana', 1),
('X-Salada', 'Tomate', 1), ('X-Salada', 'Molho Especial XTEC', 1), ('X-Salada', 'Caixa Hambúrguer Média', 1),
('Milk Shake Ovomaltine', 'Copo Descartável 300ml', 1), ('Açaí 500ml', 'Copo Descartável 300ml', 1);

INSERT INTO FICHA_TECNICA (PRODUTO_ID, INSUMO_ID, QUANTIDADE)
SELECT p.ID, i.ID, t.qtd
FROM tmp_ficha t
JOIN PRODUTO p ON p.NOME = t.produto
JOIN PRODUTO i ON i.NOME = t.insumo;
DROP TEMPORARY TABLE tmp_ficha;

-- --------------------------------------------------------------------------
-- 5. Gerador de pedidos
-- --------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_produtos;
CREATE TEMPORARY TABLE tmp_demo_produtos (ORDEM INT AUTO_INCREMENT PRIMARY KEY, PRODUTO_ID INT, PRECO DECIMAL(10, 2));

-- Popularidade: cada produto entra N vezes no sorteio
DROP TEMPORARY TABLE IF EXISTS tmp_peso;
CREATE TEMPORARY TABLE tmp_peso (nome VARCHAR(200), peso INT);
INSERT INTO tmp_peso VALUES
('X-Burguer Clássico', 14), ('X-Burguer Duplo', 9), ('Combo XTEC Especial', 12), ('Batata Frita GG', 8),
('Batata Frita P', 6), ('Misto Quente', 3), ('X-Salada', 7), ('Coca-Cola Lata 350ml', 12), ('Coca-Cola 2L', 4),
('Guaraná Antarctica Lata', 7), ('Suco de Laranja 500ml', 4), ('Água Mineral 500ml', 5),
('Cerveja Heineken Long Neck', 6), ('Petit Gateau', 4), ('Açaí 500ml', 4), ('Milk Shake Ovomaltine', 5);

DROP PROCEDURE IF EXISTS xtec_demo_sortear;
DELIMITER //
CREATE PROCEDURE xtec_demo_sortear()
BEGIN
    DECLARE v_fim INT DEFAULT 0;
    DECLARE v_id INT;
    DECLARE v_preco DECIMAL(10, 2);
    DECLARE v_peso INT;
    DECLARE v_i INT;
    DECLARE c CURSOR FOR
        SELECT p.ID, p.PRECO_UNITARIO, t.peso FROM tmp_peso t JOIN PRODUTO p ON p.NOME = t.nome;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;

    OPEN c;
    leitura: LOOP
        FETCH c INTO v_id, v_preco, v_peso;
        IF v_fim = 1 THEN LEAVE leitura; END IF;
        SET v_i = 0;
        WHILE v_i < v_peso DO
            INSERT INTO tmp_demo_produtos (PRODUTO_ID, PRECO) VALUES (v_id, v_preco);
            SET v_i = v_i + 1;
        END WHILE;
    END LOOP;
    CLOSE c;
END//

-- Cria um pedido com 1 a 4 itens sorteados. p_etapa define o estado final.
DROP PROCEDURE IF EXISTS xtec_demo_pedido//
CREATE PROCEDURE xtec_demo_pedido(IN p_data DATETIME, IN p_etapa VARCHAR(10), IN p_tipo VARCHAR(10), IN p_mesa VARCHAR(20))
BEGIN
    DECLARE v_venda INT;
    DECLARE v_itens INT DEFAULT 1 + FLOOR(RAND() * 3.4);
    DECLARE v_n INT DEFAULT 0;
    DECLARE v_total_sorteio INT;
    DECLARE v_sorteio INT;
    DECLARE v_prod INT;
    DECLARE v_preco DECIMAL(10, 2);
    DECLARE v_qtd INT;
    DECLARE v_valor DECIMAL(10, 2);
    DECLARE v_desconto DECIMAL(10, 2) DEFAULT 0;
    DECLARE v_cliente INT DEFAULT NULL;
    DECLARE v_func INT;
    DECLARE v_forma VARCHAR(10);
    DECLARE v_r DOUBLE DEFAULT RAND();
    DECLARE v_final TINYINT DEFAULT p_etapa IN ('ENTREGUE', 'CANCELADO');

    SELECT COUNT(*) INTO v_total_sorteio FROM tmp_demo_produtos;

    IF p_tipo = 'DELIVERY' OR (p_tipo = 'BALCAO' AND RAND() < 0.3) OR (p_tipo = 'SALAO' AND RAND() < 0.2) THEN
        SELECT ID INTO v_cliente FROM CLIENTE WHERE STATUS = 'ATIVO' ORDER BY RAND() LIMIT 1;
    END IF;

    SELECT ID INTO v_func FROM USUARIOS
    WHERE ATIVO = 'ATIVO' AND (CARGO = 'Atendente / Caixa' OR (RAND() < 0.15 AND CARGO IN ('Gerente', 'ADMIN')))
    ORDER BY RAND() LIMIT 1;
    IF v_func IS NULL THEN
        SELECT ID INTO v_func FROM USUARIOS ORDER BY ID LIMIT 1;
    END IF;

    SET v_forma = CASE WHEN v_r < 0.42 THEN 'PIX' WHEN v_r < 0.72 THEN 'CREDITO' WHEN v_r < 0.90 THEN 'DEBITO' ELSE 'DINHEIRO' END;

    INSERT INTO VENDA (VALOR, DESCONTO, TOTAL, CLIENTE_ID, FUNCIONARIO_ID, DATA_CADASTRO, FORMA_PAGAMENTO, STATUS,
                       TIPO_PEDIDO, MESA, ETAPA, DATA_ETAPA, ESTOQUE_BAIXADO)
    VALUES (0, 0, 0, v_cliente, v_func, p_data, v_forma,
            CASE p_etapa WHEN 'ENTREGUE' THEN 'FINALIZADA' WHEN 'CANCELADO' THEN 'CANCELADA' ELSE 'EM_ABERTO' END,
            p_tipo, IF(p_tipo = 'SALAO', p_mesa, NULL), p_etapa,
            IF(v_final, p_data + INTERVAL (14 + FLOOR(RAND() * 30)) MINUTE, p_data + INTERVAL FLOOR(RAND() * 3) MINUTE), 0);
    SET v_venda = LAST_INSERT_ID();

    WHILE v_n < v_itens DO
        SET v_sorteio = 1 + FLOOR(RAND() * v_total_sorteio);
        SELECT PRODUTO_ID, PRECO INTO v_prod, v_preco FROM tmp_demo_produtos WHERE ORDEM = v_sorteio;
        SET v_qtd = IF(RAND() < 0.78, 1, 2);
        INSERT INTO VENDA_ITEM (VENDA_ID, PRODUTO_ID, QUANTIDADE, PRECO_UNITARIO, VALOR, DESCONTO, TOTAL, DATA_CADASTRO, STATUS)
        VALUES (v_venda, v_prod, v_qtd, v_preco, v_qtd * v_preco, 0, v_qtd * v_preco, p_data,
                CASE p_etapa WHEN 'ENTREGUE' THEN 'FINALIZADA' WHEN 'CANCELADO' THEN 'CANCELADA' ELSE 'EM_ABERTO' END)
        ON DUPLICATE KEY UPDATE QUANTIDADE = QUANTIDADE + v_qtd, VALOR = QUANTIDADE * PRECO_UNITARIO, TOTAL = QUANTIDADE * PRECO_UNITARIO;
        SET v_n = v_n + 1;
    END WHILE;

    SELECT SUM(TOTAL) INTO v_valor FROM VENDA_ITEM WHERE VENDA_ID = v_venda;
    IF RAND() < 0.09 THEN
        SET v_desconto = ROUND(v_valor * (0.05 + RAND() * 0.05), 0);
    END IF;

    UPDATE VENDA SET VALOR = v_valor, DESCONTO = v_desconto, TOTAL = v_valor - v_desconto,
                     VALOR_RECEBIDO = IF(v_forma = 'DINHEIRO' AND p_etapa = 'ENTREGUE', CEIL((v_valor - v_desconto) / 10) * 10, NULL)
    WHERE ID = v_venda;

    INSERT INTO CONTA_RECEBER (VENDA_ID, DATA_VENCIMENTO, DATA_PAGAMENTO, VALOR, DESCONTO, TOTAL, STATUS, DATA_CADASTRO)
    VALUES (v_venda, DATE(p_data), IF(p_etapa = 'ENTREGUE', DATE(p_data), NULL), v_valor, v_desconto, v_valor - v_desconto,
            CASE p_etapa WHEN 'ENTREGUE' THEN 'PAGO' WHEN 'CANCELADO' THEN 'CANCELADO' ELSE 'PENDENTE' END, p_data);
END//

-- Histórico: p_dias dias para trás (segunda-feira a loja fica fechada)
DROP PROCEDURE IF EXISTS xtec_demo_historico//
CREATE PROCEDURE xtec_demo_historico(IN p_dias INT)
BEGIN
    DECLARE v_d INT DEFAULT p_dias;
    DECLARE v_dia DATE;
    DECLARE v_semana INT;
    DECLARE v_qtd INT;
    DECLARE v_i INT;
    DECLARE v_hora DATETIME;
    DECLARE v_r DOUBLE;
    DECLARE v_tipo VARCHAR(10);

    WHILE v_d >= 1 DO
        SET v_dia = CURDATE() - INTERVAL v_d DAY;
        SET v_semana = DAYOFWEEK(v_dia); -- 1 domingo ... 7 sábado

        IF v_semana <> 2 THEN
            SET v_qtd = ROUND((13 + RAND() * 9)
                              * CASE v_semana WHEN 6 THEN 1.55 WHEN 7 THEN 1.75 WHEN 1 THEN 1.35 WHEN 5 THEN 1.15 ELSE 1 END
                              * (0.85 + (p_dias - v_d) / p_dias * 0.3));
            SET v_i = 0;
            WHILE v_i < v_qtd DO
                IF v_semana IN (1, 7) AND RAND() < 0.4 THEN
                    SET v_hora = TIMESTAMP(v_dia, '12:00:00') + INTERVAL FLOOR(RAND() * 180) MINUTE;
                ELSE
                    SET v_hora = TIMESTAMP(v_dia, '18:00:00') + INTERVAL FLOOR(POW(RAND(), 1.3) * 300) MINUTE;
                END IF;
                SET v_r = RAND();
                SET v_tipo = CASE WHEN v_r < 0.5 THEN 'SALAO' WHEN v_r < 0.74 THEN 'BALCAO' ELSE 'DELIVERY' END;
                CALL xtec_demo_pedido(v_hora, IF(RAND() < 0.035, 'CANCELADO', 'ENTREGUE'), v_tipo, CAST(1 + FLOOR(RAND() * 10) AS CHAR));
                SET v_i = v_i + 1;
            END WHILE;
        END IF;

        SET v_d = v_d - 1;
    END WHILE;
END//
DELIMITER ;

CALL xtec_demo_sortear();
CALL xtec_demo_historico(60);

-- Hoje: vendas já entregues ao longo do dia...
DROP PROCEDURE IF EXISTS xtec_demo_hoje;
DELIMITER //
CREATE PROCEDURE xtec_demo_hoje()
BEGIN
    DECLARE v_minutos INT DEFAULT TIMESTAMPDIFF(MINUTE, CURDATE(), NOW());
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_r DOUBLE;

    IF v_minutos > 90 THEN
        WHILE v_i < 16 DO
            SET v_r = RAND();
            CALL xtec_demo_pedido(CURDATE() + INTERVAL FLOOR(RAND() * (v_minutos - 60)) MINUTE, 'ENTREGUE',
                                  CASE WHEN v_r < 0.5 THEN 'SALAO' WHEN v_r < 0.75 THEN 'BALCAO' ELSE 'DELIVERY' END,
                                  CAST(1 + FLOOR(RAND() * 10) AS CHAR));
            SET v_i = v_i + 1;
        END WHILE;
    END IF;

    -- ...e pedidos em andamento agora (quadro, cozinha e mesas)
    CALL xtec_demo_pedido(NOW() - INTERVAL 2 MINUTE, 'NOVO', 'BALCAO', NULL);
    CALL xtec_demo_pedido(NOW() - INTERVAL 5 MINUTE, 'NOVO', 'SALAO', '2');
    CALL xtec_demo_pedido(NOW() - INTERVAL 9 MINUTE, 'NOVO', 'DELIVERY', NULL);
    CALL xtec_demo_pedido(NOW() - INTERVAL 14 MINUTE, 'PREPARO', 'SALAO', '4');
    CALL xtec_demo_pedido(NOW() - INTERVAL 27 MINUTE, 'PREPARO', 'SALAO', '7');
    CALL xtec_demo_pedido(NOW() - INTERVAL 33 MINUTE, 'PREPARO', 'DELIVERY', NULL);
    CALL xtec_demo_pedido(NOW() - INTERVAL 38 MINUTE, 'PRONTO', 'SALAO', '3');
    CALL xtec_demo_pedido(NOW() - INTERVAL 46 MINUTE, 'PRONTO', 'SALAO', '3');
    CALL xtec_demo_pedido(NOW() - INTERVAL 41 MINUTE, 'PRONTO', 'SALAO', '9');
    CALL xtec_demo_pedido(NOW() - INTERVAL 24 MINUTE, 'PRONTO', 'DELIVERY', NULL);
END//
DELIMITER ;
CALL xtec_demo_hoje();

UPDATE VENDA SET OBSERVACAO = 'Cliente pediu talheres extras' WHERE ETAPA = 'PREPARO' AND TIPO_PEDIDO = 'SALAO' AND MESA = '4';
UPDATE VENDA_ITEM vi JOIN VENDA v ON v.ID = vi.VENDA_ID
SET vi.OBSERVACAO = 'Sem cebola'
WHERE v.ETAPA = 'NOVO' AND v.TIPO_PEDIDO = 'SALAO' AND vi.PRODUTO_ID IN (SELECT ID FROM PRODUTO WHERE NOME LIKE 'X-%');

DROP PROCEDURE IF EXISTS xtec_demo_sortear;
DROP PROCEDURE IF EXISTS xtec_demo_pedido;
DROP PROCEDURE IF EXISTS xtec_demo_historico;
DROP PROCEDURE IF EXISTS xtec_demo_hoje;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_produtos;
DROP TEMPORARY TABLE IF EXISTS tmp_peso;

-- --------------------------------------------------------------------------
-- 6. Compras dos últimos 3 meses (com parcelas no Fluxo de Caixa)
-- --------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS xtec_demo_compras;
DELIMITER //
CREATE PROCEDURE xtec_demo_compras()
BEGIN
    DECLARE v_semana INT DEFAULT 12;
    DECLARE v_fornecedor INT;
    DECLARE v_compra INT;
    DECLARE v_data DATETIME;
    DECLARE v_total DECIMAL(10, 2);
    DECLARE v_venc DATE;
    DECLARE v_comprador INT;

    SELECT ID INTO v_comprador FROM USUARIOS WHERE CARGO = 'Gerente' ORDER BY ID LIMIT 1;
    IF v_comprador IS NULL THEN
        SET v_comprador = 1;
    END IF;

    WHILE v_semana >= 0 DO
        SET v_fornecedor = 1;
        WHILE v_fornecedor <= 5 DO
            IF EXISTS (SELECT 1 FROM FORNECEDOR WHERE ID = v_fornecedor)
               AND (v_fornecedor IN (1, 3) OR v_semana % 2 = v_fornecedor % 2) THEN
                SET v_data = TIMESTAMP(CURDATE() - INTERVAL (v_semana * 7 + v_fornecedor) DAY, '09:30:00');
                IF v_data < NOW() THEN
                    INSERT INTO COMPRA (VALOR, DESCONTO, TOTAL, FORNECEDOR_ID, FUNCIONARIO_ID, DATA_CADASTRO, STATUS, NUMERO_NF)
                    VALUES (0, 0, 0, v_fornecedor, v_comprador, v_data, 'FINALIZADA', CONCAT('NF-', 10200 + v_semana * 10 + v_fornecedor));
                    SET v_compra = LAST_INSERT_ID();

                    INSERT INTO COMPRA_ITEM (COMPRA_ID, PRODUTO_ID, QUANTIDADE, PRECO_UNITARIO, VALOR, DESCONTO, TOTAL, STATUS, DATA_CADASTRO)
                    SELECT v_compra, fp.PRODUTO_ID, q.qtd, fp.PRECO_FORNECEDOR, q.qtd * fp.PRECO_FORNECEDOR, 0,
                           q.qtd * fp.PRECO_FORNECEDOR, 'FINALIZADA', v_data
                    FROM FORNECEDOR_PRODUTO fp
                    JOIN PRODUTO p ON p.ID = fp.PRODUTO_ID
                    JOIN (SELECT ID, GREATEST(10, ROUND(ESTOQUE_MINIMO * (0.8 + RAND() * 1.2) / 10) * 10) AS qtd FROM PRODUTO) q ON q.ID = p.ID
                    WHERE fp.FORNECEDOR_ID = v_fornecedor AND fp.STATUS = 'ATIVO';

                    SELECT COALESCE(SUM(TOTAL), 0) INTO v_total FROM COMPRA_ITEM WHERE COMPRA_ID = v_compra;
                    IF v_total = 0 THEN
                        DELETE FROM COMPRA WHERE ID = v_compra;
                    ELSE
                        UPDATE COMPRA SET VALOR = v_total, TOTAL = v_total WHERE ID = v_compra;
                        SET v_venc = DATE(v_data) + INTERVAL IF(v_fornecedor = 1, 21, 14) DAY;
                        INSERT INTO CONTA_PAGAR (COMPRA_ID, PARCELA_QUANTIDADE, PARCELA_NUMERO, DATA_VENCIMENTO, DATA_PAGAMENTO,
                                                 VALOR, DESCONTO, TOTAL, STATUS, DATA_CADASTRO)
                        VALUES (v_compra, 1, 1, v_venc, IF(v_venc < CURDATE() - INTERVAL 2 DAY, v_venc, NULL), v_total, 0, v_total,
                                IF(v_venc < CURDATE() - INTERVAL 2 DAY, 'PAGO', 'PENDENTE'), v_data);
                    END IF;
                END IF;
            END IF;
            SET v_fornecedor = v_fornecedor + 1;
        END WHILE;
        SET v_semana = v_semana - 1;
    END WHILE;
END//
DELIMITER ;
CALL xtec_demo_compras();
DROP PROCEDURE IF EXISTS xtec_demo_compras;

-- Movimentações de estoque recentes (entradas das compras da semana e consumo)
INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, DATA_MOVIMENTACAO, OBSERVACAO)
SELECT ci.PRODUTO_ID, c.FUNCIONARIO_ID, 'ENTRADA', ci.QUANTIDADE, c.DATA_CADASTRO, CONCAT('Compra #', c.ID)
FROM COMPRA c JOIN COMPRA_ITEM ci ON ci.COMPRA_ID = c.ID
WHERE c.DATA_CADASTRO >= CURDATE() - INTERVAL 14 DAY;

INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, DATA_MOVIMENTACAO, OBSERVACAO)
SELECT p.ID, (SELECT ID FROM USUARIOS WHERE CARGO = 'Cozinheiro' ORDER BY ID LIMIT 1), 'SAIDA', 6,
       NOW() - INTERVAL 3 HOUR, 'Perda: produto amassado'
FROM PRODUTO p WHERE p.NOME = 'Tomate';
INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, DATA_MOVIMENTACAO, OBSERVACAO)
SELECT p.ID, (SELECT ID FROM USUARIOS WHERE CARGO = 'Gerente' ORDER BY ID LIMIT 1), 'ENTRADA', 24,
       NOW() - INTERVAL 5 HOUR, 'Reposição emergencial'
FROM PRODUTO p WHERE p.NOME = 'Coca-Cola Lata 350ml';

-- --------------------------------------------------------------------------
-- 7. Despesas fixas e receitas extras (lançamentos manuais)
-- --------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS xtec_demo_caixa;
DELIMITER //
CREATE PROCEDURE xtec_demo_caixa()
BEGIN
    DECLARE v_mes INT DEFAULT 3;
    DECLARE v_inicio DATE;
    DECLARE v_admin INT DEFAULT 1;

    SELECT ID INTO v_admin FROM USUARIOS WHERE NIVEL = 'ADMIN' ORDER BY ID LIMIT 1;

    WHILE v_mes >= 0 DO
        SET v_inicio = DATE_FORMAT(CURDATE() - INTERVAL v_mes MONTH, '%Y-%m-01');

        INSERT INTO MOVIMENTACAO_CAIXA (TIPO, DESCRICAO, CATEGORIA, VALOR, DATA_MOVIMENTACAO, DATA_PAGAMENTO, STATUS, USUARIO_ID)
        SELECT t.tipo, t.descricao, t.categoria, t.valor, t.dia,
               IF(t.dia < CURDATE(), t.dia, NULL), IF(t.dia < CURDATE(), 'PAGO', 'PENDENTE'), v_admin
        FROM (
            SELECT 'SAIDA' AS tipo, 'Aluguel do salão' AS descricao, 'Despesas fixas' AS categoria, 4500.00 AS valor, v_inicio + INTERVAL 4 DAY AS dia
            UNION ALL SELECT 'SAIDA', 'Folha de pagamento', 'Pessoal', 11800.00, v_inicio + INTERVAL 4 DAY
            UNION ALL SELECT 'SAIDA', 'Conta de energia', 'Despesas fixas', ROUND(820 + RAND() * 260, 2), v_inicio + INTERVAL 9 DAY
            UNION ALL SELECT 'SAIDA', 'Conta de água', 'Despesas fixas', ROUND(210 + RAND() * 90, 2), v_inicio + INTERVAL 11 DAY
            UNION ALL SELECT 'SAIDA', 'Internet e telefone', 'Despesas fixas', 189.90, v_inicio + INTERVAL 14 DAY
            UNION ALL SELECT 'SAIDA', 'Gás de cozinha', 'Insumos', ROUND(340 + RAND() * 120, 2), v_inicio + INTERVAL 19 DAY
            UNION ALL SELECT 'SAIDA', 'Anúncios em redes sociais', 'Marketing', 600.00, v_inicio + INTERVAL 24 DAY
            UNION ALL SELECT 'SAIDA', 'Simples Nacional', 'Impostos', ROUND(2100 + RAND() * 600, 2), v_inicio + INTERVAL 19 DAY
        ) t;

        SET v_mes = v_mes - 1;
    END WHILE;

    INSERT INTO MOVIMENTACAO_CAIXA (TIPO, DESCRICAO, CATEGORIA, VALOR, DATA_MOVIMENTACAO, DATA_PAGAMENTO, STATUS, USUARIO_ID) VALUES
    ('ENTRADA', 'Evento corporativo (buffet)', 'Receitas extras', 3200.00, CURDATE() - INTERVAL 18 DAY, CURDATE() - INTERVAL 18 DAY, 'PAGO', v_admin),
    ('ENTRADA', 'Aniversário na varanda', 'Receitas extras', 1450.00, CURDATE() + INTERVAL 6 DAY, NULL, 'PENDENTE', v_admin),
    ('SAIDA', 'Manutenção da chapa', 'Manutenção', 480.00, CURDATE() - INTERVAL 3 DAY, NULL, 'PENDENTE', v_admin);
END//
DELIMITER ;
CALL xtec_demo_caixa();
DROP PROCEDURE IF EXISTS xtec_demo_caixa;

-- --------------------------------------------------------------------------
-- 8. Histórico de atividades (auditoria)
-- --------------------------------------------------------------------------
INSERT INTO LOG_ATIVIDADE (USUARIO_ID, USUARIO_NOME, MODULO, ACAO, DESCRICAO, IP, DATA)
SELECT u.ID, u.NOME, t.modulo, t.acao, t.descricao, '127.0.0.1', NOW() - INTERVAL t.minutos MINUTE
FROM (
    SELECT 'admin@tcc.com' AS email, 'sessao' AS modulo, 'login' AS acao, 'Entrou no sistema' AS descricao, 300 AS minutos
    UNION ALL SELECT 'func3@tcc.com', 'sessao', 'login', 'Entrou no sistema', 260
    UNION ALL SELECT 'func3@tcc.com', 'compras', 'salvar', 'Compra registrada e estoque atualizado', 250
    UNION ALL SELECT 'func1@tcc.com', 'sessao', 'login', 'Entrou no sistema', 200
    UNION ALL SELECT 'func1@tcc.com', 'pdv', 'venda', 'Venda registrada no PDV', 120
    UNION ALL SELECT 'func4@tcc.com', 'sessao', 'login', 'Entrou no sistema', 90
    UNION ALL SELECT 'func4@tcc.com', 'estoque', 'movimentar', 'Saída registrada: Tomate', 180
    UNION ALL SELECT 'func3@tcc.com', 'cardapios', 'salvar', 'Item atualizado.', 45
    UNION ALL SELECT 'admin@tcc.com', 'configs', 'salvar', 'Configurações salvas.', 30
) t
JOIN USUARIOS u ON u.EMAIL = t.email;
