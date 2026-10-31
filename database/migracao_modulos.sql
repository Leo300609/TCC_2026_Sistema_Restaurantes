-- ==========================================================================
-- MIGRAÇÃO DOS MÓDULOS FINAIS
-- Mesas, PDV, Cozinha, Compras, Fornecedores, Ficha técnica (baixa automática
-- de insumos), Auditoria, Segurança do login e Convites com validade.
--
-- Rode depois de migracao_crud.sql. Pode ser executado mais de uma vez: cada
-- coluna, índice e tabela só é criada se ainda não existir.
-- ==========================================================================

USE tcc;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS xtec_add_coluna;
DROP PROCEDURE IF EXISTS xtec_add_indice;
DELIMITER //
CREATE PROCEDURE xtec_add_coluna(IN p_tabela VARCHAR(64), IN p_coluna VARCHAR(64), IN p_definicao VARCHAR(500))
BEGIN
    SET @xtec_coluna_nova = 0;
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(p_tabela) AND LOWER(COLUMN_NAME) = LOWER(p_coluna)
    ) THEN
        SET @xtec_ddl = CONCAT('ALTER TABLE `', p_tabela, '` ADD COLUMN `', p_coluna, '` ', p_definicao);
        PREPARE xtec_stmt FROM @xtec_ddl;
        EXECUTE xtec_stmt;
        DEALLOCATE PREPARE xtec_stmt;
        SET @xtec_coluna_nova = 1;
    END IF;
END//

CREATE PROCEDURE xtec_add_indice(IN p_tabela VARCHAR(64), IN p_indice VARCHAR(64), IN p_colunas VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(p_tabela) AND LOWER(INDEX_NAME) = LOWER(p_indice)
    ) THEN
        SET @xtec_ddl = CONCAT('CREATE INDEX `', p_indice, '` ON `', p_tabela, '` (', p_colunas, ')');
        PREPARE xtec_stmt FROM @xtec_ddl;
        EXECUTE xtec_stmt;
        DEALLOCATE PREPARE xtec_stmt;
    END IF;
END//
DELIMITER ;

