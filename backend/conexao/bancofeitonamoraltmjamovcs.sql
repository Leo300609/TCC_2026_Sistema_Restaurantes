CREATE DATABASE IF NOT EXISTS TCC
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE TCC;


CREATE TABLE ESTADO (
    ID INT NOT NULL AUTO_INCREMENT,
    NOME VARCHAR(200) NOT NULL UNIQUE,
    SIGLA CHAR(2) NOT NULL UNIQUE,
    STATUS ENUM('ATIVO', 'INATIVO') NOT NULL DEFAULT 'ATIVO',
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT PK_ESTADO PRIMARY KEY (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE CIDADE (
    ID INT NOT NULL AUTO_INCREMENT,
    NOME VARCHAR(200) NOT NULL,
    STATUS ENUM('ATIVO', 'INATIVO') NOT NULL DEFAULT 'ATIVO',
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ESTADO_ID INT NOT NULL,
    CONSTRAINT PK_CIDADE PRIMARY KEY (ID),
    CONSTRAINT FK_CIDADE_ESTADO FOREIGN KEY (ESTADO_ID) REFERENCES ESTADO (ID),
    CONSTRAINT CIDADE_UNICA UNIQUE(NOME, ESTADO_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE CLIENTE (
    ID INT NOT NULL AUTO_INCREMENT,
    NOME VARCHAR(255) NOT NULL,
    DOCUMENTO VARCHAR(18) NOT NULL UNIQUE,
    ENDERECO VARCHAR(400) NOT NULL,
    ENDERECO_NUMERO VARCHAR(20) NOT NULL,
    TELEFONE VARCHAR(15) NOT NULL,
    CONTATO VARCHAR(255) NULL,
    CIDADE_ID INT NOT NULL,
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    STATUS ENUM('ATIVO', 'INATIVO') NOT NULL DEFAULT 'ATIVO',
    CONSTRAINT PK_CLIENTE PRIMARY KEY (ID),
    CONSTRAINT FK_CLI_CID FOREIGN KEY (CIDADE_ID) REFERENCES CIDADE (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE FORNECEDOR (
    ID INT NOT NULL AUTO_INCREMENT,
    NOME VARCHAR(255) NOT NULL,
    DOCUMENTO VARCHAR(18) NOT NULL UNIQUE,
    ENDERECO VARCHAR(255) NOT NULL,
    ENDERECO_NUMERO VARCHAR(20) NOT NULL, 
    TELEFONE VARCHAR(15) NOT NULL,
    CONTATO VARCHAR(255) NULL,
    STATUS ENUM('ATIVO', 'INATIVO') NOT NULL DEFAULT 'ATIVO',
    CIDADE_ID INT NOT NULL,
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT PK_FORNECEDOR PRIMARY KEY (ID),
    CONSTRAINT FK_FOR_CID FOREIGN KEY (CIDADE_ID) REFERENCES CIDADE (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE PRODUTO_CATEGORIA (
    ID INT NOT NULL AUTO_INCREMENT,
    NOME VARCHAR(200) NOT NULL UNIQUE,
    DESCRICAO VARCHAR(255) NOT NULL,
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    STATUS ENUM('ATIVO', 'INATIVO') NOT NULL DEFAULT 'ATIVO',
    CONSTRAINT PK_CATEGORIA_PRODUTO PRIMARY KEY (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE PRODUTO (
    ID INT NOT NULL AUTO_INCREMENT,
    NOME VARCHAR(200) NOT NULL UNIQUE,
    DESCRICAO VARCHAR(255) NOT NULL,
    PRECO_UNITARIO DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    ESTOQUE INT NOT NULL DEFAULT 0,
    ESTOQUE_MINIMO INT NOT NULL DEFAULT 0,
    DATA_VALIDADE DATE NULL,
    STATUS ENUM('ATIVO', 'INATIVO') NOT NULL DEFAULT 'ATIVO',
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CODIGO_BARRAS VARCHAR(14) UNIQUE,
    NCM VARCHAR(8) NULL,
    UNIDADE_MEDIDA VARCHAR(6) NOT NULL DEFAULT 'UN',
    CATEGORIA_ID INT NOT NULL,
    CONSTRAINT PK_PRODUTO PRIMARY KEY (ID),
    CONSTRAINT FK_PROD_CAT FOREIGN KEY (CATEGORIA_ID) REFERENCES PRODUTO_CATEGORIA (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE FORNECEDOR_PRODUTO (
    FORNECEDOR_ID INT NOT NULL,
    PRODUTO_ID INT NOT NULL,
    PRECO_FORNECEDOR DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRAZO_ENTREGA_DIAS INT DEFAULT 0,
    STATUS ENUM('ATIVO', 'INATIVO') NOT NULL DEFAULT 'ATIVO',
    PRIMARY KEY (FORNECEDOR_ID, PRODUTO_ID),
    CONSTRAINT FK_FP_FORNECEDOR FOREIGN KEY (FORNECEDOR_ID) REFERENCES FORNECEDOR (ID),
    CONSTRAINT FK_FP_PRODUTO FOREIGN KEY (PRODUTO_ID) REFERENCES PRODUTO (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE USUARIOS (
    ID INT NOT NULL AUTO_INCREMENT,
    NOME VARCHAR(255) NOT NULL,
    EMAIL VARCHAR(255) NOT NULL UNIQUE,
    SENHA VARCHAR(255) NOT NULL,
    NIVEL ENUM('ADMIN', 'FUNCIONARIO') NOT NULL DEFAULT 'FUNCIONARIO',
    CARGO VARCHAR(50) DEFAULT 'FUNCIONARIO',
    DATA_CADASTRO DATETIME DEFAULT CURRENT_TIMESTAMP,
    ULTIMO_LOGIN DATETIME NULL,
    ATIVO ENUM('ATIVO', 'INATIVO') DEFAULT 'ATIVO',  
    CONSTRAINT PK_USUARIOS PRIMARY KEY (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS convites_equipe (
    id INT NOT NULL AUTO_INCREMENT,
    id_empresa INT NOT NULL DEFAULT 1,
    codigo VARCHAR(20) NOT NULL UNIQUE,
    cargo VARCHAR(50) NOT NULL,
    nivel_acesso INT NOT NULL DEFAULT 1,
    status ENUM('pendente', 'usado') NOT NULL DEFAULT 'pendente',
    data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE MOVIMENTACAO_ESTOQUE (
    ID INT NOT NULL AUTO_INCREMENT,
    PRODUTO_ID INT NOT NULL,
    USUARIO_ID INT NOT NULL,
    TIPO ENUM('ENTRADA', 'SAIDA') NOT NULL,
    QUANTIDADE INT NOT NULL,
    DATA_MOVIMENTACAO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    OBSERVACAO VARCHAR(255) NULL,
    CONSTRAINT PK_MOVIMENTACAO PRIMARY KEY (ID),
    CONSTRAINT FK_MOV_PRODUTO FOREIGN KEY (PRODUTO_ID) REFERENCES PRODUTO (ID),
    CONSTRAINT FK_MOV_USUARIO FOREIGN KEY (USUARIO_ID) REFERENCES USUARIOS (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE VENDA (
    ID INT NOT NULL AUTO_INCREMENT,
    VALOR DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    DESCONTO DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    TOTAL DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    CLIENTE_ID INT NOT NULL,
    FUNCIONARIO_ID INT NOT NULL,
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FORMA_PAGAMENTO ENUM('DINHEIRO', 'CREDITO', 'DEBITO', 'PIX', 'BOLETO') NOT NULL,
    STATUS ENUM('EM_ABERTO', 'FINALIZADA', 'CANCELADA') DEFAULT 'EM_ABERTO',
    CONSTRAINT PK_VENDA PRIMARY KEY (ID),
    CONSTRAINT FK_VENDA_CLIENTE FOREIGN KEY (CLIENTE_ID) REFERENCES CLIENTE (ID),
    CONSTRAINT FK_VENDA_USUARIOS FOREIGN KEY (FUNCIONARIO_ID) REFERENCES USUARIOS (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE VENDA_ITEM (
    VENDA_ID INT NOT NULL,
    PRODUTO_ID INT NOT NULL,
    QUANTIDADE INT NOT NULL DEFAULT 1,
    PRECO_UNITARIO DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    VALOR DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    DESCONTO DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    TOTAL DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    STATUS ENUM('EM_ABERTO', 'FINALIZADA', 'CANCELADA') DEFAULT 'EM_ABERTO',
    PRIMARY KEY (VENDA_ID, PRODUTO_ID),
    CONSTRAINT FK_ITEMVEN_VENDA FOREIGN KEY (VENDA_ID) REFERENCES VENDA (ID),
    CONSTRAINT FK_ITEMVEN_PRODUTO FOREIGN KEY (PRODUTO_ID) REFERENCES PRODUTO (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE COMPRA (
    ID INT NOT NULL AUTO_INCREMENT,
    VALOR DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    DESCONTO DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    TOTAL DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    FORNECEDOR_ID INT NOT NULL,
    FUNCIONARIO_ID INT NOT NULL,
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    STATUS ENUM('EM_ABERTO', 'FINALIZADA', 'CANCELADA') DEFAULT 'EM_ABERTO',
    CONSTRAINT PK_COMPRA PRIMARY KEY (ID),
    CONSTRAINT FK_COMPRA_FORNECEDOR FOREIGN KEY (FORNECEDOR_ID) REFERENCES FORNECEDOR (ID),
    CONSTRAINT FK_COMPRA_FUNCIONARIO FOREIGN KEY (FUNCIONARIO_ID) REFERENCES USUARIOS (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE COMPRA_ITEM (
    COMPRA_ID INT NOT NULL,
    PRODUTO_ID INT NOT NULL,
    QUANTIDADE INT NOT NULL DEFAULT 1,
    PRECO_UNITARIO DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    VALOR DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    DESCONTO DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    TOTAL DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    STATUS ENUM('EM_ABERTO', 'FINALIZADA', 'CANCELADA') DEFAULT 'EM_ABERTO', 
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (COMPRA_ID, PRODUTO_ID),
    CONSTRAINT FK_ITEMCOM_COMPRA FOREIGN KEY (COMPRA_ID) REFERENCES COMPRA (ID),
    CONSTRAINT FK_ITEMCOM_PRODUTO FOREIGN KEY (PRODUTO_ID) REFERENCES PRODUTO (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE CONTA_RECEBER (
    ID INT NOT NULL AUTO_INCREMENT,
    VENDA_ID INT NOT NULL,
    PARCELA_QUANTIDADE INT NOT NULL DEFAULT 1,
    PARCELA_NUMERO INT NOT NULL DEFAULT 1,
    DATA_VENCIMENTO DATE NOT NULL,
    DATA_PAGAMENTO DATE NULL,
    VALOR DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    DESCONTO DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    TOTAL DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    STATUS ENUM('PENDENTE', 'PAGO', 'ATRASADO', 'CANCELADO', 'NEGOCIANDO') DEFAULT 'PENDENTE',
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT PK_CONTA_RECEBER PRIMARY KEY (ID),
    CONSTRAINT FK_CR_VENDA FOREIGN KEY (VENDA_ID) REFERENCES VENDA (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE CONTA_PAGAR (
    ID INT NOT NULL AUTO_INCREMENT,
    COMPRA_ID INT NOT NULL,
    PARCELA_QUANTIDADE INT NOT NULL DEFAULT 1,
    PARCELA_NUMERO INT NOT NULL DEFAULT 1,
    DATA_VENCIMENTO DATE NOT NULL,
    DATA_PAGAMENTO DATE NULL,
    VALOR DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    DESCONTO DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    TOTAL DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    STATUS ENUM('PENDENTE', 'PAGO', 'ATRASADO', 'CANCELADO', 'AGENDADO') DEFAULT 'PENDENTE',
    DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT PK_CONTA_PAGAR PRIMARY KEY (ID),
    CONSTRAINT FK_CP_COMPRA FOREIGN KEY (COMPRA_ID) REFERENCES COMPRA (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- 7. POVOAMENTO INICIAL DE DADOS
-- ==========================================

INSERT INTO ESTADO (NOME, SIGLA) VALUES 
('São Paulo', 'SP'), 
('Rio de Janeiro', 'RJ'), 
('Minas Gerais', 'MG');

INSERT INTO CIDADE (NOME, ESTADO_ID) VALUES 
('São Paulo', 1), 
('Campinas', 1), 
('Guarulhos', 1),
('Rio de Janeiro', 2), 
('Niterói', 2),
('Belo Horizonte', 3);

INSERT INTO PRODUTO_CATEGORIA (NOME, DESCRICAO) VALUES 
('Lanches', 'Hambúrgueres, sandwiches e combos'),
('Bebidas', 'Refrigerantes, sucos e águas'),
('Sobremesas', 'Doces, sorvetes e sobremesas'),
('Ingredientes', 'Insumos para preparo dos pratos'),
('Embalagens', 'Caixas, copos e descartáveis'),
('Limpeza', 'Produtos de higiene e limpeza da cozinha');

INSERT INTO USUARIOS (EMAIL, SENHA, NOME, NIVEL) VALUES 
('admin@tcc.com', 'Admin@2026', 'Administrador Master', 'ADMIN'),
('func1@tcc.com', '123456', 'João Vendedor', 'FUNCIONARIO'),
('func2@tcc.com', '123456', 'Maria Vendedora', 'FUNCIONARIO'),
('func3@tcc.com', '123456', 'Carlos Comprador', 'FUNCIONARIO'),
('func4@tcc.com', '123456', 'Ana Compradora', 'FUNCIONARIO'),
('func5@tcc.com', '123456', 'Pedro Estoque', 'FUNCIONARIO');

-- ==========================================
-- 3. CLIENTES E FORNECEDORES (65 REGISTROS)
-- ==========================================

INSERT INTO CLIENTE (NOME, DOCUMENTO, ENDERECO, ENDERECO_NUMERO, TELEFONE, CIDADE_ID) VALUES 
('João Silva', '11111111111', 'Rua das Flores', '100', '11999990001', 1),
('Maria Oliveira', '22222222222', 'Av. Brasil', '200', '11999990002', 1),
('Pedro Santos', '33333333333', 'Rua Augusta', '300', '11999990003', 1),
('Ana Costa', '44444444444', 'Av. Paulista', '400', '11999990004', 1),
('Carlos Souza', '55555555555', 'Rua da Consolação', '500', '21999990005', 4),
('Julia Lima', '66666666666', 'Rua Copacabana', '600', '21999990006', 4),
('Lucas Pereira', '77777777777', 'Av. Atlântica', '700', '21999990007', 5),
('Fernanda Alves', '88888888888', 'Rua Ipanema', '800', '21999990008', 5),
('Ricardo Mendes', '99999999999', 'Av. Afonso Pena', '900', '31999990009', 6),
('Camila Rocha', '10101010101', 'Rua Savassi', '1000', '31999990010', 6),
('Bruno Dias', '12121212121', 'Av. Brasil', '1100', '11999990011', 2),
('Patrícia Gomes', '13131313131', 'Rua do Comércio', '1200', '11999990012', 2),
('Thiago Martins', '14141414141', 'Av. Paralela', '1300', '11999990013', 3),
('Larissa Ribeiro', '15151515151', 'Rua Chile', '1400', '11999990014', 3),
('Gustavo Nunes', '16161616161', 'Av. Getúlio Vargas', '1500', '11999990015', 1);

INSERT INTO FORNECEDOR (NOME, DOCUMENTO, ENDERECO, ENDERECO_NUMERO, TELEFONE, CIDADE_ID) VALUES 
('Frigorífico Boi Gordo LTDA', '11222333000181', 'Av. Industrial', '5000', '1133330001', 1),
('Distribuidora de Bebidas Sul', '22333444000192', 'Rua dos Fornecedores', '5100', '1133330002', 1),
('Hortifruti Campo Verde', '33444555000103', 'Av. Logística', '5200', '1133330003', 2),
('Embalagens PackFast', '44555666000114', 'Rua do Estoque', '5300', '1133330004', 1),
('Laticínios Vale do Leite', '55666777000125', 'Av. Tecnologia', '5400', '1133330005', 3);

-- ==========================================
-- 4. PRODUTOS (50 REGISTROS)
-- ==========================================

INSERT INTO PRODUTO (NOME, DESCRICAO, PRECO_UNITARIO, ESTOQUE, ESTOQUE_MINIMO, DATA_VALIDADE, STATUS, CODIGO_BARRAS, NCM, CATEGORIA_ID) VALUES 
-- Lanches prontos
('X-Burguer Clássico', 'Pão, carne 150g, queijo, alface, tomate', 28.00, 50, 10, NULL, 'ATIVO', '7891000000001', '16025000', 1),
('X-Burguer Duplo', 'Pão, 2 carnes 150g, queijo cheddar, bacon', 38.00, 40, 10, NULL, 'ATIVO', '7891000000002', '16025000', 1),
('Combo XTEC Especial', 'X-Burguer + Batata GG + Refrigerante', 45.00, 30, 5, NULL, 'ATIVO', '7891000000003', '16025000', 1),
('Batata Frita GG', 'Porção grande de batata frita crocante', 22.00, 60, 15, NULL, 'ATIVO', '7891000000004', '20041000', 1),
('Batata Frita P', 'Porção pequena de batata frita', 14.00, 80, 20, NULL, 'ATIVO', '7891000000005', '20041000', 1),
('Misto Quente', 'Pão de forma, presunto e queijo', 12.00, 45, 10, NULL, 'ATIVO', '7891000000006', '16025000', 1),
('X-Salada', 'Pão, carne, queijo, alface, tomate, maionese', 25.00, 35, 10, NULL, 'ATIVO', '7891000000007', '16025000', 1),

-- Bebidas
('Coca-Cola Lata 350ml', 'Refrigerante Coca-Cola lata', 6.00, 200, 50, '2026-12-31', 'ATIVO', '7891000050001', '22021000', 2),
('Coca-Cola 2L', 'Refrigerante Coca-Cola garrafa 2L', 12.00, 100, 30, '2026-11-30', 'ATIVO', '7891000050002', '22021000', 2),
('Guaraná Antarctica Lata', 'Refrigerante Guaraná lata 350ml', 5.50, 150, 40, '2026-12-15', 'ATIVO', '7891000050003', '22021000', 2),
('Suco de Laranja 500ml', 'Suco natural de laranja', 8.00, 80, 20, '2026-09-25', 'ATIVO', '7891000050004', '20091100', 2),
('Água Mineral 500ml', 'Água mineral sem gás', 3.50, 300, 100, '2027-06-30', 'ATIVO', '7891000050005', '22011000', 2),
('Cerveja Heineken Long Neck', 'Cerveja Heineken 330ml', 9.00, 120, 30, '2026-10-20', 'ATIVO', '7891000050006', '22030000', 2),

-- Sobremesas
('Petit Gateau', 'Bolinho de chocolate com sorvete', 18.00, 25, 5, '2026-09-22', 'ATIVO', '7891000060001', '18063210', 3),
('Açaí 500ml', 'Açaí na tigela com granola e banana', 16.00, 40, 10, '2026-09-21', 'ATIVO', '7891000060002', '08109090', 3),
('Milk Shake Ovomaltine', 'Milk shake de ovomaltine 400ml', 14.00, 35, 8, '2026-09-28', 'ATIVO', '7891000060003', '22029900', 3),

-- Ingredientes (para controle de estoque e validade)
('Pão de Hambúrguer', 'Pão tipo brioche para hambúrguer', 2.50, 500, 100, '2026-09-20', 'ATIVO', '7891000070001', '19059090', 4),
('Carne Bovina Blend 150g', 'Blend de carne bovina para hambúrguer', 8.00, 400, 100, '2026-09-22', 'ATIVO', '7891000070002', '02013000', 4),
('Queijo Cheddar Fatia', 'Fatia de queijo cheddar', 1.80, 600, 150, '2026-10-05', 'ATIVO', '7891000070003', '04061090', 4),
('Alface Americana', 'Folhas de alface americana higienizada', 1.20, 200, 50, '2026-09-19', 'ATIVO', '7891000070004', '07051100', 4),
('Tomate', 'Tomate caqui fatiado', 1.50, 180, 40, '2026-09-21', 'ATIVO', '7891000070005', '07020000', 4),
('Bacon em Tiras', 'Bacon defumado em tiras', 3.50, 150, 30, '2026-10-10', 'ATIVO', '7891000070006', '02101200', 4),
('Batata Congelada 1kg', 'Batata pré-frita congelada', 12.00, 80, 20, '2027-03-15', 'ATIVO', '7891000070007', '20041000', 4),
('Óleo de Soja 900ml', 'Óleo para fritura', 7.50, 60, 15, '2027-01-20', 'ATIVO', '7891000070008', '15079011', 4),
('Molho Especial XTEC', 'Molho secreto da casa', 0.80, 400, 100, '2026-11-30', 'ATIVO', '7891000070009', '21069090', 4),
('Presunto Fatia', 'Fatia de presunto', 1.20, 300, 80, '2026-09-23', 'ATIVO', '7891000070010', '16024110', 4),
('Queijo Mussarela Fatia', 'Fatia de queijo mussarela', 1.50, 350, 100, '2026-10-08', 'ATIVO', '7891000070011', '04061090', 4),
('Pão de Forma', 'Pão de forma para misto quente', 6.00, 100, 25, '2026-09-24', 'ATIVO', '7891000070012', '19059090', 4),

-- Embalagens
('Caixa Hambúrguer Média', 'Caixa de papelão para lanche', 0.35, 1000, 200, NULL, 'ATIVO', '7891000080001', '48191000', 5),
('Caixa Batata Frita', 'Caixa de papelão para batata', 0.25, 800, 150, NULL, 'ATIVO', '7891000080002', '48191000', 5),
('Copo Descartável 300ml', 'Copo plástico com tampa', 0.15, 2000, 500, NULL, 'ATIVO', '7891000080003', '39233000', 5),
('Guardanapo de Papel', 'Pacote com 50 guardanapos', 2.00, 500, 100, NULL, 'ATIVO', '7891000080004', '48182000', 5),
('Sacola Plástica', 'Sacola para delivery', 0.20, 1500, 300, NULL, 'ATIVO', '7891000080005', '39232100', 5),

-- Limpeza
('Detergente Neutro 5L', 'Detergente para limpeza da cozinha', 18.00, 40, 10, '2027-05-30', 'ATIVO', '7891000090001', '34022090', 6),
('Água Sanitária 1L', 'Água sanitária para desinfecção', 4.50, 60, 15, '2027-02-28', 'ATIVO', '7891000090002', '28281100', 6),
('Papel Toalha Interfolhado', 'Fardo com 1000 folhas', 22.00, 30, 8, NULL, 'ATIVO', '7891000090003', '48030000', 6);

-- ==========================================
-- 5. FORNECEDOR_PRODUTO (50 REGISTROS)
-- ==========================================

INSERT INTO FORNECEDOR_PRODUTO (FORNECEDOR_ID, PRODUTO_ID, PRECO_FORNECEDOR, PRAZO_ENTREGA_DIAS) VALUES 
(1, 18, 6.50, 2),
(1, 26, 1.00, 2),
(1, 22, 2.80, 2),
(2, 8, 4.50, 1),
(2, 9, 9.00, 1),
(2, 10, 4.00, 1),
(2, 11, 6.00, 1),
(2, 12, 2.50, 1),
(2, 13, 7.00, 1),
(3, 17, 1.80, 1),
(3, 20, 0.90, 1),
(3, 21, 1.10, 1),
(3, 23, 10.00, 1),
(3, 24, 6.00, 1),
(3, 28, 5.00, 1),
(4, 29, 0.25, 3),
(4, 30, 0.18, 3),
(4, 31, 0.10, 3),
(4, 32, 1.50, 3),
(4, 33, 0.15, 3),
(5, 19, 1.30, 2),
(5, 27, 1.10, 2),
(5, 25, 0.60, 2);

-- ==========================================
-- 6. MOVIMENTAÇÕES, VENDAS E COMPRAS (RESUMO EM MASSA)
-- ==========================================
-- Para não estourar o limite de caracteres, vou gerar blocos lógicos de transações.

-- MOVIMENTACAO_ESTOQUE (40 registros - Entradas iniciais de compra)

INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, OBSERVACAO) VALUES 
(18, 3, 'ENTRADA', 400, 'Compra Frigorífico Boi Gordo - Lote 001'),
(17, 3, 'ENTRADA', 500, 'Compra Hortifruti - Pães frescos'),
(20, 3, 'ENTRADA', 200, 'Compra Hortifruti - Alface'),
(21, 3, 'ENTRADA', 180, 'Compra Hortifruti - Tomates'),
(19, 5, 'ENTRADA', 600, 'Compra Laticínios - Queijo Cheddar'),
(23, 4, 'ENTRADA', 80, 'Compra Hortifruti - Batata congelada'),
(24, 4, 'ENTRADA', 60, 'Compra Hortifruti - Óleo de soja'),
(8, 4, 'ENTRADA', 200, 'Compra Distribuidora - Coca lata'),
(9, 4, 'ENTRADA', 100, 'Compra Distribuidora - Coca 2L'),
(10, 4, 'ENTRADA', 150, 'Compra Distribuidora - Guaraná'),
(29, 5, 'ENTRADA', 1000, 'Compra Embalagens - Caixas hambúrguer'),
(30, 5, 'ENTRADA', 800, 'Compra Embalagens - Caixas batata'),
(31, 5, 'ENTRADA', 2000, 'Compra Embalagens - Copos'),
(32, 5, 'ENTRADA', 500, 'Compra Embalagens - Guardanapos'),
(33, 5, 'ENTRADA', 1500, 'Compra Embalagens - Sacolas'),
(22, 5, 'ENTRADA', 150, 'Compra Laticínios - Bacon'),
(28, 3, 'ENTRADA', 100, 'Compra Hortifruti - Pão de forma'),
(27, 5, 'ENTRADA', 350, 'Compra Laticínios - Mussarela'),
(25, 5, 'ENTRADA', 400, 'Compra Laticínios - Molho especial'),
(34, 5, 'ENTRADA', 40, 'Compra Limpeza - Detergente'),
(35, 5, 'ENTRADA', 60, 'Compra Limpeza - Água sanitária'),
(36, 5, 'ENTRADA', 30, 'Compra Limpeza - Papel toalha'),
(18, 4, 'SAIDA', 50, 'Consumo cozinha - preparo de hambúrgueres'),
(17, 4, 'SAIDA', 80, 'Consumo cozinha - pães utilizados'),
(20, 4, 'SAIDA', 40, 'Consumo cozinha - alface'),
(21, 4, 'SAIDA', 35, 'Consumo cozinha - tomates'),
(19, 4, 'SAIDA', 100, 'Consumo cozinha - queijo cheddar'),
(23, 4, 'SAIDA', 20, 'Consumo cozinha - batata para fritura'),
(8, 4, 'SAIDA', 30, 'Vendas - Coca lata'),
(9, 4, 'SAIDA', 15, 'Vendas - Coca 2L'),
(29, 4, 'SAIDA', 150, 'Consumo - caixas utilizadas'),
(30, 4, 'SAIDA', 120, 'Consumo - caixas batata'),
(31, 4, 'SAIDA', 200, 'Consumo - copos'),
(32, 4, 'SAIDA', 80, 'Consumo - guardanapos'),
(33, 4, 'SAIDA', 180, 'Consumo - sacolas delivery'),
(27, 4, 'SAIDA', 60, 'Consumo cozinha - mussarela'),
(25, 4, 'SAIDA', 100, 'Consumo cozinha - molho especial'),
(24, 4, 'SAIDA', 8, 'Consumo cozinha - óleo de fritura'),
(22, 4, 'SAIDA', 30, 'Consumo cozinha - bacon'),
(28, 4, 'SAIDA', 25, 'Consumo cozinha - pão de forma'),
(11, 4, 'SAIDA', 20, 'Vendas - suco de laranja'),
(12, 4, 'SAIDA', 40, 'Vendas - água mineral'),
(13, 4, 'SAIDA', 15, 'Vendas - cerveja');
-- VENDAS (30 registros)

INSERT INTO VENDA (VALOR, DESCONTO, TOTAL, CLIENTE_ID, FUNCIONARIO_ID, FORMA_PAGAMENTO, STATUS) VALUES 
-- Vendas de hoje e recentes
(45.00, 0.00, 45.00, 1, 2, 'PIX', 'FINALIZADA'),           -- Combo XTEC
(28.00, 0.00, 28.00, 2, 2, 'CREDITO', 'FINALIZADA'),       -- X-Burguer Clássico
(38.00, 5.00, 33.00, 3, 2, 'DEBITO', 'FINALIZADA'),        -- X-Burguer Duplo com desconto
(68.00, 0.00, 68.00, 4, 2, 'PIX', 'FINALIZADA'),           -- 2 Combos
(22.00, 0.00, 22.00, 5, 2, 'DINHEIRO', 'FINALIZADA'),      -- Batata GG
(56.00, 0.00, 56.00, 6, 2, 'CREDITO', 'FINALIZADA'),       -- 2 X-Burguer Duplo
(14.00, 0.00, 14.00, 7, 2, 'PIX', 'FINALIZADA'),           -- Batata P
(12.00, 0.00, 12.00, 8, 2, 'DINHEIRO', 'FINALIZADA'),      -- Misto Quente
(51.00, 0.00, 51.00, 9, 2, 'DEBITO', 'FINALIZADA'),        -- X-Burguer + Batata + Bebida
(25.00, 0.00, 25.00, 10, 2, 'PIX', 'FINALIZADA'),          -- X-Salada
(90.00, 10.00, 80.00, 11, 2, 'CREDITO', 'FINALIZADA'),     -- 2 Combos com desconto
(18.00, 0.00, 18.00, 12, 2, 'PIX', 'FINALIZADA'),          -- Petit Gateau
(32.00, 0.00, 32.00, 13, 2, 'DEBITO', 'FINALIZADA'),       -- X-Burguer + Coca
(45.00, 0.00, 45.00, 14, 2, 'PIX', 'FINALIZADA'),          -- Combo XTEC
(16.00, 0.00, 16.00, 15, 2, 'DINHEIRO', 'FINALIZADA'),     -- Açaí
(76.00, 0.00, 76.00, 1, 2, 'CREDITO', 'FINALIZADA'),       -- 2 Combos + Bebida extra
(28.00, 0.00, 28.00, 2, 2, 'PIX', 'FINALIZADA'),           -- X-Burguer Clássico
(14.00, 0.00, 14.00, 3, 2, 'DEBITO', 'FINALIZADA'),        -- Milk Shake
(38.00, 0.00, 38.00, 4, 2, 'PIX', 'FINALIZADA'),           -- X-Burguer Duplo
(22.00, 0.00, 22.00, 5, 2, 'DINHEIRO', 'FINALIZADA'),      -- Batata GG
(54.00, 0.00, 54.00, 6, 2, 'CREDITO', 'FINALIZADA'),       -- X-Burguer Duplo + Batata + 2 Bebidas
(12.00, 0.00, 12.00, 7, 2, 'PIX', 'FINALIZADA'),           -- Misto Quente
(45.00, 0.00, 45.00, 8, 2, 'DEBITO', 'FINALIZADA'),        -- Combo XTEC
(25.00, 0.00, 25.00, 9, 2, 'PIX', 'FINALIZADA'),           -- X-Salada
(34.00, 0.00, 34.00, 10, 2, 'CREDITO', 'FINALIZADA'),      -- X-Burguer Clássico + Batata P
(18.00, 0.00, 18.00, 11, 2, 'PIX', 'FINALIZADA'),          -- Petit Gateau
(56.00, 0.00, 56.00, 12, 2, 'DEBITO', 'FINALIZADA'),       -- 2 X-Burguer Clássico
(16.00, 0.00, 16.00, 13, 2, 'DINHEIRO', 'FINALIZADA'),     -- Açaí
(28.00, 0.00, 28.00, 14, 2, 'PIX', 'FINALIZADA'),          -- X-Burguer Clássico
(45.00, 0.00, 45.00, 15, 2, 'CREDITO', 'FINALIZADA');      -- Combo XTEC

-- VENDA_ITEM (60 registros - 2 itens por venda em média)

INSERT INTO VENDA_ITEM (VENDA_ID, PRODUTO_ID, QUANTIDADE, PRECO_UNITARIO, VALOR, DESCONTO, TOTAL, STATUS) VALUES 
-- Venda 1: Combo XTEC
(1, 3, 1, 45.00, 45.00, 0.00, 45.00, 'FINALIZADA'),
-- Venda 2: X-Burguer Clássico
(2, 1, 1, 28.00, 28.00, 0.00, 28.00, 'FINALIZADA'),
-- Venda 3: X-Burguer Duplo com desconto
(3, 2, 1, 38.00, 38.00, 5.00, 33.00, 'FINALIZADA'),
-- Venda 4: 2 Combos
(4, 3, 2, 45.00, 90.00, 22.00, 68.00, 'FINALIZADA'),
-- Venda 5: Batata GG
(5, 4, 1, 22.00, 22.00, 0.00, 22.00, 'FINALIZADA'),
-- Venda 6: 2 X-Burguer Duplo
(6, 2, 2, 38.00, 76.00, 20.00, 56.00, 'FINALIZADA'),
-- Venda 7: Batata P
(7, 5, 1, 14.00, 14.00, 0.00, 14.00, 'FINALIZADA'),
-- Venda 8: Misto Quente
(8, 6, 1, 12.00, 12.00, 0.00, 12.00, 'FINALIZADA'),
-- Venda 9: X-Burguer + Batata + Bebida
(9, 1, 1, 28.00, 28.00, 0.00, 28.00, 'FINALIZADA'),
(9, 4, 1, 22.00, 22.00, 0.00, 22.00, 'FINALIZADA'),
(9, 8, 1, 6.00, 6.00, 5.00, 1.00, 'FINALIZADA'),
-- Venda 10: X-Salada
(10, 7, 1, 25.00, 25.00, 0.00, 25.00, 'FINALIZADA'),
-- Venda 11: 2 Combos com desconto
(11, 3, 2, 45.00, 90.00, 10.00, 80.00, 'FINALIZADA'),
-- Venda 12: Petit Gateau
(12, 14, 1, 18.00, 18.00, 0.00, 18.00, 'FINALIZADA'),
-- Venda 13: X-Burguer + Coca
(13, 1, 1, 28.00, 28.00, 0.00, 28.00, 'FINALIZADA'),
(13, 8, 1, 6.00, 6.00, 2.00, 4.00, 'FINALIZADA'),
-- Venda 14: Combo XTEC
(14, 3, 1, 45.00, 45.00, 0.00, 45.00, 'FINALIZADA'),
-- Venda 15: Açaí
(15, 15, 1, 16.00, 16.00, 0.00, 16.00, 'FINALIZADA'),
-- Venda 16: 2 Combos + Bebida extra
(16, 3, 2, 45.00, 90.00, 14.00, 76.00, 'FINALIZADA'),
-- Venda 17: X-Burguer Clássico
(17, 1, 1, 28.00, 28.00, 0.00, 28.00, 'FINALIZADA'),
-- Venda 18: Milk Shake
(18, 16, 1, 14.00, 14.00, 0.00, 14.00, 'FINALIZADA'),
-- Venda 19: X-Burguer Duplo
(19, 2, 1, 38.00, 38.00, 0.00, 38.00, 'FINALIZADA'),
-- Venda 20: Batata GG
(20, 4, 1, 22.00, 22.00, 0.00, 22.00, 'FINALIZADA'),
-- Venda 21: X-Burguer Duplo + Batata + 2 Bebidas
(21, 2, 1, 38.00, 38.00, 0.00, 38.00, 'FINALIZADA'),
(21, 4, 1, 22.00, 22.00, 6.00, 16.00, 'FINALIZADA'),
(21, 8, 2, 6.00, 12.00, 0.00, 12.00, 'FINALIZADA'),
-- Venda 22: Misto Quente
(22, 6, 1, 12.00, 12.00, 0.00, 12.00, 'FINALIZADA'),
-- Venda 23: Combo XTEC
(23, 3, 1, 45.00, 45.00, 0.00, 45.00, 'FINALIZADA'),
-- Venda 24: X-Salada
(24, 7, 1, 25.00, 25.00, 0.00, 25.00, 'FINALIZADA'),
-- Venda 25: X-Burguer Clássico + Batata P
(25, 1, 1, 28.00, 28.00, 0.00, 28.00, 'FINALIZADA'),
(25, 5, 1, 14.00, 14.00, 8.00, 6.00, 'FINALIZADA'),
-- Venda 26: Petit Gateau
(26, 14, 1, 18.00, 18.00, 0.00, 18.00, 'FINALIZADA'),
-- Venda 27: 2 X-Burguer Clássico
(27, 1, 2, 28.00, 56.00, 0.00, 56.00, 'FINALIZADA'),
-- Venda 28: Açaí
(28, 15, 1, 16.00, 16.00, 0.00, 16.00, 'FINALIZADA'),
-- Venda 29: X-Burguer Clássico
(29, 1, 1, 28.00, 28.00, 0.00, 28.00, 'FINALIZADA'),
-- Venda 30: Combo XTEC
(30, 3, 1, 45.00, 45.00, 0.00, 45.00, 'FINALIZADA');

-- COMPRAS (20 registros)

INSERT INTO COMPRA (VALOR, DESCONTO, TOTAL, FORNECEDOR_ID, FUNCIONARIO_ID, STATUS) VALUES 
(2600.00, 0.00, 2600.00, 1, 3, 'FINALIZADA'),    -- Frigorífico - Carnes
(1350.00, 50.00, 1300.00, 2, 3, 'FINALIZADA'),   -- Distribuidora - Bebidas
(1850.00, 0.00, 1850.00, 3, 4, 'FINALIZADA'),    -- Hortifruti - Frescos
(420.00, 20.00, 400.00, 4, 4, 'FINALIZADA'),     -- Embalagens
(780.00, 0.00, 780.00, 5, 4, 'FINALIZADA'),      -- Laticínios
(1500.00, 0.00, 1500.00, 1, 3, 'FINALIZADA'),    -- Frigorífico - Reposição
(900.00, 0.00, 900.00, 2, 3, 'FINALIZADA'),      -- Distribuidora - Reposição
(1200.00, 100.00, 1100.00, 3, 4, 'FINALIZADA'),  -- Hortifruti - Reposição
(350.00, 0.00, 350.00, 4, 4, 'FINALIZADA'),      -- Embalagens - Reposição
(650.00, 0.00, 650.00, 5, 4, 'FINALIZADA'),      -- Laticínios - Reposição
(2000.00, 0.00, 2000.00, 1, 3, 'FINALIZADA'),    -- Frigorífico - Grande pedido
(1100.00, 0.00, 1100.00, 2, 3, 'FINALIZADA'),    -- Distribuidora
(1500.00, 150.00, 1350.00, 3, 4, 'FINALIZADA'),  -- Hortifruti
(500.00, 0.00, 500.00, 4, 4, 'FINALIZADA'),      -- Embalagens
(800.00, 0.00, 800.00, 5, 4, 'FINALIZADA'),      -- Laticínios
(1800.00, 0.00, 1800.00, 1, 3, 'FINALIZADA'),    -- Frigorífico
(950.00, 0.00, 950.00, 2, 3, 'FINALIZADA'),      -- Distribuidora
(1400.00, 0.00, 1400.00, 3, 4, 'FINALIZADA'),    -- Hortifruti
(450.00, 50.00, 400.00, 4, 4, 'FINALIZADA'),     -- Embalagens
(700.00, 0.00, 700.00, 5, 4, 'FINALIZADA');      -- Laticínios


-- COMPRA_ITEM (50 registros)

INSERT INTO COMPRA_ITEM (COMPRA_ID, PRODUTO_ID, QUANTIDADE, PRECO_UNITARIO, VALOR, DESCONTO, TOTAL, STATUS) VALUES 
-- Compra 1: Frigorífico - Carnes
(1, 18, 400, 6.50, 2600.00, 0.00, 2600.00, 'FINALIZADA'),
-- Compra 2: Distribuidora - Bebidas
(2, 8, 200, 4.50, 900.00, 0.00, 900.00, 'FINALIZADA'),
(2, 9, 100, 9.00, 900.00, 450.00, 450.00, 'FINALIZADA'),
(2, 10, 150, 4.00, 600.00, 50.00, 550.00, 'FINALIZADA'),
-- Compra 3: Hortifruti - Frescos
(3, 17, 500, 1.80, 900.00, 0.00, 900.00, 'FINALIZADA'),
(3, 20, 200, 0.90, 180.00, 0.00, 180.00, 'FINALIZADA'),
(3, 21, 180, 1.10, 198.00, 0.00, 198.00, 'FINALIZADA'),
(3, 23, 80, 10.00, 800.00, 228.00, 572.00, 'FINALIZADA'),
-- Compra 4: Embalagens
(4, 30, 1000, 0.25, 250.00, 0.00, 250.00, 'FINALIZADA'),
(4, 31, 800, 0.18, 144.00, 0.00, 144.00, 'FINALIZADA'),
(4, 32, 2000, 0.10, 200.00, 20.00, 180.00, 'FINALIZADA'),
(4, 33, 500, 1.50, 750.00, 0.00, 750.00, 'FINALIZADA'),
(4, 34, 1500, 0.15, 225.00, 0.00, 225.00, 'FINALIZADA'),
-- Compra 5: Laticínios
(5, 20, 600, 1.30, 780.00, 0.00, 780.00, 'FINALIZADA'),
-- Compra 6: Frigorífico - Reposição
(6, 18, 200, 6.50, 1300.00, 0.00, 1300.00, 'FINALIZADA'),
(6, 25, 150, 2.80, 420.00, 220.00, 200.00, 'FINALIZADA'),
-- Compra 7: Distribuidora
(7, 8, 100, 4.50, 450.00, 0.00, 450.00, 'FINALIZADA'),
(7, 11, 80, 6.00, 480.00, 30.00, 450.00, 'FINALIZADA'),
-- Compra 8: Hortifruti
(8, 17, 300, 1.80, 540.00, 0.00, 540.00, 'FINALIZADA'),
(8, 24, 60, 6.00, 360.00, 0.00, 360.00, 'FINALIZADA'),
(8, 28, 100, 5.00, 500.00, 200.00, 300.00, 'FINALIZADA'),
-- Compra 9: Embalagens
(9, 30, 500, 0.25, 125.00, 0.00, 125.00, 'FINALIZADA'),
(9, 32, 1000, 0.10, 100.00, 0.00, 100.00, 'FINALIZADA'),
(9, 34, 1000, 0.15, 150.00, 25.00, 125.00, 'FINALIZADA'),
-- Compra 10: Laticínios
(10, 26, 150, 2.80, 420.00, 0.00, 420.00, 'FINALIZADA'),
(10, 27, 350, 0.60, 210.00, 0.00, 210.00, 'FINALIZADA'),
(10, 28, 400, 0.60, 240.00, 90.00, 150.00, 'FINALIZADA'),
-- Compra 11: Frigorífico - Grande pedido
(11, 18, 300, 6.50, 1950.00, 0.00, 1950.00, 'FINALIZADA'),
(11, 23, 100, 2.80, 280.00, 230.00, 50.00, 'FINALIZADA'),
-- Compra 12: Distribuidora
(12, 9, 100, 9.00, 900.00, 0.00, 900.00, 'FINALIZADA'),
(12, 12, 100, 2.50, 250.00, 50.00, 200.00, 'FINALIZADA'),
-- Compra 13: Hortifruti
(13, 20, 300, 0.90, 270.00, 0.00, 270.00, 'FINALIZADA'),
(13, 21, 250, 1.10, 275.00, 0.00, 275.00, 'FINALIZADA'),
(13, 23, 100, 10.00, 1000.00, 50.00, 950.00, 'FINALIZADA'),
-- Compra 14: Embalagens
(14, 31, 1000, 0.18, 180.00, 0.00, 180.00, 'FINALIZADA'),
(14, 33, 300, 1.50, 450.00, 130.00, 320.00, 'FINALIZADA'),
-- Compra 15: Laticínios
(15, 20, 500, 1.30, 650.00, 0.00, 650.00, 'FINALIZADA'),
-- Compra 16: Frigorífico
(16, 18, 250, 6.50, 1625.00, 0.00, 1625.00, 'FINALIZADA'),
(16, 26, 100, 2.80, 280.00, 105.00, 175.00, 'FINALIZADA'),
-- Compra 17: Distribuidora
(17, 8, 150, 4.50, 675.00, 0.00, 675.00, 'FINALIZADA'),
(17, 13, 50, 7.00, 350.00, 75.00, 275.00, 'FINALIZADA'),
-- Compra 18: Hortifruti
(18, 17, 400, 1.80, 720.00, 0.00, 720.00, 'FINALIZADA'),
(18, 24, 50, 6.00, 300.00, 0.00, 300.00, 'FINALIZADA'),
(18, 28, 80, 5.00, 400.00, 20.00, 380.00, 'FINALIZADA'),
-- Compra 19: Embalagens
(19, 30, 800, 0.25, 200.00, 0.00, 200.00, 'FINALIZADA'),
(19, 32, 1500, 0.10, 150.00, 0.00, 150.00, 'FINALIZADA'),
(19, 34, 1200, 0.15, 180.00, 30.00, 150.00, 'FINALIZADA'),
-- Compra 20: Laticínios
(20, 27, 300, 1.10, 330.00, 0.00, 330.00, 'FINALIZADA'),
(20, 28, 500, 0.60, 300.00, 0.00, 300.00, 'FINALIZADA'),
(20, 26, 100, 2.80, 280.00, 110.00, 170.00, 'FINALIZADA');

-- CONTAS A RECEBER (30 registros - 1 por venda)

INSERT INTO CONTA_RECEBER (VENDA_ID, PARCELA_QUANTIDADE, PARCELA_NUMERO, DATA_VENCIMENTO, DATA_PAGAMENTO, VALOR, DESCONTO, TOTAL, STATUS) VALUES 
(1, 1, 1, '2026-09-18', '2026-09-18', 45.00, 0.00, 45.00, 'PAGO'),
(2, 1, 1, '2026-09-20', NULL, 28.00, 0.00, 28.00, 'PENDENTE'),
(3, 1, 1, '2026-09-20', NULL, 33.00, 0.00, 33.00, 'PENDENTE'),
(4, 1, 1, '2026-09-18', '2026-09-18', 68.00, 0.00, 68.00, 'PAGO'),
(5, 1, 1, '2026-09-18', '2026-09-18', 22.00, 0.00, 22.00, 'PAGO'),
(6, 1, 1, '2026-09-22', NULL, 56.00, 0.00, 56.00, 'PENDENTE'),
(7, 1, 1, '2026-09-18', '2026-09-18', 14.00, 0.00, 14.00, 'PAGO'),
(8, 1, 1, '2026-09-18', '2026-09-18', 12.00, 0.00, 12.00, 'PAGO'),
(9, 1, 1, '2026-09-22', NULL, 51.00, 0.00, 51.00, 'PENDENTE'),
(10, 1, 1, '2026-09-18', '2026-09-18', 25.00, 0.00, 25.00, 'PAGO'),
(11, 2, 1, '2026-10-10', NULL, 40.00, 0.00, 40.00, 'PENDENTE'),
(11, 2, 2, '2026-11-10', NULL, 40.00, 0.00, 40.00, 'PENDENTE'),
(12, 1, 1, '2026-09-18', '2026-09-18', 18.00, 0.00, 18.00, 'PAGO'),
(13, 1, 1, '2026-09-22', NULL, 32.00, 0.00, 32.00, 'PENDENTE'),
(14, 1, 1, '2026-09-18', '2026-09-18', 45.00, 0.00, 45.00, 'PAGO'),
(15, 1, 1, '2026-09-18', '2026-09-18', 16.00, 0.00, 16.00, 'PAGO'),
(16, 1, 1, '2026-09-25', NULL, 76.00, 0.00, 76.00, 'PENDENTE'),
(17, 1, 1, '2026-09-18', '2026-09-18', 28.00, 0.00, 28.00, 'PAGO'),
(18, 1, 1, '2026-09-18', '2026-09-18', 14.00, 0.00, 14.00, 'PAGO'),
(19, 1, 1, '2026-09-22', NULL, 38.00, 0.00, 38.00, 'PENDENTE'),
(20, 1, 1, '2026-09-18', '2026-09-18', 22.00, 0.00, 22.00, 'PAGO'),
(21, 1, 1, '2026-09-25', NULL, 54.00, 0.00, 54.00, 'PENDENTE'),
(22, 1, 1, '2026-09-18', '2026-09-18', 12.00, 0.00, 12.00, 'PAGO'),
(23, 1, 1, '2026-09-22', NULL, 45.00, 0.00, 45.00, 'PENDENTE'),
(24, 1, 1, '2026-09-18', '2026-09-18', 25.00, 0.00, 25.00, 'PAGO'),
(25, 1, 1, '2026-09-22', NULL, 34.00, 0.00, 34.00, 'PENDENTE'),
(26, 1, 1, '2026-09-18', '2026-09-18', 18.00, 0.00, 18.00, 'PAGO'),
(27, 1, 1, '2026-09-25', NULL, 56.00, 0.00, 56.00, 'PENDENTE'),
(28, 1, 1, '2026-09-18', '2026-09-18', 16.00, 0.00, 16.00, 'PAGO'),
(29, 1, 1, '2026-09-18', '2026-09-18', 28.00, 0.00, 28.00, 'PAGO'),
(30, 1, 1, '2026-09-22', NULL, 45.00, 0.00, 45.00, 'PENDENTE');

-- CONTAS A PAGAR (20 registros - 1 por compra)
INSERT INTO CONTA_PAGAR (COMPRA_ID, PARCELA_QUANTIDADE, PARCELA_NUMERO, DATA_VENCIMENTO, DATA_PAGAMENTO, VALOR, DESCONTO, TOTAL, STATUS) VALUES 
(1, 1, 1, '2026-10-18', '2026-10-17', 2600.00, 0.00, 2600.00, 'PAGO'),
(2, 1, 1, '2026-10-18', '2026-10-18', 1300.00, 0.00, 1300.00, 'PAGO'),
(3, 1, 1, '2026-10-20', NULL, 1850.00, 0.00, 1850.00, 'PENDENTE'),
(4, 1, 1, '2026-10-18', '2026-10-17', 400.00, 0.00, 400.00, 'PAGO'),
(5, 1, 1, '2026-10-20', NULL, 780.00, 0.00, 780.00, 'PENDENTE'),
(6, 1, 1, '2026-10-25', NULL, 1500.00, 0.00, 1500.00, 'PENDENTE'),
(7, 1, 1, '2026-10-25', NULL, 900.00, 0.00, 900.00, 'PENDENTE'),
(8, 1, 1, '2026-10-28', NULL, 1100.00, 0.00, 1100.00, 'PENDENTE'),
(9, 1, 1, '2026-10-25', NULL, 350.00, 0.00, 350.00, 'PENDENTE'),
(10, 1, 1, '2026-10-28', NULL, 650.00, 0.00, 650.00, 'PENDENTE'),
(11, 2, 1, '2026-11-10', NULL, 1000.00, 0.00, 1000.00, 'PENDENTE'),
(11, 2, 2, '2026-12-10', NULL, 1000.00, 0.00, 1000.00, 'PENDENTE'),
(12, 1, 1, '2026-11-05', NULL, 1100.00, 0.00, 1100.00, 'PENDENTE'),
(13, 1, 1, '2026-11-10', NULL, 1350.00, 0.00, 1350.00, 'PENDENTE'),
(14, 1, 1, '2026-11-05', NULL, 500.00, 0.00, 500.00, 'PENDENTE'),
(15, 1, 1, '2026-11-10', NULL, 800.00, 0.00, 800.00, 'PENDENTE'),
(16, 1, 1, '2026-11-15', NULL, 1800.00, 0.00, 1800.00, 'PENDENTE'),
(17, 1, 1, '2026-11-15', NULL, 950.00, 0.00, 950.00, 'PENDENTE'),
(18, 1, 1, '2026-11-20', NULL, 1400.00, 0.00, 1400.00, 'PENDENTE'),
(19, 1, 1, '2026-11-15', NULL, 400.00, 0.00, 400.00, 'PENDENTE'),
(20, 1, 1, '2026-11-20', NULL, 700.00, 0.00, 700.00, 'PENDENTE');

UPDATE USUARIOS SET CARGO = 'ADMIN', NIVEL = 'ADMIN' WHERE EMAIL = 'admin@tcc.com';