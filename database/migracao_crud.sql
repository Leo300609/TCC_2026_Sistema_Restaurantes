-- ==========================================================================
-- MIGRAÇÃO DOS CRUDs DO DASHBOARD (Clientes, Cardápio, Estoque, Pedidos,
-- Empresa, Configurações e Fluxo de Caixa)
--
-- Rode depois do banco base (bancofeitonamoraltmjamovcs.sql) e do
-- migracao_dashboard.sql. Pode ser executado mais de uma vez: cada coluna e
-- cada tabela só é criada se ainda não existir.
-- ==========================================================================

USE tcc;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Procedimento auxiliar: adiciona a coluna somente se ela ainda não existe.
DROP PROCEDURE IF EXISTS xtec_add_coluna;
DELIMITER //
CREATE PROCEDURE xtec_add_coluna(
    IN p_tabela VARCHAR(64),
    IN p_coluna VARCHAR(64),
    IN p_definicao VARCHAR(500)
)
BEGIN
    SET @xtec_coluna_nova = 0;
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND LOWER(TABLE_NAME) = LOWER(p_tabela)
          AND LOWER(COLUMN_NAME) = LOWER(p_coluna)
    ) THEN
        SET @xtec_ddl = CONCAT('ALTER TABLE `', p_tabela, '` ADD COLUMN `', p_coluna, '` ', p_definicao);
        PREPARE xtec_stmt FROM @xtec_ddl;
        EXECUTE xtec_stmt;
        DEALLOCATE PREPARE xtec_stmt;
        SET @xtec_coluna_nova = 1;
    END IF;
END//
DELIMITER ;

-- --------------------------------------------------------------------------
-- 1. PEDIDOS (VENDA / VENDA_ITEM)
-- --------------------------------------------------------------------------
CALL xtec_add_coluna('VENDA', 'TIPO_PEDIDO', "ENUM('SALAO','BALCAO','DELIVERY') NOT NULL DEFAULT 'BALCAO'");
CALL xtec_add_coluna('VENDA', 'MESA', 'VARCHAR(20) NULL');
CALL xtec_add_coluna('VENDA', 'ETAPA', "ENUM('NOVO','PREPARO','PRONTO','ENTREGUE','CANCELADO') NOT NULL DEFAULT 'NOVO'");
-- Vendas que já existiam antes desta migração são tratadas como concluídas.
UPDATE VENDA SET ETAPA = 'ENTREGUE'  WHERE @xtec_coluna_nova = 1 AND STATUS = 'FINALIZADA';
UPDATE VENDA SET ETAPA = 'CANCELADO' WHERE @xtec_coluna_nova = 1 AND STATUS = 'CANCELADA';
CALL xtec_add_coluna('VENDA', 'DATA_ETAPA', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
UPDATE VENDA SET DATA_ETAPA = DATA_CADASTRO WHERE @xtec_coluna_nova = 1;
CALL xtec_add_coluna('VENDA', 'OBSERVACAO', 'VARCHAR(255) NULL');
CALL xtec_add_coluna('VENDA_ITEM', 'OBSERVACAO', 'VARCHAR(255) NULL');

-- Pedido de salão/balcão pode não ter cliente identificado.
ALTER TABLE VENDA MODIFY CLIENTE_ID INT NULL;

-- --------------------------------------------------------------------------
-- 2. CARDÁPIO x ESTOQUE: o tipo da categoria define onde o item aparece
--    CARDAPIO = vendido no cardápio | INSUMO = só controle de estoque
-- --------------------------------------------------------------------------
CALL xtec_add_coluna('PRODUTO_CATEGORIA', 'TIPO', "ENUM('CARDAPIO','INSUMO') NOT NULL DEFAULT 'CARDAPIO'");
UPDATE PRODUTO_CATEGORIA
SET TIPO = 'INSUMO'
WHERE @xtec_coluna_nova = 1
  AND NOME IN ('Ingredientes', 'Embalagens', 'Limpeza');

DROP PROCEDURE IF EXISTS xtec_add_coluna;

-- --------------------------------------------------------------------------
-- 3. EMPRESA e HORÁRIO DE FUNCIONAMENTO
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS EMPRESA (
    ID TINYINT NOT NULL DEFAULT 1,
    NOME_FANTASIA VARCHAR(150) NOT NULL DEFAULT '',
    RAZAO_SOCIAL VARCHAR(200) NOT NULL DEFAULT '',
    CNPJ VARCHAR(18) NOT NULL DEFAULT '',
    TELEFONE VARCHAR(20) NOT NULL DEFAULT '',
    EMAIL VARCHAR(255) NOT NULL DEFAULT '',
    ENDERECO VARCHAR(255) NOT NULL DEFAULT '',
    ENDERECO_NUMERO VARCHAR(20) NOT NULL DEFAULT '',
    ESTADO CHAR(2) NOT NULL DEFAULT 'SP',
    CIDADE VARCHAR(200) NOT NULL DEFAULT '',
    DATA_ATUALIZACAO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT PK_EMPRESA PRIMARY KEY (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO EMPRESA (ID, NOME_FANTASIA) VALUES (1, 'XTEC');

CREATE TABLE IF NOT EXISTS EMPRESA_HORARIO (
    DIA_SEMANA TINYINT NOT NULL,            -- 1 = segunda ... 7 = domingo
    ABERTO TINYINT(1) NOT NULL DEFAULT 1,
    ABRE TIME NOT NULL DEFAULT '18:00:00',
    FECHA TIME NOT NULL DEFAULT '23:00:00',
    CONSTRAINT PK_EMPRESA_HORARIO PRIMARY KEY (DIA_SEMANA)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO EMPRESA_HORARIO (DIA_SEMANA, ABERTO, ABRE, FECHA) VALUES
(1, 0, '18:00:00', '23:00:00'),
(2, 1, '18:00:00', '23:00:00'),
(3, 1, '18:00:00', '23:00:00'),
(4, 1, '18:00:00', '23:00:00'),
(5, 1, '18:00:00', '23:59:00'),
(6, 1, '12:00:00', '23:59:00'),
(7, 1, '12:00:00', '22:00:00');

-- --------------------------------------------------------------------------
-- 4. CONFIGURAÇÕES (USUARIO_ID = 0 vale para toda a operação)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS CONFIGURACAO (
    USUARIO_ID INT NOT NULL DEFAULT 0,
    CHAVE VARCHAR(60) NOT NULL,
    VALOR VARCHAR(255) NOT NULL DEFAULT '',
    CONSTRAINT PK_CONFIGURACAO PRIMARY KEY (USUARIO_ID, CHAVE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- 5. ESTADOS que faltavam (a base só trazia SP, RJ e MG)
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ESTADO (NOME, SIGLA) VALUES
('Acre', 'AC'), ('Alagoas', 'AL'), ('Amapá', 'AP'), ('Amazonas', 'AM'),
('Bahia', 'BA'), ('Ceará', 'CE'), ('Distrito Federal', 'DF'),
('Espírito Santo', 'ES'), ('Goiás', 'GO'), ('Maranhão', 'MA'),
('Mato Grosso', 'MT'), ('Mato Grosso do Sul', 'MS'), ('Pará', 'PA'),
('Paraíba', 'PB'), ('Paraná', 'PR'), ('Pernambuco', 'PE'), ('Piauí', 'PI'),
('Rio Grande do Norte', 'RN'), ('Rio Grande do Sul', 'RS'),
('Rondônia', 'RO'), ('Roraima', 'RR'), ('Santa Catarina', 'SC'),
('Sergipe', 'SE'), ('Tocantins', 'TO');
