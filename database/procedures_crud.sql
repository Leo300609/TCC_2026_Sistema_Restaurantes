-- ==========================================================================
-- FUNÇÕES, VIEWS E PROCEDURES DOS CRUDs DO DASHBOARD
--
-- Toda a regra de dados dos módulos (Clientes, Cardápio, Estoque, Pedidos,
-- Fluxo de Caixa, Empresa e Configurações) fica aqui, no MySQL. O PHP só
-- valida o formato do que veio do formulário, confere a permissão do usuário
-- e chama as procedures (CALL sp_...).
--
-- Ordem de execução:
--   1. bancofeitonamoraltmjamovcs.sql   (estrutura base)
--   2. migracao_dashboard.sql
--   3. migracao_crud.sql                (colunas/tabelas novas)
--   4. procedures_crud.sql              (este arquivo)
--
-- Pode ser executado quantas vezes quiser: cada rotina é recriada.
--
-- Convenções
--   fn_*  funções (retornam um valor e são usadas dentro de consultas)
--   vw_*  views
--   sp_*  procedures (retornam linhas com SELECT)
--   Erros de regra de negócio usam SIGNAL SQLSTATE '45000' com a mensagem
--   que o PHP mostra ao usuário.
--   Procedures que gravam em várias tabelas abrem a própria transação. As de
--   Pedidos (criar/atualizar/item/totais) NÃO abrem: o PHP envolve a sequência
--   toda em uma transação única.
-- ==========================================================================

USE tcc;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //

-- ==========================================================================
-- 1. FUNÇÕES
-- ==========================================================================

-- Mantém só os números de um texto ("(11) 98765-4321" -> "11987654321").
DROP FUNCTION IF EXISTS fn_somente_digitos//
CREATE FUNCTION fn_somente_digitos(p_texto VARCHAR(255))
RETURNS VARCHAR(255)
DETERMINISTIC
BEGIN
    DECLARE v_i INT DEFAULT 1;
    DECLARE v_tamanho INT DEFAULT CHAR_LENGTH(COALESCE(p_texto, ''));
    DECLARE v_resultado VARCHAR(255) DEFAULT '';
    DECLARE v_c CHAR(1);

    WHILE v_i <= v_tamanho DO
        SET v_c = SUBSTRING(p_texto, v_i, 1);
        IF INSTR('0123456789', v_c) > 0 THEN
            SET v_resultado = CONCAT(v_resultado, v_c);
        END IF;
        SET v_i = v_i + 1;
    END WHILE;

    RETURN v_resultado;
END//

-- 12345678000190 -> 12.345.678/0001-90 (qualquer outro tamanho volta sem formatar).
DROP FUNCTION IF EXISTS fn_formatar_cnpj//
CREATE FUNCTION fn_formatar_cnpj(p_digitos VARCHAR(255))
RETURNS VARCHAR(18)
DETERMINISTIC
BEGIN
    IF p_digitos IS NULL OR CHAR_LENGTH(p_digitos) <> 14 THEN
        RETURN LEFT(COALESCE(p_digitos, ''), 18);
    END IF;

    RETURN CONCAT(
        SUBSTRING(p_digitos, 1, 2), '.', SUBSTRING(p_digitos, 3, 3), '.',
        SUBSTRING(p_digitos, 6, 3), '/', SUBSTRING(p_digitos, 9, 4), '-',
        SUBSTRING(p_digitos, 13, 2)
    );
END//

-- Lê uma configuração (USUARIO_ID 0 = valor global da operação).
DROP FUNCTION IF EXISTS fn_config//
CREATE FUNCTION fn_config(p_usuario INT, p_chave VARCHAR(60), p_padrao VARCHAR(255))
RETURNS VARCHAR(255)
READS SQL DATA
BEGIN
    DECLARE v_valor VARCHAR(255) DEFAULT NULL;

    SELECT VALOR INTO v_valor
    FROM CONFIGURACAO
    WHERE USUARIO_ID = p_usuario AND CHAVE = p_chave
    LIMIT 1;

    RETURN COALESCE(v_valor, p_padrao);
END//

-- Quantidade de pedidos (não cancelados) de um cliente.
DROP FUNCTION IF EXISTS fn_cliente_pedidos//
CREATE FUNCTION fn_cliente_pedidos(p_cliente_id INT)
RETURNS INT
READS SQL DATA
BEGIN
    RETURN (SELECT COUNT(*) FROM VENDA WHERE CLIENTE_ID = p_cliente_id AND STATUS <> 'CANCELADA');
END//

-- Total já pago pelo cliente (vendas finalizadas).
DROP FUNCTION IF EXISTS fn_cliente_total_gasto//
CREATE FUNCTION fn_cliente_total_gasto(p_cliente_id INT)
RETURNS DECIMAL(12, 2)
READS SQL DATA
BEGIN
    RETURN (SELECT COALESCE(SUM(TOTAL), 0) FROM VENDA WHERE CLIENTE_ID = p_cliente_id AND STATUS = 'FINALIZADA');
END//

-- Em quantos registros (vendas, compras, movimentos, fornecedores) o produto aparece.
DROP FUNCTION IF EXISTS fn_produto_usos//
CREATE FUNCTION fn_produto_usos(p_produto_id INT)
RETURNS INT
READS SQL DATA
BEGIN
    RETURN (SELECT COUNT(*) FROM VENDA_ITEM WHERE PRODUTO_ID = p_produto_id)
         + (SELECT COUNT(*) FROM COMPRA_ITEM WHERE PRODUTO_ID = p_produto_id)
         + (SELECT COUNT(*) FROM MOVIMENTACAO_ESTOQUE WHERE PRODUTO_ID = p_produto_id)
         + (SELECT COUNT(*) FROM FORNECEDOR_PRODUTO WHERE PRODUTO_ID = p_produto_id);
END//

-- Situação do item no estoque. Precedência: INATIVO > VENCIDO > CRITICO > VENCENDO > OK.
DROP FUNCTION IF EXISTS fn_produto_situacao//
CREATE FUNCTION fn_produto_situacao(
    p_status VARCHAR(10),
    p_estoque INT,
    p_minimo INT,
    p_validade DATE,
    p_dias INT
)
RETURNS VARCHAR(10)
READS SQL DATA
BEGIN
    IF p_status = 'INATIVO' THEN
        RETURN 'INATIVO';
    ELSEIF p_validade IS NOT NULL AND p_validade < CURDATE() THEN
        RETURN 'VENCIDO';
    ELSEIF p_estoque <= p_minimo THEN
        RETURN 'CRITICO';
    ELSEIF p_validade IS NOT NULL AND p_validade <= DATE_ADD(CURDATE(), INTERVAL p_dias DAY) THEN
        RETURN 'VENCENDO';
    END IF;

    RETURN 'OK';
END//

DELIMITER ;

-- ==========================================================================
-- 2. VIEWS DO FLUXO DE CAIXA
--    vw_fluxo_base  = entradas (vendas), saídas (compras) e lançamentos manuais
--    vw_fluxo_caixa = a mesma lista + "situacao" (pendente vencida = ATRASADO)
-- ==========================================================================
CREATE OR REPLACE VIEW vw_fluxo_base AS
SELECT 'ENTRADA' AS tipo, 'VENDA' AS origem, cr.ID AS id, v.ID AS referencia,
       cr.DATA_VENCIMENTO AS vencimento, cr.DATA_PAGAMENTO AS pagamento, cr.TOTAL AS total,
       cr.STATUS AS status, COALESCE(cl.NOME, 'Consumidor final') AS contraparte, '' AS categoria
FROM CONTA_RECEBER cr
JOIN VENDA v ON v.ID = cr.VENDA_ID
LEFT JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
UNION ALL
SELECT 'SAIDA', 'COMPRA', cp.ID, c.ID, cp.DATA_VENCIMENTO, cp.DATA_PAGAMENTO, cp.TOTAL,
       cp.STATUS, f.NOME, ''
FROM CONTA_PAGAR cp
JOIN COMPRA c ON c.ID = cp.COMPRA_ID
JOIN FORNECEDOR f ON f.ID = c.FORNECEDOR_ID
UNION ALL
SELECT m.TIPO, 'MANUAL', m.ID, m.ID, m.DATA_MOVIMENTACAO, m.DATA_PAGAMENTO, m.VALOR,
       m.STATUS, m.DESCRICAO, m.CATEGORIA
FROM MOVIMENTACAO_CAIXA m;

CREATE OR REPLACE VIEW vw_fluxo_caixa AS
SELECT b.tipo, b.origem, b.id, b.referencia, b.vencimento, b.pagamento, b.total, b.status,
       b.contraparte, b.categoria,
       CASE WHEN b.status = 'PENDENTE' AND b.vencimento < CURDATE() THEN 'ATRASADO' ELSE b.status END AS situacao
FROM vw_fluxo_base b;

DELIMITER //

-- ==========================================================================
-- 3. CLIENTES
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_estado_listar//
CREATE PROCEDURE sp_estado_listar()
BEGIN
    SELECT SIGLA AS sigla, NOME AS nome
    FROM ESTADO
    WHERE STATUS = 'ATIVO'
    ORDER BY NOME;
END//

DROP PROCEDURE IF EXISTS sp_cliente_listar//
CREATE PROCEDURE sp_cliente_listar(
    IN p_busca VARCHAR(255),
    IN p_status VARCHAR(10),
    IN p_limite INT,
    IN p_deslocamento INT
)
BEGIN
    DECLARE v_texto VARCHAR(257) DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    DECLARE v_digitos VARCHAR(255) DEFAULT fn_somente_digitos(p_busca);
    DECLARE v_numeros VARCHAR(257) DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');

    SELECT c.ID AS id, c.NOME AS nome, c.DOCUMENTO AS documento, c.ENDERECO AS endereco,
           c.ENDERECO_NUMERO AS endereco_numero, c.TELEFONE AS telefone, c.CONTATO AS contato,
           c.STATUS AS status, ci.NOME AS cidade, es.SIGLA AS estado,
           fn_cliente_pedidos(c.ID) AS pedidos,
           fn_cliente_total_gasto(c.ID) AS total_gasto
    FROM CLIENTE c
    JOIN CIDADE ci ON ci.ID = c.CIDADE_ID
    JOIN ESTADO es ON es.ID = ci.ESTADO_ID
    WHERE (TRIM(COALESCE(p_busca, '')) = ''
           OR c.NOME LIKE v_texto
           OR (v_digitos <> '' AND (c.DOCUMENTO LIKE v_numeros OR c.TELEFONE LIKE v_numeros)))
      AND (p_status NOT IN ('ATIVO', 'INATIVO') OR c.STATUS = p_status)
    ORDER BY c.NOME
    LIMIT p_limite OFFSET p_deslocamento;