-- --------------------------------------------------------------------------
-- 1. MESAS DO SALÃO
--    A situação (livre, ocupada, aguardando pagamento) é calculada a partir
--    dos pedidos de salão em aberto com o mesmo número de mesa.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS MESA (
    ID INT NOT NULL AUTO_INCREMENT,
    NUMERO VARCHAR(20) NOT NULL,
    LUGARES TINYINT NOT NULL DEFAULT 4,
    AREA VARCHAR(50) NOT NULL DEFAULT 'Salão',
    STATUS ENUM('ATIVO', 'INATIVO') NOT NULL DEFAULT 'ATIVO',
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT PK_MESA PRIMARY KEY (ID),
    CONSTRAINT UQ_MESA_NUMERO UNIQUE (NUMERO)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO MESA (NUMERO, LUGARES, AREA) VALUES
('1', 2, 'Salão'), ('2', 2, 'Salão'), ('3', 4, 'Salão'), ('4', 4, 'Salão'),
('5', 4, 'Salão'), ('6', 6, 'Salão'), ('7', 4, 'Varanda'), ('8', 4, 'Varanda'),
('9', 6, 'Varanda'), ('10', 8, 'Varanda'), ('11', 2, 'Balcão'), ('12', 2, 'Balcão');

-- --------------------------------------------------------------------------
-- 2. FICHA TÉCNICA: quais insumos cada item do cardápio consome.
--    Na entrega do pedido o estoque dos insumos recebe baixa automática.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS FICHA_TECNICA (
    PRODUTO_ID INT NOT NULL,
    INSUMO_ID INT NOT NULL,
    QUANTIDADE INT NOT NULL DEFAULT 1,
    CONSTRAINT PK_FICHA_TECNICA PRIMARY KEY (PRODUTO_ID, INSUMO_ID),
    CONSTRAINT FK_FICHA_PRODUTO FOREIGN KEY (PRODUTO_ID) REFERENCES PRODUTO (ID) ON DELETE CASCADE,
    CONSTRAINT FK_FICHA_INSUMO FOREIGN KEY (INSUMO_ID) REFERENCES PRODUTO (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Itens de revenda (ex.: refrigerante em lata) descontam o próprio estoque.
CALL xtec_add_coluna('PRODUTO', 'CONTROLA_ESTOQUE', 'TINYINT(1) NOT NULL DEFAULT 0');
UPDATE PRODUTO p
JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
SET p.CONTROLA_ESTOQUE = 1
WHERE @xtec_coluna_nova = 1 AND c.NOME = 'Bebidas';

-- --------------------------------------------------------------------------
-- 3. PEDIDOS: troco no PDV e controle da baixa de estoque
-- --------------------------------------------------------------------------
CALL xtec_add_coluna('VENDA', 'VALOR_RECEBIDO', 'DECIMAL(10, 2) NULL');
CALL xtec_add_coluna('VENDA', 'ESTOQUE_BAIXADO', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL xtec_add_indice('VENDA', 'IDX_VENDA_DATA', 'DATA_CADASTRO');
CALL xtec_add_indice('VENDA', 'IDX_VENDA_ETAPA', 'ETAPA, TIPO_PEDIDO');
CALL xtec_add_indice('MOVIMENTACAO_ESTOQUE', 'IDX_MOVEST_DATA', 'DATA_MOVIMENTACAO');
CALL xtec_add_indice('CONTA_RECEBER', 'IDX_CR_VENC', 'DATA_VENCIMENTO');
CALL xtec_add_indice('CONTA_PAGAR', 'IDX_CP_VENC', 'DATA_VENCIMENTO');

-- --------------------------------------------------------------------------
-- 4. COMPRAS: nota fiscal e observação
-- --------------------------------------------------------------------------
CALL xtec_add_coluna('COMPRA', 'NUMERO_NF', 'VARCHAR(30) NULL');
CALL xtec_add_coluna('COMPRA', 'OBSERVACAO', 'VARCHAR(255) NULL');
CALL xtec_add_indice('COMPRA', 'IDX_COMPRA_DATA', 'DATA_CADASTRO');
-- Fornecedor pode ser cadastrado sem contato e com e-mail
CALL xtec_add_coluna('FORNECEDOR', 'EMAIL', 'VARCHAR(255) NULL');

-- --------------------------------------------------------------------------
-- 5. USUÁRIOS: último acesso (equipe online) e convites com validade
-- --------------------------------------------------------------------------
CALL xtec_add_coluna('USUARIOS', 'ULTIMO_ACESSO', 'DATETIME NULL');

CALL xtec_add_coluna('convites_equipe', 'nivel_sistema', "ENUM('ADMIN','FUNCIONARIO') NOT NULL DEFAULT 'FUNCIONARIO'");
CALL xtec_add_coluna('convites_equipe', 'expira_em', 'DATETIME NULL');
CALL xtec_add_coluna('convites_equipe', 'criado_por', 'INT NULL');
CALL xtec_add_coluna('convites_equipe', 'usado_por', 'INT NULL');
ALTER TABLE convites_equipe MODIFY status ENUM('pendente', 'usado', 'revogado') NOT NULL DEFAULT 'pendente';
UPDATE convites_equipe SET expira_em = DATE_ADD(data_criacao, INTERVAL 7 DAY) WHERE expira_em IS NULL;

-- --------------------------------------------------------------------------
-- 6. SEGURANÇA DO LOGIN: tentativas (bloqueio após 5 erros em 15 minutos)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS LOGIN_TENTATIVA (
    ID INT NOT NULL AUTO_INCREMENT,
    EMAIL VARCHAR(255) NOT NULL,
    IP VARCHAR(45) NOT NULL,
    SUCESSO TINYINT(1) NOT NULL DEFAULT 0,
    DATA DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT PK_LOGIN_TENTATIVA PRIMARY KEY (ID),
    INDEX IDX_TENTATIVA_EMAIL (EMAIL, DATA),
    INDEX IDX_TENTATIVA_IP (IP, DATA)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- 7. AUDITORIA: quem fez o quê e quando
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS LOG_ATIVIDADE (
    ID INT NOT NULL AUTO_INCREMENT,
    USUARIO_ID INT NULL,
    USUARIO_NOME VARCHAR(255) NOT NULL DEFAULT '',
    MODULO VARCHAR(40) NOT NULL,
    ACAO VARCHAR(40) NOT NULL DEFAULT '',
    DESCRICAO VARCHAR(500) NOT NULL,
    IP VARCHAR(45) NOT NULL DEFAULT '',
    DATA DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT PK_LOG_ATIVIDADE PRIMARY KEY (ID),
    INDEX IDX_LOG_DATA (DATA),
    INDEX IDX_LOG_USUARIO (USUARIO_ID, DATA)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS xtec_add_coluna;
DROP PROCEDURE IF EXISTS xtec_add_indice;