END//

DROP PROCEDURE IF EXISTS sp_cliente_contagem//
CREATE PROCEDURE sp_cliente_contagem(IN p_busca VARCHAR(255))
BEGIN
    DECLARE v_texto VARCHAR(257) DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    DECLARE v_digitos VARCHAR(255) DEFAULT fn_somente_digitos(p_busca);
    DECLARE v_numeros VARCHAR(257) DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');

    SELECT COUNT(*) AS todos,
           COALESCE(SUM(c.STATUS = 'ATIVO'), 0) AS ativos,
           COALESCE(SUM(c.STATUS = 'INATIVO'), 0) AS inativos
    FROM CLIENTE c
    WHERE TRIM(COALESCE(p_busca, '')) = ''
       OR c.NOME LIKE v_texto
       OR (v_digitos <> '' AND (c.DOCUMENTO LIKE v_numeros OR c.TELEFONE LIKE v_numeros));
END//

DROP PROCEDURE IF EXISTS sp_cliente_obter//
CREATE PROCEDURE sp_cliente_obter(IN p_id INT)
BEGIN
    SELECT c.ID AS id, c.NOME AS nome, c.DOCUMENTO AS documento, c.ENDERECO AS endereco,
           c.ENDERECO_NUMERO AS endereco_numero, c.TELEFONE AS telefone, c.CONTATO AS contato,
           c.STATUS AS status, ci.NOME AS cidade, es.SIGLA AS estado
    FROM CLIENTE c
    JOIN CIDADE ci ON ci.ID = c.CIDADE_ID
    JOIN ESTADO es ON es.ID = ci.ESTADO_ID
    WHERE c.ID = p_id;
END//

-- p_id = 0 cadastra; p_id > 0 atualiza. Devolve o id do cliente.
DROP PROCEDURE IF EXISTS sp_cliente_salvar//
CREATE PROCEDURE sp_cliente_salvar(
    IN p_id INT,
    IN p_nome VARCHAR(255),
    IN p_documento VARCHAR(30),
    IN p_endereco VARCHAR(400),
    IN p_numero VARCHAR(20),
    IN p_telefone VARCHAR(30),
    IN p_contato VARCHAR(255),
    IN p_status VARCHAR(10),
    IN p_uf CHAR(2),
    IN p_cidade VARCHAR(200)
)
BEGIN
    DECLARE v_documento VARCHAR(255) DEFAULT fn_somente_digitos(p_documento);
    DECLARE v_telefone VARCHAR(255) DEFAULT fn_somente_digitos(p_telefone);
    DECLARE v_estado_id INT DEFAULT NULL;
    DECLARE v_cidade_id INT DEFAULT NULL;
    DECLARE v_id INT DEFAULT COALESCE(p_id, 0);

    IF TRIM(COALESCE(p_nome, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome do cliente.';
    END IF;
    IF CHAR_LENGTH(v_documento) NOT IN (11, 14) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe um CPF (11 números) ou CNPJ (14 números) válido.';
    END IF;
    IF CHAR_LENGTH(v_telefone) NOT IN (10, 11) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o telefone com DDD (10 ou 11 números).';
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

    IF v_id > 0 AND NOT EXISTS (SELECT 1 FROM CLIENTE WHERE ID = v_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cliente não encontrado.';
    END IF;
    IF EXISTS (SELECT 1 FROM CLIENTE WHERE DOCUMENTO = v_documento AND ID <> v_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe um cliente com esse CPF/CNPJ.';
    END IF;

    -- A cidade é cadastrada na hora se ainda não existir.
    SELECT ID INTO v_cidade_id FROM CIDADE WHERE NOME = TRIM(p_cidade) AND ESTADO_ID = v_estado_id LIMIT 1;
    IF v_cidade_id IS NULL THEN
        INSERT INTO CIDADE (NOME, ESTADO_ID) VALUES (TRIM(p_cidade), v_estado_id);
        SET v_cidade_id = LAST_INSERT_ID();
    END IF;

    IF v_id = 0 THEN
        INSERT INTO CLIENTE (NOME, DOCUMENTO, ENDERECO, ENDERECO_NUMERO, TELEFONE, CONTATO, STATUS, CIDADE_ID)
        VALUES (TRIM(p_nome), v_documento, TRIM(p_endereco), TRIM(p_numero), v_telefone,
                NULLIF(TRIM(COALESCE(p_contato, '')), ''), p_status, v_cidade_id);
        SET v_id = LAST_INSERT_ID();
    ELSE
        UPDATE CLIENTE
        SET NOME = TRIM(p_nome), DOCUMENTO = v_documento, ENDERECO = TRIM(p_endereco),
            ENDERECO_NUMERO = TRIM(p_numero), TELEFONE = v_telefone,
            CONTATO = NULLIF(TRIM(COALESCE(p_contato, '')), ''), STATUS = p_status, CIDADE_ID = v_cidade_id
        WHERE ID = v_id;
    END IF;

    SELECT v_id AS id;
END//

DROP PROCEDURE IF EXISTS sp_cliente_status//
CREATE PROCEDURE sp_cliente_status(IN p_id INT, IN p_status VARCHAR(10))
BEGIN
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Situação inválida.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM CLIENTE WHERE ID = p_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cliente não encontrado.';
    END IF;

    UPDATE CLIENTE SET STATUS = p_status WHERE ID = p_id;
END//

-- Exclui; se o cliente tem pedidos, apenas inativa. Devolve EXCLUIDO ou INATIVADO.
DROP PROCEDURE IF EXISTS sp_cliente_excluir//
CREATE PROCEDURE sp_cliente_excluir(IN p_id INT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM CLIENTE WHERE ID = p_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cliente não encontrado.';
    END IF;

    IF EXISTS (SELECT 1 FROM VENDA WHERE CLIENTE_ID = p_id) THEN
        UPDATE CLIENTE SET STATUS = 'INATIVO' WHERE ID = p_id;
        SELECT 'INATIVADO' AS resultado;
    ELSE
        DELETE FROM CLIENTE WHERE ID = p_id;
        SELECT 'EXCLUIDO' AS resultado;
    END IF;
END//

-- ==========================================================================
-- 4. CATEGORIAS DE PRODUTO (tipo CARDAPIO ou INSUMO)
-- ==========================================================================

-- p_tipo vazio devolve todas.
DROP PROCEDURE IF EXISTS sp_categoria_listar//
CREATE PROCEDURE sp_categoria_listar(IN p_tipo VARCHAR(10))
BEGIN
    SELECT c.ID AS id, c.NOME AS nome, c.DESCRICAO AS descricao, c.STATUS AS status, c.TIPO AS tipo,
           (SELECT COUNT(*) FROM PRODUTO p WHERE p.CATEGORIA_ID = c.ID) AS itens
    FROM PRODUTO_CATEGORIA c
    WHERE p_tipo NOT IN ('CARDAPIO', 'INSUMO') OR c.TIPO = p_tipo
    ORDER BY c.NOME;
END//

DROP PROCEDURE IF EXISTS sp_categoria_obter//
CREATE PROCEDURE sp_categoria_obter(IN p_id INT)
BEGIN
    SELECT c.ID AS id, c.NOME AS nome, c.DESCRICAO AS descricao, c.STATUS AS status, c.TIPO AS tipo
    FROM PRODUTO_CATEGORIA c
    WHERE c.ID = p_id;
END//

DROP PROCEDURE IF EXISTS sp_categoria_salvar//
CREATE PROCEDURE sp_categoria_salvar(
    IN p_id INT,
    IN p_nome VARCHAR(200),
    IN p_descricao VARCHAR(255),
    IN p_status VARCHAR(10),
    IN p_tipo VARCHAR(10)
)
BEGIN
    DECLARE v_id INT DEFAULT COALESCE(p_id, 0);
    DECLARE v_nome VARCHAR(200) DEFAULT TRIM(COALESCE(p_nome, ''));

    IF v_nome = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome da categoria.';
    END IF;
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        SET p_status = 'ATIVO';
    END IF;
    IF EXISTS (SELECT 1 FROM PRODUTO_CATEGORIA WHERE NOME = v_nome AND ID <> v_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe uma categoria com esse nome.';
    END IF;

    IF v_id = 0 THEN
        IF p_tipo NOT IN ('CARDAPIO', 'INSUMO') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de categoria inválido.';
        END IF;

        INSERT INTO PRODUTO_CATEGORIA (NOME, DESCRICAO, STATUS, TIPO)
        VALUES (v_nome, COALESCE(NULLIF(TRIM(COALESCE(p_descricao, '')), ''), v_nome), p_status, p_tipo);
        SET v_id = LAST_INSERT_ID();
    ELSE
        IF NOT EXISTS (SELECT 1 FROM PRODUTO_CATEGORIA WHERE ID = v_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Categoria não encontrada.';
        END IF;

        UPDATE PRODUTO_CATEGORIA
        SET NOME = v_nome,
            DESCRICAO = COALESCE(NULLIF(TRIM(COALESCE(p_descricao, '')), ''), v_nome),
            STATUS = p_status
        WHERE ID = v_id;
    END IF;

    SELECT v_id AS id;
END//

DROP PROCEDURE IF EXISTS sp_categoria_excluir//
CREATE PROCEDURE sp_categoria_excluir(IN p_id INT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM PRODUTO_CATEGORIA WHERE ID = p_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Categoria não encontrada.';
    END IF;
    IF EXISTS (SELECT 1 FROM PRODUTO WHERE CATEGORIA_ID = p_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A categoria tem itens. Mova ou remova os itens antes, ou apenas inative a categoria.';
    END IF;

    DELETE FROM PRODUTO_CATEGORIA WHERE ID = p_id;
END//

-- ==========================================================================
-- 5. PRODUTOS: CARDÁPIO E ESTOQUE (mesma tabela PRODUTO)
--    p_modo = 'CARDAPIO' (só campos de venda) ou 'ESTOQUE' (todos os campos)
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_cardapio_listar//
CREATE PROCEDURE sp_cardapio_listar(IN p_busca VARCHAR(255))
BEGIN
    DECLARE v_texto VARCHAR(257) DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');

    SELECT p.ID AS id, p.NOME AS nome, p.DESCRICAO AS descricao, p.PRECO_UNITARIO AS preco,
           p.STATUS AS status, p.CATEGORIA_ID AS categoria_id, c.NOME AS categoria,
           p.CODIGO_BARRAS AS codigo_barras, p.CONTROLA_ESTOQUE AS controla_estoque, p.ESTOQUE AS estoque,
           fn_produto_custo(p.ID) AS custo, fn_produto_produziveis(p.ID) AS produziveis,
           (SELECT COUNT(*) FROM FICHA_TECNICA f WHERE f.PRODUTO_ID = p.ID) AS insumos
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE c.TIPO = 'CARDAPIO'
      AND (TRIM(COALESCE(p_busca, '')) = '' OR p.NOME LIKE v_texto OR p.DESCRICAO LIKE v_texto)
    ORDER BY c.NOME, p.NOME;
END//

DROP PROCEDURE IF EXISTS sp_produto_obter//
CREATE PROCEDURE sp_produto_obter(IN p_id INT)
BEGIN
    SELECT p.ID AS id, p.NOME AS nome, p.DESCRICAO AS descricao, p.PRECO_UNITARIO AS preco,
           p.ESTOQUE AS estoque, p.ESTOQUE_MINIMO AS estoque_minimo, p.UNIDADE_MEDIDA AS unidade,
           p.DATA_VALIDADE AS validade, p.STATUS AS status, p.CATEGORIA_ID AS categoria_id,
           c.NOME AS categoria, c.TIPO AS categoria_tipo, p.CODIGO_BARRAS AS codigo_barras, p.NCM AS ncm,
           p.CONTROLA_ESTOQUE AS controla_estoque
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE p.ID = p_id;
END//

-- p_id = 0 cadastra; p_id > 0 atualiza. Devolve o id do produto.
-- p_estoque_inicial só vale no cadastro pelo estoque (gera a entrada "Estoque inicial").
DROP PROCEDURE IF EXISTS sp_produto_salvar//
CREATE PROCEDURE sp_produto_salvar(
    IN p_id INT,
    IN p_modo VARCHAR(10),
    IN p_nome VARCHAR(200),
    IN p_descricao VARCHAR(255),
    IN p_preco DECIMAL(10, 2),
    IN p_categoria_id INT,
    IN p_status VARCHAR(10),
    IN p_codigo_barras VARCHAR(30),
    IN p_ncm VARCHAR(30),
    IN p_unidade VARCHAR(10),
    IN p_estoque_minimo INT,
    IN p_validade DATE,
    IN p_estoque_inicial INT,
    IN p_usuario_id INT
)
BEGIN
    DECLARE v_id INT DEFAULT COALESCE(p_id, 0);
    DECLARE v_nome VARCHAR(200) DEFAULT TRIM(COALESCE(p_nome, ''));
    DECLARE v_codigo VARCHAR(255) DEFAULT NULLIF(fn_somente_digitos(p_codigo_barras), '');
    DECLARE v_ncm VARCHAR(255) DEFAULT NULLIF(fn_somente_digitos(p_ncm), '');
    DECLARE v_unidade VARCHAR(10) DEFAULT UPPER(TRIM(COALESCE(p_unidade, '')));
    DECLARE v_tipo VARCHAR(10) DEFAULT NULL;
    DECLARE v_inicial INT DEFAULT COALESCE(p_estoque_inicial, 0);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_modo NOT IN ('CARDAPIO', 'ESTOQUE') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Modo de cadastro inválido.';
    END IF;
    IF v_nome = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome do item.';
    END IF;
    IF TRIM(COALESCE(p_descricao, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a descrição do item.';
    END IF;
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        SET p_status = 'ATIVO';
    END IF;

    IF p_modo = 'CARDAPIO' AND COALESCE(p_preco, 0) < 0.01 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe um preço maior que zero.';
    END IF;
    IF p_modo = 'ESTOQUE' AND COALESCE(p_preco, 0) < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O preço não pode ser negativo.';
    END IF;

    IF v_codigo IS NOT NULL AND CHAR_LENGTH(v_codigo) > 14 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O código de barras deve ter até 14 números.';
    END IF;
    IF v_ncm IS NOT NULL AND CHAR_LENGTH(v_ncm) <> 8 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O NCM deve ter 8 números.';
    END IF;

    SELECT TIPO INTO v_tipo FROM PRODUTO_CATEGORIA WHERE ID = p_categoria_id LIMIT 1;
    IF v_tipo IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Categoria não encontrada.';
    END IF;
    IF p_modo = 'CARDAPIO' AND v_tipo <> 'CARDAPIO' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha uma categoria do cardápio.';
    END IF;

    IF v_id > 0 AND NOT EXISTS (SELECT 1 FROM PRODUTO WHERE ID = v_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item não encontrado.';
    END IF;
    IF EXISTS (SELECT 1 FROM PRODUTO WHERE NOME = v_nome AND ID <> v_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe um item com esse nome.';
    END IF;
    IF v_codigo IS NOT NULL AND EXISTS (SELECT 1 FROM PRODUTO WHERE CODIGO_BARRAS = v_codigo AND ID <> v_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esse código de barras já está cadastrado em outro item.';
    END IF;

    IF p_modo = 'ESTOQUE' THEN
        IF v_unidade = '' THEN
            SET v_unidade = 'UN';
        END IF;
        IF CHAR_LENGTH(v_unidade) > 6 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A unidade de medida deve ter até 6 caracteres.';
        END IF;
        IF COALESCE(p_estoque_minimo, 0) < 0 OR v_inicial < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'As quantidades não podem ser negativas.';
        END IF;
    END IF;

    START TRANSACTION;

    IF v_id = 0 THEN
        IF p_modo = 'CARDAPIO' THEN
            INSERT INTO PRODUTO (NOME, DESCRICAO, PRECO_UNITARIO, CATEGORIA_ID, STATUS, CODIGO_BARRAS)
            VALUES (v_nome, TRIM(p_descricao), p_preco, p_categoria_id, p_status, v_codigo);
        ELSE
            INSERT INTO PRODUTO (NOME, DESCRICAO, PRECO_UNITARIO, CATEGORIA_ID, STATUS, CODIGO_BARRAS, NCM,
                                 UNIDADE_MEDIDA, ESTOQUE, ESTOQUE_MINIMO, DATA_VALIDADE)
            VALUES (v_nome, TRIM(p_descricao), p_preco, p_categoria_id, p_status, v_codigo, v_ncm,
                    v_unidade, v_inicial, COALESCE(p_estoque_minimo, 0), p_validade);
        END IF;
        SET v_id = LAST_INSERT_ID();

        IF p_modo = 'ESTOQUE' AND v_inicial > 0 THEN
            INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, OBSERVACAO)
            VALUES (v_id, p_usuario_id, 'ENTRADA', v_inicial, 'Estoque inicial');
        END IF;
    ELSE
        IF p_modo = 'CARDAPIO' THEN
            UPDATE PRODUTO
            SET NOME = v_nome, DESCRICAO = TRIM(p_descricao), PRECO_UNITARIO = p_preco,
                CATEGORIA_ID = p_categoria_id, STATUS = p_status, CODIGO_BARRAS = v_codigo
            WHERE ID = v_id;
        ELSE
            UPDATE PRODUTO
            SET NOME = v_nome, DESCRICAO = TRIM(p_descricao), PRECO_UNITARIO = p_preco,
                CATEGORIA_ID = p_categoria_id, STATUS = p_status, CODIGO_BARRAS = v_codigo, NCM = v_ncm,
                UNIDADE_MEDIDA = v_unidade, ESTOQUE_MINIMO = COALESCE(p_estoque_minimo, 0), DATA_VALIDADE = p_validade
            WHERE ID = v_id;
        END IF;
    END IF;

    COMMIT;

    SELECT v_id AS id;
END//

-- Marca o item do cardápio como disponível (1) ou indisponível (0).
DROP PROCEDURE IF EXISTS sp_cardapio_disponibilidade//
CREATE PROCEDURE sp_cardapio_disponibilidade(IN p_id INT, IN p_disponivel TINYINT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM PRODUTO p JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
        WHERE p.ID = p_id AND c.TIPO = 'CARDAPIO'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item do cardápio não encontrado.';
    END IF;

    UPDATE PRODUTO SET STATUS = IF(p_disponivel = 1, 'ATIVO', 'INATIVO') WHERE ID = p_id;
END//

-- Exclui; se o item tem histórico (vendas, compras, movimentos) apenas inativa.
-- Devolve EXCLUIDO ou INATIVADO.
DROP PROCEDURE IF EXISTS sp_produto_excluir//
CREATE PROCEDURE sp_produto_excluir(IN p_id INT, IN p_modo VARCHAR(10))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM PRODUTO WHERE ID = p_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item não encontrado.';
    END IF;
    IF p_modo = 'CARDAPIO' AND NOT EXISTS (
        SELECT 1 FROM PRODUTO p JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
        WHERE p.ID = p_id AND c.TIPO = 'CARDAPIO'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item do cardápio não encontrado.';
    END IF;

    IF fn_produto_usos(p_id) > 0 THEN
        UPDATE PRODUTO SET STATUS = 'INATIVO' WHERE ID = p_id;
        SELECT 'INATIVADO' AS resultado;
    ELSE
        DELETE FROM PRODUTO WHERE ID = p_id;
        SELECT 'EXCLUIDO' AS resultado;
    END IF;
END//

-- ==========================================================================
-- 6. ESTOQUE
-- ==========================================================================

-- p_situacao: TODOS | CRITICO (inclui vencidos) | VENCENDO | OK
-- p_categoria: 0 = todas | p_dias = antecedência do alerta de validade
DROP PROCEDURE IF EXISTS sp_estoque_listar//
CREATE PROCEDURE sp_estoque_listar(
    IN p_busca VARCHAR(255),
    IN p_situacao VARCHAR(10),
    IN p_categoria INT,
    IN p_dias INT,
    IN p_limite INT,
    IN p_deslocamento INT
)
BEGIN
    DECLARE v_texto VARCHAR(257) DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    DECLARE v_digitos VARCHAR(255) DEFAULT fn_somente_digitos(p_busca);
    DECLARE v_numeros VARCHAR(257) DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');

    SELECT t.*
    FROM (
        SELECT p.ID AS id, p.NOME AS nome, p.DESCRICAO AS descricao, p.PRECO_UNITARIO AS preco,
               p.ESTOQUE AS estoque, p.ESTOQUE_MINIMO AS estoque_minimo, p.UNIDADE_MEDIDA AS unidade,
               p.DATA_VALIDADE AS validade, p.STATUS AS status, p.CATEGORIA_ID AS categoria_id,
               c.NOME AS categoria, p.CODIGO_BARRAS AS codigo_barras, p.NCM AS ncm,
               DATEDIFF(p.DATA_VALIDADE, CURDATE()) AS dias_para_vencer,
               LEAST(100, ROUND(p.ESTOQUE / GREATEST(p.ESTOQUE_MINIMO * 2, 1) * 100)) AS nivel,
               fn_produto_situacao(p.STATUS, p.ESTOQUE, p.ESTOQUE_MINIMO, p.DATA_VALIDADE, p_dias) AS situacao
        FROM PRODUTO p
        JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
        WHERE (TRIM(COALESCE(p_busca, '')) = ''
               OR p.NOME LIKE v_texto
               OR (v_digitos <> '' AND p.CODIGO_BARRAS LIKE v_numeros))
          AND (p_categoria = 0 OR p.CATEGORIA_ID = p_categoria)
    ) t
    WHERE p_situacao NOT IN ('CRITICO', 'VENCENDO', 'OK')
       OR (p_situacao = 'CRITICO' AND t.situacao IN ('CRITICO', 'VENCIDO'))
       OR (p_situacao = 'VENCENDO' AND t.situacao = 'VENCENDO')
       OR (p_situacao = 'OK' AND t.situacao = 'OK')
    ORDER BY t.nome
    LIMIT p_limite OFFSET p_deslocamento;
END//

DROP PROCEDURE IF EXISTS sp_estoque_contagem//
CREATE PROCEDURE sp_estoque_contagem(IN p_busca VARCHAR(255), IN p_categoria INT, IN p_dias INT)
BEGIN
    DECLARE v_texto VARCHAR(257) DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    DECLARE v_digitos VARCHAR(255) DEFAULT fn_somente_digitos(p_busca);
    DECLARE v_numeros VARCHAR(257) DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');

    SELECT COUNT(*) AS todos,
           COALESCE(SUM(t.situacao IN ('CRITICO', 'VENCIDO')), 0) AS critico,
           COALESCE(SUM(t.situacao = 'VENCENDO'), 0) AS vencendo,
           COALESCE(SUM(t.situacao = 'OK'), 0) AS ok
    FROM (
        SELECT fn_produto_situacao(p.STATUS, p.ESTOQUE, p.ESTOQUE_MINIMO, p.DATA_VALIDADE, p_dias) AS situacao
        FROM PRODUTO p
        WHERE (TRIM(COALESCE(p_busca, '')) = ''
               OR p.NOME LIKE v_texto
               OR (v_digitos <> '' AND p.CODIGO_BARRAS LIKE v_numeros))
          AND (p_categoria = 0 OR p.CATEGORIA_ID = p_categoria)
    ) t;
END//

-- Cartões do topo da tela de estoque (só itens ativos).
DROP PROCEDURE IF EXISTS sp_estoque_resumo//
CREATE PROCEDURE sp_estoque_resumo(IN p_dias INT)
BEGIN
    SELECT COUNT(*) AS itens,
           COUNT(DISTINCT p.CATEGORIA_ID) AS categorias,
           COALESCE(SUM(p.ESTOQUE <= p.ESTOQUE_MINIMO), 0) AS abaixo_minimo,
           COALESCE(SUM(p.DATA_VALIDADE IS NOT NULL AND p.DATA_VALIDADE <= DATE_ADD(CURDATE(), INTERVAL p_dias DAY)), 0) AS vencendo,
           COALESCE(SUM(p.ESTOQUE * p.PRECO_UNITARIO), 0) AS valor_estoque
    FROM PRODUTO p
    WHERE p.STATUS = 'ATIVO';
END//

DROP PROCEDURE IF EXISTS sp_estoque_movimentos_hoje//
CREATE PROCEDURE sp_estoque_movimentos_hoje()
BEGIN
    SELECT m.ID AS id, m.TIPO AS tipo, m.QUANTIDADE AS quantidade, p.UNIDADE_MEDIDA AS unidade,
           p.NOME AS produto, u.NOME AS usuario, DATE_FORMAT(m.DATA_MOVIMENTACAO, '%H:%i') AS hora,
           m.OBSERVACAO AS observacao
    FROM MOVIMENTACAO_ESTOQUE m
    JOIN PRODUTO p ON p.ID = m.PRODUTO_ID
    JOIN USUARIOS u ON u.ID = m.USUARIO_ID
    WHERE DATE(m.DATA_MOVIMENTACAO) = CURDATE()
    ORDER BY m.ID DESC
    LIMIT 30;
END//

-- Itens ativos para a lista de "Registrar entrada/saída".
DROP PROCEDURE IF EXISTS sp_estoque_produtos_ativos//
CREATE PROCEDURE sp_estoque_produtos_ativos()
BEGIN
    SELECT ID AS id, NOME AS nome, UNIDADE_MEDIDA AS unidade, ESTOQUE AS estoque
    FROM PRODUTO
    WHERE STATUS = 'ATIVO'
    ORDER BY NOME;
END//

-- Entrada ou saída de estoque. Devolve o novo saldo.
DROP PROCEDURE IF EXISTS sp_estoque_movimentar//
CREATE PROCEDURE sp_estoque_movimentar(
    IN p_produto_id INT,
    IN p_tipo VARCHAR(10),
    IN p_quantidade INT,
    IN p_observacao VARCHAR(255),
    IN p_usuario_id INT
)
BEGIN
    DECLARE v_atual INT DEFAULT NULL;
    DECLARE v_status VARCHAR(10) DEFAULT NULL;
    DECLARE v_novo INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_tipo NOT IN ('ENTRADA', 'SAIDA') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de movimentação inválido.';
    END IF;
    IF p_quantidade IS NULL OR p_quantidade < 1 OR p_quantidade > 9999999 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe uma quantidade válida.';
    END IF;

    START TRANSACTION;

    SELECT ESTOQUE, STATUS INTO v_atual, v_status FROM PRODUTO WHERE ID = p_produto_id FOR UPDATE;

    IF v_atual IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item não encontrado.';
    END IF;
    IF v_status = 'INATIVO' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item inativo não pode receber movimentações.';
    END IF;
    IF p_tipo = 'SAIDA' AND p_quantidade > v_atual THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Estoque insuficiente para essa saída.';
    END IF;

    SET v_novo = IF(p_tipo = 'ENTRADA', v_atual + p_quantidade, v_atual - p_quantidade);

    UPDATE PRODUTO SET ESTOQUE = v_novo WHERE ID = p_produto_id;
    INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, OBSERVACAO)
    VALUES (p_produto_id, p_usuario_id, p_tipo, p_quantidade, NULLIF(TRIM(COALESCE(p_observacao, '')), ''));

    COMMIT;

    SELECT v_novo AS estoque;
END//

-- ==========================================================================
-- 7. PEDIDOS (VENDA + VENDA_ITEM)
--    Etapas: NOVO -> PREPARO -> PRONTO -> ENTREGUE (ou CANCELADO)
--    Criar o pedido gera uma CONTA_RECEBER pendente (aparece no Fluxo de Caixa);
--    entregar finaliza a venda e paga a conta; cancelar cancela os dois.
-- ==========================================================================

-- Pedidos em andamento + entregues hoje.
DROP PROCEDURE IF EXISTS sp_pedido_quadro//
CREATE PROCEDURE sp_pedido_quadro()
BEGIN
    SELECT v.ID AS id, v.TIPO_PEDIDO AS tipo, v.MESA AS mesa, v.CLIENTE_ID AS cliente_id, cl.NOME AS cliente,
           v.ETAPA AS etapa, v.FORMA_PAGAMENTO AS forma_pagamento, v.DESCONTO AS desconto, v.VALOR AS valor,
           v.TOTAL AS total, v.OBSERVACAO AS observacao, u.NOME AS atendente,
           TIMESTAMPDIFF(MINUTE, v.DATA_CADASTRO, NOW()) AS minutos,
           TIMESTAMPDIFF(MINUTE, v.DATA_ETAPA, NOW()) AS minutos_etapa,
           DATE_FORMAT(v.DATA_CADASTRO, '%H:%i') AS hora,
           DATE_FORMAT(v.DATA_ETAPA, '%H:%i') AS hora_etapa
    FROM VENDA v
    LEFT JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    WHERE v.ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
       OR (v.ETAPA = 'ENTREGUE' AND DATE(v.DATA_ETAPA) = CURDATE())
    ORDER BY v.DATA_CADASTRO, v.ID
    LIMIT 300;
END//

-- Itens dos mesmos pedidos do quadro.
DROP PROCEDURE IF EXISTS sp_pedido_quadro_itens//
CREATE PROCEDURE sp_pedido_quadro_itens()
BEGIN
    SELECT vi.VENDA_ID AS venda_id, vi.PRODUTO_ID AS produto_id, p.NOME AS nome,
           vi.QUANTIDADE AS quantidade, vi.PRECO_UNITARIO AS preco, vi.OBSERVACAO AS observacao
    FROM VENDA_ITEM vi
    JOIN VENDA v ON v.ID = vi.VENDA_ID
    JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID
    WHERE v.ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
       OR (v.ETAPA = 'ENTREGUE' AND DATE(v.DATA_ETAPA) = CURDATE())
    ORDER BY vi.VENDA_ID, p.NOME;
END//

DROP PROCEDURE IF EXISTS sp_pedido_obter//
CREATE PROCEDURE sp_pedido_obter(IN p_id INT)
BEGIN
    SELECT v.ID AS id, v.TIPO_PEDIDO AS tipo, v.MESA AS mesa, v.CLIENTE_ID AS cliente_id, cl.NOME AS cliente,
           v.ETAPA AS etapa, v.FORMA_PAGAMENTO AS forma_pagamento, v.DESCONTO AS desconto, v.VALOR AS valor,
           v.TOTAL AS total, v.OBSERVACAO AS observacao, u.NOME AS atendente
    FROM VENDA v
    LEFT JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    WHERE v.ID = p_id;
END//

DROP PROCEDURE IF EXISTS sp_pedido_itens//
CREATE PROCEDURE sp_pedido_itens(IN p_venda_id INT)
BEGIN
    SELECT vi.PRODUTO_ID AS produto_id, p.NOME AS nome, vi.QUANTIDADE AS quantidade,
           vi.PRECO_UNITARIO AS preco, vi.OBSERVACAO AS observacao
    FROM VENDA_ITEM vi
    JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID
    WHERE vi.VENDA_ID = p_venda_id
    ORDER BY p.NOME;
END//

-- Itens do cardápio que podem ser vendidos agora.
DROP PROCEDURE IF EXISTS sp_pedido_opcoes_produtos//
CREATE PROCEDURE sp_pedido_opcoes_produtos()
BEGIN
    SELECT p.ID AS id, p.NOME AS nome, p.PRECO_UNITARIO AS preco, c.NOME AS categoria
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE p.STATUS = 'ATIVO' AND c.TIPO = 'CARDAPIO'
    ORDER BY c.NOME, p.NOME;
END//

DROP PROCEDURE IF EXISTS sp_pedido_opcoes_clientes//
CREATE PROCEDURE sp_pedido_opcoes_clientes()
BEGIN
    SELECT ID AS id, NOME AS nome, TELEFONE AS telefone
    FROM CLIENTE
    WHERE STATUS = 'ATIVO'
    ORDER BY NOME
    LIMIT 1000;
END//

-- Cria o cabeçalho do pedido (sem itens). Rode dentro de uma transação do PHP
-- junto com sp_pedido_item_adicionar e sp_pedido_totais. Devolve o id.
DROP PROCEDURE IF EXISTS sp_pedido_criar//
CREATE PROCEDURE sp_pedido_criar(
    IN p_tipo VARCHAR(10),
    IN p_mesa VARCHAR(20),
    IN p_cliente_id INT,
    IN p_forma VARCHAR(10),
    IN p_observacao VARCHAR(255),
    IN p_usuario_id INT
)
BEGIN
    DECLARE v_cliente INT DEFAULT NULLIF(p_cliente_id, 0);

    IF p_tipo NOT IN ('SALAO', 'BALCAO', 'DELIVERY') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha o tipo do pedido.';
    END IF;
    IF p_tipo = 'DELIVERY' AND fn_config(0, 'aceita_delivery', '1') <> '1' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O delivery está pausado nas Configurações.';
    END IF;
    IF p_tipo = 'SALAO' AND TRIM(COALESCE(p_mesa, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a mesa do pedido.';
    END IF;
    IF p_tipo = 'DELIVERY' AND v_cliente IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha o cliente do delivery.';
    END IF;
    IF v_cliente IS NOT NULL AND NOT EXISTS (SELECT 1 FROM CLIENTE WHERE ID = v_cliente) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cliente não encontrado.';
    END IF;
    IF FIND_IN_SET(p_forma, fn_config(0, 'pagamentos', 'PIX,CREDITO,DEBITO,DINHEIRO')) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forma de pagamento não aceita pela loja.';
    END IF;

    INSERT INTO VENDA (VALOR, DESCONTO, TOTAL, CLIENTE_ID, FUNCIONARIO_ID, FORMA_PAGAMENTO, STATUS,
                       TIPO_PEDIDO, MESA, ETAPA, OBSERVACAO)
    VALUES (0, 0, 0, v_cliente, p_usuario_id, p_forma, 'EM_ABERTO',
            p_tipo, IF(p_tipo = 'SALAO', TRIM(p_mesa), NULL), 'NOVO', NULLIF(TRIM(COALESCE(p_observacao, '')), ''));

    SELECT LAST_INSERT_ID() AS id;
END//

-- Atualiza o cabeçalho de um pedido ainda aberto (os itens são regravados em seguida).
DROP PROCEDURE IF EXISTS sp_pedido_atualizar//
CREATE PROCEDURE sp_pedido_atualizar(
    IN p_id INT,
    IN p_tipo VARCHAR(10),
    IN p_mesa VARCHAR(20),
    IN p_cliente_id INT,
    IN p_forma VARCHAR(10),
    IN p_observacao VARCHAR(255)
)
BEGIN
    DECLARE v_cliente INT DEFAULT NULLIF(p_cliente_id, 0);
    DECLARE v_etapa VARCHAR(10) DEFAULT NULL;
    DECLARE v_tipo_atual VARCHAR(10) DEFAULT NULL;
    DECLARE v_forma_atual VARCHAR(10) DEFAULT NULL;

    SELECT ETAPA, TIPO_PEDIDO, FORMA_PAGAMENTO INTO v_etapa, v_tipo_atual, v_forma_atual
    FROM VENDA WHERE ID = p_id;

    IF v_etapa IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pedido não encontrado.';
    END IF;
    IF v_etapa NOT IN ('NOVO', 'PREPARO', 'PRONTO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pedidos entregues ou cancelados não podem ser editados.';
    END IF;
    IF p_tipo NOT IN ('SALAO', 'BALCAO', 'DELIVERY') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha o tipo do pedido.';
    END IF;
    IF p_tipo = 'DELIVERY' AND v_tipo_atual <> 'DELIVERY' AND fn_config(0, 'aceita_delivery', '1') <> '1' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O delivery está pausado nas Configurações.';
    END IF;
    IF p_tipo = 'SALAO' AND TRIM(COALESCE(p_mesa, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a mesa do pedido.';
    END IF;
    IF p_tipo = 'DELIVERY' AND v_cliente IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha o cliente do delivery.';
    END IF;
    IF v_cliente IS NOT NULL AND NOT EXISTS (SELECT 1 FROM CLIENTE WHERE ID = v_cliente) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cliente não encontrado.';
    END IF;
    IF p_forma <> v_forma_atual
       AND FIND_IN_SET(p_forma, fn_config(0, 'pagamentos', 'PIX,CREDITO,DEBITO,DINHEIRO')) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forma de pagamento não aceita pela loja.';
    END IF;

    UPDATE VENDA
    SET TIPO_PEDIDO = p_tipo, MESA = IF(p_tipo = 'SALAO', TRIM(p_mesa), NULL), CLIENTE_ID = v_cliente,
        FORMA_PAGAMENTO = p_forma, OBSERVACAO = NULLIF(TRIM(COALESCE(p_observacao, '')), '')
    WHERE ID = p_id;
END//

DROP PROCEDURE IF EXISTS sp_pedido_item_limpar//
CREATE PROCEDURE sp_pedido_item_limpar(IN p_venda_id INT)
BEGIN
    DELETE FROM VENDA_ITEM WHERE VENDA_ID = p_venda_id;
END//

-- Adiciona um item (com o preço atual do cardápio). Se o produto já está no pedido, soma a quantidade.
DROP PROCEDURE IF EXISTS sp_pedido_item_adicionar//
CREATE PROCEDURE sp_pedido_item_adicionar(
    IN p_venda_id INT,
    IN p_produto_id INT,
    IN p_quantidade INT,
    IN p_observacao VARCHAR(255)
)
BEGIN
    DECLARE v_preco DECIMAL(10, 2) DEFAULT NULL;

    IF p_quantidade IS NULL OR p_quantidade < 1 OR p_quantidade > 999 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe uma quantidade válida (1 a 999) para cada item.';
    END IF;

    SELECT p.PRECO_UNITARIO INTO v_preco
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE p.ID = p_produto_id AND p.STATUS = 'ATIVO' AND c.TIPO = 'CARDAPIO';

    IF v_preco IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Um dos itens não está mais disponível no cardápio.';
    END IF;

    INSERT INTO VENDA_ITEM (VENDA_ID, PRODUTO_ID, QUANTIDADE, PRECO_UNITARIO, VALOR, DESCONTO, TOTAL, STATUS, OBSERVACAO)
    VALUES (p_venda_id, p_produto_id, p_quantidade, v_preco, p_quantidade * v_preco, 0, p_quantidade * v_preco,
            'EM_ABERTO', NULLIF(TRIM(COALESCE(p_observacao, '')), ''))
    ON DUPLICATE KEY UPDATE
        QUANTIDADE = LEAST(999, QUANTIDADE + p_quantidade),
        OBSERVACAO = NULLIF(TRIM(CONCAT_WS(' ', OBSERVACAO, NULLIF(TRIM(COALESCE(p_observacao, '')), ''))), '');

    UPDATE VENDA_ITEM
    SET VALOR = QUANTIDADE * PRECO_UNITARIO, TOTAL = QUANTIDADE * PRECO_UNITARIO
    WHERE VENDA_ID = p_venda_id AND PRODUTO_ID = p_produto_id;
END//

-- Recalcula valor/total do pedido a partir dos itens e cria/atualiza a conta a receber.
DROP PROCEDURE IF EXISTS sp_pedido_totais//
CREATE PROCEDURE sp_pedido_totais(IN p_venda_id INT, IN p_desconto DECIMAL(10, 2))
BEGIN
    DECLARE v_valor DECIMAL(10, 2) DEFAULT 0;
    DECLARE v_itens INT DEFAULT 0;
    DECLARE v_desconto DECIMAL(10, 2) DEFAULT COALESCE(p_desconto, 0);

    SELECT COALESCE(SUM(TOTAL), 0), COUNT(*) INTO v_valor, v_itens
    FROM VENDA_ITEM WHERE VENDA_ID = p_venda_id;

    IF v_itens = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adicione ao menos um item ao pedido.';
    END IF;
    IF v_desconto < 0 OR v_desconto > v_valor THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O desconto não pode ser maior que o valor dos itens.';
    END IF;

    UPDATE VENDA SET VALOR = v_valor, DESCONTO = v_desconto, TOTAL = v_valor - v_desconto WHERE ID = p_venda_id;

    IF EXISTS (SELECT 1 FROM CONTA_RECEBER WHERE VENDA_ID = p_venda_id) THEN
        UPDATE CONTA_RECEBER
        SET VALOR = v_valor, DESCONTO = v_desconto, TOTAL = v_valor - v_desconto
        WHERE VENDA_ID = p_venda_id AND STATUS IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO');
    ELSE
        INSERT INTO CONTA_RECEBER (VENDA_ID, DATA_VENCIMENTO, VALOR, DESCONTO, TOTAL, STATUS)
        VALUES (p_venda_id, CURDATE(), v_valor, v_desconto, v_valor - v_desconto, 'PENDENTE');
    END IF;
END//

-- Move o pedido uma etapa para frente ou para trás.
DROP PROCEDURE IF EXISTS sp_pedido_etapa//
CREATE PROCEDURE sp_pedido_etapa(IN p_id INT, IN p_destino VARCHAR(10))
BEGIN
    DECLARE v_atual VARCHAR(10) DEFAULT NULL;
    DECLARE v_pos_atual INT DEFAULT 0;
    DECLARE v_pos_destino INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT ETAPA INTO v_atual FROM VENDA WHERE ID = p_id;

    IF v_atual IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pedido não encontrado.';
    END IF;

    SET v_pos_atual = FIND_IN_SET(v_atual, 'NOVO,PREPARO,PRONTO,ENTREGUE');
    SET v_pos_destino = FIND_IN_SET(p_destino, 'NOVO,PREPARO,PRONTO,ENTREGUE');

    IF v_pos_atual = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este pedido não pode mais mudar de etapa.';
    END IF;
    IF v_atual = 'ENTREGUE' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pedidos entregues não voltam para a cozinha.';
    END IF;
    IF v_pos_destino = 0 OR ABS(v_pos_destino - v_pos_atual) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Movimento de etapa inválido.';
    END IF;

    START TRANSACTION;

    UPDATE VENDA SET ETAPA = p_destino, DATA_ETAPA = NOW() WHERE ID = p_id;

    IF p_destino = 'ENTREGUE' THEN
        UPDATE VENDA SET STATUS = 'FINALIZADA' WHERE ID = p_id;
        UPDATE VENDA_ITEM SET STATUS = 'FINALIZADA' WHERE VENDA_ID = p_id;
        UPDATE CONTA_RECEBER
        SET STATUS = 'PAGO', DATA_PAGAMENTO = CURDATE()
        WHERE VENDA_ID = p_id AND STATUS IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO');
        -- baixa automática dos insumos da ficha técnica (procedures_modulos.sql)
        CALL sp_estoque_baixa_pedido(p_id, -1);
    END IF;

    COMMIT;
END//

-- Cancela o pedido (e a conta a receber). Pedido já entregue só o gerente estorna.
DROP PROCEDURE IF EXISTS sp_pedido_cancelar//
CREATE PROCEDURE sp_pedido_cancelar(IN p_id INT, IN p_eh_gerente TINYINT)
BEGIN
    DECLARE v_etapa VARCHAR(10) DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT ETAPA INTO v_etapa FROM VENDA WHERE ID = p_id;

    IF v_etapa IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pedido não encontrado.';
    END IF;
    IF v_etapa = 'CANCELADO' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este pedido já está cancelado.';
    END IF;
    IF v_etapa = 'ENTREGUE' AND p_eh_gerente <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Somente administradores e gerentes podem estornar um pedido entregue.';
    END IF;

    START TRANSACTION;

    UPDATE VENDA SET ETAPA = 'CANCELADO', STATUS = 'CANCELADA', DATA_ETAPA = NOW() WHERE ID = p_id;
    UPDATE VENDA_ITEM SET STATUS = 'CANCELADA' WHERE VENDA_ID = p_id;
    UPDATE CONTA_RECEBER SET STATUS = 'CANCELADO', DATA_PAGAMENTO = NULL WHERE VENDA_ID = p_id;
    -- devolve ao estoque os insumos que já tinham recebido baixa
    CALL sp_estoque_baixa_pedido(p_id, 1);

    COMMIT;
END//

-- Exclui o pedido por completo (não vale para entregues: cancele-os).
DROP PROCEDURE IF EXISTS sp_pedido_excluir//
CREATE PROCEDURE sp_pedido_excluir(IN p_id INT)
BEGIN
    DECLARE v_etapa VARCHAR(10) DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT ETAPA INTO v_etapa FROM VENDA WHERE ID = p_id;

    IF v_etapa IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pedido não encontrado.';
    END IF;
    IF v_etapa = 'ENTREGUE' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pedidos entregues fazem parte do faturamento. Cancele em vez de excluir.';
    END IF;

    START TRANSACTION;

    DELETE FROM CONTA_RECEBER WHERE VENDA_ID = p_id;
    DELETE FROM VENDA_ITEM WHERE VENDA_ID = p_id;
    DELETE FROM VENDA WHERE ID = p_id;

    COMMIT;
END//

-- ==========================================================================
-- 8. FLUXO DE CAIXA (usa a view vw_fluxo_caixa)
-- ==========================================================================

-- p_tipo: TODOS | ENTRADA | SAIDA   p_situacao: TODAS | PENDENTE | PAGO | ATRASADO | CANCELADO
DROP PROCEDURE IF EXISTS sp_fluxo_listar//
CREATE PROCEDURE sp_fluxo_listar(
    IN p_de DATE,
    IN p_ate DATE,
    IN p_tipo VARCHAR(10),
    IN p_situacao VARCHAR(10),
    IN p_limite INT,
    IN p_deslocamento INT
)
BEGIN
    SELECT f.tipo, f.origem, f.id, f.referencia, f.vencimento, f.pagamento, f.total, f.status,
           f.contraparte, f.categoria, f.situacao
    FROM vw_fluxo_caixa f
    WHERE f.vencimento BETWEEN p_de AND p_ate
      AND (p_tipo NOT IN ('ENTRADA', 'SAIDA') OR f.tipo = p_tipo)
      AND (p_situacao = 'TODAS' OR f.situacao = p_situacao)
    ORDER BY f.vencimento DESC, f.id DESC
    LIMIT p_limite OFFSET p_deslocamento;
END//

-- Quantos lançamentos há no período/situação (todos, entradas e saídas) para os filtros.
DROP PROCEDURE IF EXISTS sp_fluxo_contagem//
CREATE PROCEDURE sp_fluxo_contagem(IN p_de DATE, IN p_ate DATE, IN p_situacao VARCHAR(10))
BEGIN
    SELECT COUNT(*) AS todos,
           COALESCE(SUM(f.tipo = 'ENTRADA'), 0) AS entradas,
           COALESCE(SUM(f.tipo = 'SAIDA'), 0) AS saidas
    FROM vw_fluxo_caixa f
    WHERE f.vencimento BETWEEN p_de AND p_ate
      AND (p_situacao = 'TODAS' OR f.situacao = p_situacao);
END//

-- Cartões do topo: saldo, pagos, a receber/pagar e atrasados.
DROP PROCEDURE IF EXISTS sp_fluxo_resumo//
CREATE PROCEDURE sp_fluxo_resumo(IN p_de DATE, IN p_ate DATE)
BEGIN
    SELECT
        COALESCE(SUM(CASE WHEN f.tipo = 'ENTRADA' AND f.status = 'PAGO' THEN f.total END), 0) AS entradas_pagas,
        COALESCE(SUM(CASE WHEN f.tipo = 'SAIDA' AND f.status = 'PAGO' THEN f.total END), 0) AS saidas_pagas,
        COALESCE(SUM(CASE WHEN f.tipo = 'ENTRADA' AND f.status IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO', 'AGENDADO') THEN f.total END), 0) AS a_receber,
        COALESCE(SUM(CASE WHEN f.tipo = 'SAIDA' AND f.status IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO', 'AGENDADO') THEN f.total END), 0) AS a_pagar,
        COALESCE(SUM(CASE WHEN f.situacao = 'ATRASADO' THEN f.total END), 0) AS atrasado_valor,
        COALESCE(SUM(f.situacao = 'ATRASADO'), 0) AS atrasado_qtd
    FROM vw_fluxo_caixa f
    WHERE f.vencimento BETWEEN p_de AND p_ate;
END//

-- Entradas e saídas das últimas 4 semanas (7 dias cada), da mais antiga para a atual.
DROP PROCEDURE IF EXISTS sp_fluxo_semanas//
CREATE PROCEDURE sp_fluxo_semanas()
BEGIN
    SELECT 3 AS semana, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 27 DAY), '%d/%m') AS rotulo,
           COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' THEN total END), 0) AS entrada,
           COALESCE(SUM(CASE WHEN tipo = 'SAIDA' THEN total END), 0) AS saida
    FROM vw_fluxo_caixa
    WHERE status <> 'CANCELADO' AND vencimento BETWEEN DATE_SUB(CURDATE(), INTERVAL 27 DAY) AND DATE_SUB(CURDATE(), INTERVAL 21 DAY)
    UNION ALL
    SELECT 2, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 20 DAY), '%d/%m'),
           COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' THEN total END), 0),
           COALESCE(SUM(CASE WHEN tipo = 'SAIDA' THEN total END), 0)
    FROM vw_fluxo_caixa
    WHERE status <> 'CANCELADO' AND vencimento BETWEEN DATE_SUB(CURDATE(), INTERVAL 20 DAY) AND DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    UNION ALL
    SELECT 1, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 13 DAY), '%d/%m'),
           COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' THEN total END), 0),
           COALESCE(SUM(CASE WHEN tipo = 'SAIDA' THEN total END), 0)
    FROM vw_fluxo_caixa
    WHERE status <> 'CANCELADO' AND vencimento BETWEEN DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    UNION ALL
    SELECT 0, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 6 DAY), '%d/%m'),
           COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' THEN total END), 0),
           COALESCE(SUM(CASE WHEN tipo = 'SAIDA' THEN total END), 0)
    FROM vw_fluxo_caixa
    WHERE status <> 'CANCELADO' AND vencimento BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE();
END//

DROP PROCEDURE IF EXISTS sp_fluxo_obter//
CREATE PROCEDURE sp_fluxo_obter(IN p_id INT)
BEGIN
    SELECT ID AS id, TIPO AS tipo, DESCRICAO AS descricao, CATEGORIA AS categoria, VALOR AS valor,
           DATA_MOVIMENTACAO AS data_movimentacao, STATUS AS status
    FROM MOVIMENTACAO_CAIXA
    WHERE ID = p_id;
END//

-- Lançamento manual: p_id = 0 cria; p_id > 0 edita (se não estiver cancelado).
DROP PROCEDURE IF EXISTS sp_fluxo_salvar//
CREATE PROCEDURE sp_fluxo_salvar(
    IN p_id INT,
    IN p_tipo VARCHAR(10),
    IN p_descricao VARCHAR(255),
    IN p_categoria VARCHAR(100),
    IN p_valor DECIMAL(10, 2),
    IN p_data DATE,
    IN p_status VARCHAR(10),
    IN p_usuario_id INT
)
BEGIN
    DECLARE v_id INT DEFAULT COALESCE(p_id, 0);

    IF p_tipo NOT IN ('ENTRADA', 'SAIDA') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha se o lançamento é entrada ou saída.';
    END IF;
    IF TRIM(COALESCE(p_descricao, '')) = '' OR TRIM(COALESCE(p_categoria, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a descrição e a categoria.';
    END IF;
    IF p_valor IS NULL OR p_valor <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe um valor maior que zero.';
    END IF;
    IF p_data IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a data do lançamento.';
    END IF;
    IF p_status NOT IN ('PENDENTE', 'PAGO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Situação inválida.';
    END IF;

    IF v_id = 0 THEN
        INSERT INTO MOVIMENTACAO_CAIXA (TIPO, DESCRICAO, CATEGORIA, VALOR, DATA_MOVIMENTACAO, DATA_PAGAMENTO, STATUS, USUARIO_ID)
        VALUES (p_tipo, TRIM(p_descricao), TRIM(p_categoria), p_valor, p_data,
                IF(p_status = 'PAGO', p_data, NULL), p_status, p_usuario_id);
        SET v_id = LAST_INSERT_ID();
    ELSE
        IF NOT EXISTS (SELECT 1 FROM MOVIMENTACAO_CAIXA WHERE ID = v_id AND STATUS <> 'CANCELADO') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lançamento cancelado ou inexistente não pode ser editado.';
        END IF;

        UPDATE MOVIMENTACAO_CAIXA
        SET TIPO = p_tipo, DESCRICAO = TRIM(p_descricao), CATEGORIA = TRIM(p_categoria), VALOR = p_valor,
            DATA_MOVIMENTACAO = p_data, STATUS = p_status, DATA_PAGAMENTO = IF(p_status = 'PAGO', p_data, NULL)
        WHERE ID = v_id;
    END IF;

    SELECT v_id AS id;
END//

-- Marca como PAGO, volta para PENDENTE ou CANCELA um lançamento (venda, compra ou manual).
DROP PROCEDURE IF EXISTS sp_fluxo_status//
CREATE PROCEDURE sp_fluxo_status(IN p_id INT, IN p_tipo VARCHAR(10), IN p_origem VARCHAR(10), IN p_status VARCHAR(10))
BEGIN
    DECLARE v_atual VARCHAR(12) DEFAULT NULL;

    IF p_status NOT IN ('PAGO', 'PENDENTE', 'CANCELADO') OR p_tipo NOT IN ('ENTRADA', 'SAIDA') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Situação ou tipo inválido.';
    END IF;

    IF p_origem = 'MANUAL' THEN
        SELECT STATUS INTO v_atual FROM MOVIMENTACAO_CAIXA WHERE ID = p_id AND TIPO = p_tipo;
        IF v_atual IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lançamento não encontrado.';
        END IF;
        IF v_atual = 'CANCELADO' AND p_status <> 'CANCELADO' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Um lançamento cancelado não pode ser reaberto.';
        END IF;

        UPDATE MOVIMENTACAO_CAIXA
        SET STATUS = p_status, DATA_PAGAMENTO = IF(p_status = 'PAGO', CURDATE(), NULL)
        WHERE ID = p_id;
    ELSEIF p_origem = 'VENDA' AND p_tipo = 'ENTRADA' THEN
        SELECT STATUS INTO v_atual FROM CONTA_RECEBER WHERE ID = p_id;
        IF v_atual IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lançamento não encontrado.';
        END IF;
        IF v_atual = 'CANCELADO' AND p_status <> 'CANCELADO' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Um lançamento cancelado não pode ser reaberto.';
        END IF;

        UPDATE CONTA_RECEBER
        SET STATUS = p_status, DATA_PAGAMENTO = IF(p_status = 'PAGO', CURDATE(), NULL)
        WHERE ID = p_id;
    ELSEIF p_origem = 'COMPRA' AND p_tipo = 'SAIDA' THEN
        SELECT STATUS INTO v_atual FROM CONTA_PAGAR WHERE ID = p_id;
        IF v_atual IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lançamento não encontrado.';
        END IF;
        IF v_atual = 'CANCELADO' AND p_status <> 'CANCELADO' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Um lançamento cancelado não pode ser reaberto.';
        END IF;

        UPDATE CONTA_PAGAR
        SET STATUS = p_status, DATA_PAGAMENTO = IF(p_status = 'PAGO', CURDATE(), NULL)
        WHERE ID = p_id;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Origem do lançamento inválida.';
    END IF;
END//

DROP PROCEDURE IF EXISTS sp_fluxo_excluir//
CREATE PROCEDURE sp_fluxo_excluir(IN p_id INT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM MOVIMENTACAO_CAIXA WHERE ID = p_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lançamento não encontrado.';
    END IF;

    DELETE FROM MOVIMENTACAO_CAIXA WHERE ID = p_id;
END//

-- ==========================================================================
-- 9. EMPRESA, HORÁRIOS E EQUIPE
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_empresa_obter//
CREATE PROCEDURE sp_empresa_obter()
BEGIN
    SELECT NOME_FANTASIA AS nome_fantasia, RAZAO_SOCIAL AS razao_social, CNPJ AS cnpj, TELEFONE AS telefone,
           EMAIL AS email_contato, ENDERECO AS endereco, ENDERECO_NUMERO AS endereco_numero,
           ESTADO AS estado, CIDADE AS cidade
    FROM EMPRESA
    WHERE ID = 1;
END//

DROP PROCEDURE IF EXISTS sp_empresa_horarios//
CREATE PROCEDURE sp_empresa_horarios()
BEGIN
    SELECT DIA_SEMANA AS dia, ABERTO AS aberto,
           TIME_FORMAT(ABRE, '%H:%i') AS abre, TIME_FORMAT(FECHA, '%H:%i') AS fecha
    FROM EMPRESA_HORARIO
    ORDER BY DIA_SEMANA;
END//

DROP PROCEDURE IF EXISTS sp_empresa_salvar//
CREATE PROCEDURE sp_empresa_salvar(
    IN p_nome_fantasia VARCHAR(150),
    IN p_razao_social VARCHAR(200),
    IN p_cnpj VARCHAR(30),
    IN p_telefone VARCHAR(30),
    IN p_email VARCHAR(255),
    IN p_endereco VARCHAR(255),
    IN p_numero VARCHAR(20),
    IN p_estado CHAR(2),
    IN p_cidade VARCHAR(200)
)
BEGIN
    DECLARE v_cnpj VARCHAR(255) DEFAULT fn_somente_digitos(p_cnpj);
    DECLARE v_telefone VARCHAR(255) DEFAULT fn_somente_digitos(p_telefone);

    IF TRIM(COALESCE(p_nome_fantasia, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome fantasia.';
    END IF;
    IF v_cnpj <> '' AND CHAR_LENGTH(v_cnpj) <> 14 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O CNPJ deve ter 14 números.';
    END IF;
    IF v_telefone <> '' AND CHAR_LENGTH(v_telefone) NOT IN (10, 11) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o telefone com DDD (10 ou 11 números).';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM ESTADO WHERE SIGLA = p_estado) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Estado inválido.';
    END IF;

    INSERT INTO EMPRESA (ID, NOME_FANTASIA, RAZAO_SOCIAL, CNPJ, TELEFONE, EMAIL, ENDERECO, ENDERECO_NUMERO, ESTADO, CIDADE)
    VALUES (1, TRIM(p_nome_fantasia), TRIM(COALESCE(p_razao_social, '')), fn_formatar_cnpj(v_cnpj), v_telefone,
            TRIM(COALESCE(p_email, '')), TRIM(COALESCE(p_endereco, '')), TRIM(COALESCE(p_numero, '')),
            p_estado, TRIM(COALESCE(p_cidade, '')))
    ON DUPLICATE KEY UPDATE
        NOME_FANTASIA = VALUES(NOME_FANTASIA), RAZAO_SOCIAL = VALUES(RAZAO_SOCIAL), CNPJ = VALUES(CNPJ),
        TELEFONE = VALUES(TELEFONE), EMAIL = VALUES(EMAIL), ENDERECO = VALUES(ENDERECO),
        ENDERECO_NUMERO = VALUES(ENDERECO_NUMERO), ESTADO = VALUES(ESTADO), CIDADE = VALUES(CIDADE);
END//

DROP PROCEDURE IF EXISTS sp_empresa_horario_salvar//
CREATE PROCEDURE sp_empresa_horario_salvar(IN p_dia INT, IN p_aberto TINYINT, IN p_abre TIME, IN p_fecha TIME)
BEGIN
    IF p_dia < 1 OR p_dia > 7 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dia da semana inválido.';
    END IF;
    IF p_aberto = 1 AND p_fecha <= p_abre THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O horário de fechamento deve ser depois do de abertura.';
    END IF;

    INSERT INTO EMPRESA_HORARIO (DIA_SEMANA, ABERTO, ABRE, FECHA)
    VALUES (p_dia, IF(p_aberto = 1, 1, 0), p_abre, p_fecha)
    ON DUPLICATE KEY UPDATE ABERTO = VALUES(ABERTO), ABRE = VALUES(ABRE), FECHA = VALUES(FECHA);
END//

DROP PROCEDURE IF EXISTS sp_equipe_listar//
CREATE PROCEDURE sp_equipe_listar()
BEGIN
    SELECT ID AS id, NOME AS nome, EMAIL AS email, NIVEL AS nivel, COALESCE(CARGO, 'FUNCIONARIO') AS cargo,
           ATIVO AS ativo, DATE_FORMAT(COALESCE(ULTIMO_ACESSO, ULTIMO_LOGIN), '%d/%m %H:%i') AS ultimo_login,
           COALESCE(COALESCE(ULTIMO_ACESSO, ULTIMO_LOGIN) >= NOW() - INTERVAL 15 MINUTE, 0) AS online
    FROM USUARIOS
    ORDER BY (ATIVO = 'INATIVO'), NOME;
END//

DROP PROCEDURE IF EXISTS sp_equipe_obter//
CREATE PROCEDURE sp_equipe_obter(IN p_id INT)
BEGIN
    SELECT ID AS id, NOME AS nome, EMAIL AS email, NIVEL AS nivel, COALESCE(CARGO, 'FUNCIONARIO') AS cargo, ATIVO AS ativo
    FROM USUARIOS
    WHERE ID = p_id;
END//

-- p_ator_admin = 1 quando quem edita é administrador.
DROP PROCEDURE IF EXISTS sp_equipe_atualizar//
CREATE PROCEDURE sp_equipe_atualizar(
    IN p_id INT,
    IN p_nome VARCHAR(255),
    IN p_cargo VARCHAR(50),
    IN p_nivel VARCHAR(12),
    IN p_ativo VARCHAR(10),
    IN p_ator_id INT,
    IN p_ator_admin TINYINT
)
BEGIN
    DECLARE v_nivel_atual VARCHAR(12) DEFAULT NULL;

    SELECT NIVEL INTO v_nivel_atual FROM USUARIOS WHERE ID = p_id;

    IF v_nivel_atual IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Funcionário não encontrado.';
    END IF;
    IF p_id = p_ator_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Altere os seus próprios dados em Configurações.';
    END IF;
    IF v_nivel_atual = 'ADMIN' AND p_ator_admin <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Somente administradores podem alterar outro administrador.';
    END IF;
    IF TRIM(COALESCE(p_nome, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome do funcionário.';
    END IF;
    IF p_cargo NOT IN ('Atendente / Caixa', 'Cozinheiro', 'Entregador', 'Gerente', 'FUNCIONARIO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cargo inválido.';
    END IF;
    IF p_ativo NOT IN ('ATIVO', 'INATIVO') OR p_nivel NOT IN ('ADMIN', 'FUNCIONARIO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Situação ou nível inválido.';
    END IF;
    IF p_nivel <> v_nivel_atual AND p_ator_admin <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Somente administradores podem mudar o nível de acesso.';
    END IF;

    UPDATE USUARIOS SET NOME = TRIM(p_nome), CARGO = p_cargo, NIVEL = p_nivel, ATIVO = p_ativo WHERE ID = p_id;
END//

-- Remove o funcionário; se ele tem histórico, apenas desativa o acesso. Devolve EXCLUIDO ou INATIVADO.
DROP PROCEDURE IF EXISTS sp_equipe_remover//
CREATE PROCEDURE sp_equipe_remover(IN p_id INT, IN p_ator_id INT, IN p_ator_admin TINYINT)
BEGIN
    DECLARE v_nivel_atual VARCHAR(12) DEFAULT NULL;

    SELECT NIVEL INTO v_nivel_atual FROM USUARIOS WHERE ID = p_id;

    IF v_nivel_atual IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Funcionário não encontrado.';
    END IF;
    IF p_id = p_ator_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Você não pode remover a sua própria conta.';
    END IF;
    IF v_nivel_atual = 'ADMIN' AND p_ator_admin <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Somente administradores podem remover outro administrador.';
    END IF;

    IF EXISTS (SELECT 1 FROM VENDA WHERE FUNCIONARIO_ID = p_id)
       OR EXISTS (SELECT 1 FROM COMPRA WHERE FUNCIONARIO_ID = p_id)
       OR EXISTS (SELECT 1 FROM MOVIMENTACAO_ESTOQUE WHERE USUARIO_ID = p_id)
       OR EXISTS (SELECT 1 FROM MOVIMENTACAO_CAIXA WHERE USUARIO_ID = p_id) THEN
        UPDATE USUARIOS SET ATIVO = 'INATIVO' WHERE ID = p_id;
        SELECT 'INATIVADO' AS resultado;
    ELSE
        DELETE FROM CONFIGURACAO WHERE USUARIO_ID = p_id;
        DELETE FROM USUARIOS WHERE ID = p_id;
        SELECT 'EXCLUIDO' AS resultado;
    END IF;
END//

-- ==========================================================================
-- 10. CONFIGURAÇÕES (conta, notificações e operação)
-- ==========================================================================

DROP PROCEDURE IF EXISTS sp_config_conta//
CREATE PROCEDURE sp_config_conta(IN p_usuario_id INT)
BEGIN
    SELECT NOME AS nome, EMAIL AS email, NIVEL AS nivel, COALESCE(CARGO, 'FUNCIONARIO') AS cargo
    FROM USUARIOS
    WHERE ID = p_usuario_id;
END//

-- Hash da senha gravada (o PHP confere com password_verify).
DROP PROCEDURE IF EXISTS sp_config_senha_atual//
CREATE PROCEDURE sp_config_senha_atual(IN p_usuario_id INT)
BEGIN
    SELECT SENHA AS senha FROM USUARIOS WHERE ID = p_usuario_id;
END//

-- p_senha_hash vazio mantém a senha atual.
DROP PROCEDURE IF EXISTS sp_config_conta_salvar//
CREATE PROCEDURE sp_config_conta_salvar(
    IN p_usuario_id INT,
    IN p_nome VARCHAR(255),
    IN p_email VARCHAR(255),
    IN p_senha_hash VARCHAR(255)
)
BEGIN
    IF TRIM(COALESCE(p_nome, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o seu nome.';
    END IF;
    IF TRIM(COALESCE(p_email, '')) = '' OR p_email NOT LIKE '%_@_%.__%' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe um e-mail válido.';
    END IF;
    IF EXISTS (SELECT 1 FROM USUARIOS WHERE EMAIL = TRIM(p_email) AND ID <> p_usuario_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esse e-mail já é usado por outra conta.';
    END IF;

    UPDATE USUARIOS
    SET NOME = TRIM(p_nome), EMAIL = TRIM(p_email), SENHA = IF(COALESCE(p_senha_hash, '') = '', SENHA, p_senha_hash)
    WHERE ID = p_usuario_id;
END//

-- Grava (ou atualiza) uma configuração. p_usuario_id = 0 vale para toda a operação.
DROP PROCEDURE IF EXISTS sp_config_definir//
CREATE PROCEDURE sp_config_definir(IN p_usuario_id INT, IN p_chave VARCHAR(60), IN p_valor VARCHAR(255))
BEGIN
    INSERT INTO CONFIGURACAO (USUARIO_ID, CHAVE, VALOR) VALUES (p_usuario_id, p_chave, p_valor)
    ON DUPLICATE KEY UPDATE VALOR = VALUES(VALOR);
END//

-- Configurações do usuário + globais, já com os padrões (uma linha por chave).
DROP PROCEDURE IF EXISTS sp_config_listar//
CREATE PROCEDURE sp_config_listar(IN p_usuario_id INT)
BEGIN
    SELECT fn_config(p_usuario_id, 'notif_pedido', '1') AS notif_pedido,
           fn_config(p_usuario_id, 'notif_minimo', '1') AS notif_minimo,
           fn_config(p_usuario_id, 'notif_validade', '1') AS notif_validade,
           fn_config(p_usuario_id, 'notif_contas', '0') AS notif_contas,
           fn_config(p_usuario_id, 'dias_validade', '3') AS dias_validade,
           fn_config(0, 'aceita_delivery', '1') AS aceita_delivery,
           fn_config(0, 'tempo_preparo', '20') AS tempo_preparo,
           fn_config(0, 'pagamentos', 'PIX,CREDITO,DEBITO,DINHEIRO') AS pagamentos;
END//

-- Salva a operação da loja (somente gerência): delivery, tempo de preparo e formas de pagamento.
DROP PROCEDURE IF EXISTS sp_config_operacao_salvar//
CREATE PROCEDURE sp_config_operacao_salvar(
    IN p_aceita_delivery TINYINT,
    IN p_tempo_preparo INT,
    IN p_pagamentos VARCHAR(100)
)
BEGIN
    IF p_tempo_preparo < 5 OR p_tempo_preparo > 240 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O tempo de preparo deve ficar entre 5 e 240 minutos.';
    END IF;
    IF TRIM(COALESCE(p_pagamentos, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha ao menos uma forma de pagamento.';
    END IF;

    CALL sp_config_definir(0, 'aceita_delivery', IF(p_aceita_delivery = 1, '1', '0'));
    CALL sp_config_definir(0, 'tempo_preparo', CAST(p_tempo_preparo AS CHAR));
    CALL sp_config_definir(0, 'pagamentos', p_pagamentos);
END//

DELIMITER ;
