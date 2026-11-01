-- CRUD
-- (convertido do MariaDB; funções fn_* retornam valores e sp_* retornam jsonb, uma linha por registro)

-- Texto sem acento e em minúsculas: ordena e compara como o MariaDB (collation unicode_ci).
DROP FUNCTION IF EXISTS fn_ord;
CREATE OR REPLACE FUNCTION fn_ord(p_texto text)
RETURNS text
LANGUAGE sql
IMMUTABLE
AS $fn$
    SELECT lower(translate(COALESCE(p_texto, ''),
        'ÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇÑáàâãäéèêëíìîïóòôõöúùûüçñ',
        'AAAAAEEEEIIIIOOOOOUUUUCNaaaaaeeeeiiiiooooouuuucn'))
$fn$;

-- Busca por trecho sem diferenciar maiúsculas nem acentos ("acai" encontra "Açaí").
DROP FUNCTION IF EXISTS fn_busca;
CREATE OR REPLACE FUNCTION fn_busca(p_texto text, p_padrao text)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $fn$
    SELECT fn_ord(p_texto) LIKE fn_ord(p_padrao)
$fn$;

-- Mantém só os números de um texto ("(11) 98765-4321" -> "11987654321").
DROP FUNCTION IF EXISTS fn_somente_digitos;
CREATE OR REPLACE FUNCTION fn_somente_digitos(p_texto varchar)
RETURNS varchar
LANGUAGE sql
IMMUTABLE
AS $fn$
    SELECT regexp_replace(COALESCE(p_texto, ''), '[^0-9]', '', 'g')
$fn$;

-- Valor em reais no formato brasileiro ("1.234,56").
DROP FUNCTION IF EXISTS fn_brl;
CREATE OR REPLACE FUNCTION fn_brl(p_valor numeric)
RETURNS varchar
LANGUAGE sql
IMMUTABLE
AS $fn$
    SELECT translate(to_char(COALESCE(p_valor, 0), 'FM999,999,999,990.00'), ',.', '.,')
$fn$;

-- 12345678000190 -> 12.345.678/0001-90 (qualquer outro tamanho volta sem formatar).
DROP FUNCTION IF EXISTS fn_formatar_cnpj;
CREATE OR REPLACE FUNCTION fn_formatar_cnpj(p_digitos varchar)
RETURNS varchar
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF p_digitos IS NULL OR CHAR_LENGTH(p_digitos) <> 14 THEN
        RETURN LEFT(COALESCE(p_digitos, ''), 18);
    END IF;

    RETURN CONCAT(
        SUBSTRING(p_digitos, 1, 2), '.', SUBSTRING(p_digitos, 3, 3), '.',
        SUBSTRING(p_digitos, 6, 3), '/', SUBSTRING(p_digitos, 9, 4), '-',
        SUBSTRING(p_digitos, 13, 2)
    );
END
$fn$;

-- Lê uma configuração (USUARIO_ID 0 = valor global da operação).
DROP FUNCTION IF EXISTS fn_config;
CREATE OR REPLACE FUNCTION fn_config(p_usuario integer, p_chave varchar, p_padrao varchar)
RETURNS varchar
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_valor varchar DEFAULT NULL;
BEGIN


    SELECT VALOR INTO v_valor
    FROM CONFIGURACAO
    WHERE USUARIO_ID = p_usuario AND CHAVE = p_chave
    LIMIT 1;

    RETURN COALESCE(v_valor, p_padrao);
END
$fn$;

-- Quantidade de pedidos (não cancelados) de um cliente.
DROP FUNCTION IF EXISTS fn_cliente_pedidos;
CREATE OR REPLACE FUNCTION fn_cliente_pedidos(p_cliente_id integer)
RETURNS integer
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN (SELECT COUNT(*) FROM VENDA WHERE CLIENTE_ID = p_cliente_id AND STATUS <> 'CANCELADA');
END
$fn$;

-- Total já pago pelo cliente (vendas finalizadas).
DROP FUNCTION IF EXISTS fn_cliente_total_gasto;
CREATE OR REPLACE FUNCTION fn_cliente_total_gasto(p_cliente_id integer)
RETURNS numeric(12, 2)
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN (SELECT COALESCE(SUM(TOTAL), 0) FROM VENDA WHERE CLIENTE_ID = p_cliente_id AND STATUS = 'FINALIZADA');
END
$fn$;

-- Em quantos registros (vendas, compras, movimentos, fornecedores) o produto aparece.
DROP FUNCTION IF EXISTS fn_produto_usos;
CREATE OR REPLACE FUNCTION fn_produto_usos(p_produto_id integer)
RETURNS integer
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN (SELECT COUNT(*) FROM VENDA_ITEM WHERE PRODUTO_ID = p_produto_id)
         + (SELECT COUNT(*) FROM COMPRA_ITEM WHERE PRODUTO_ID = p_produto_id)
         + (SELECT COUNT(*) FROM MOVIMENTACAO_ESTOQUE WHERE PRODUTO_ID = p_produto_id)
         + (SELECT COUNT(*) FROM FORNECEDOR_PRODUTO WHERE PRODUTO_ID = p_produto_id);
END
$fn$;

-- Situação do item no estoque. Precedência: INATIVO > VENCIDO > CRITICO > VENCENDO > OK.
DROP FUNCTION IF EXISTS fn_produto_situacao;
CREATE OR REPLACE FUNCTION fn_produto_situacao(p_status varchar, p_estoque integer, p_minimo integer, p_validade date, p_dias integer)
RETURNS varchar
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF p_status = 'INATIVO' THEN
        RETURN 'INATIVO';
    ELSIF p_validade IS NOT NULL AND p_validade < CURRENT_DATE THEN
        RETURN 'VENCIDO';
    ELSIF p_minimo > 0 AND p_estoque <= p_minimo THEN
        -- mínimo 0 = item sem controle de reposição (ex.: lanche feito na hora)
        RETURN 'CRITICO';
    ELSIF p_validade IS NOT NULL AND p_validade <= ((CURRENT_DATE + (p_dias) * INTERVAL '1 day'))::date THEN
        RETURN 'VENCENDO';
    END IF;

    RETURN 'OK';
END
$fn$;

-- ==========================================================================
-- 3. CLIENTES
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_estado_listar;
CREATE OR REPLACE FUNCTION sp_estado_listar()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT SIGLA AS sigla, NOME AS nome
    FROM ESTADO
    WHERE STATUS = 'ATIVO'
    ORDER BY fn_ord(NOME)) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_cliente_listar;
CREATE OR REPLACE FUNCTION sp_cliente_listar(p_busca varchar, p_status varchar, p_limite integer, p_deslocamento integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    v_digitos varchar DEFAULT fn_somente_digitos(p_busca);
    v_numeros varchar DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');
BEGIN




    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT c.ID AS id, c.NOME AS nome, c.DOCUMENTO AS documento, c.ENDERECO AS endereco,
           c.ENDERECO_NUMERO AS endereco_numero, c.TELEFONE AS telefone, c.CONTATO AS contato,
           c.STATUS AS status, ci.NOME AS cidade, es.SIGLA AS estado,
           fn_cliente_pedidos(c.ID) AS pedidos,
           fn_cliente_total_gasto(c.ID) AS total_gasto
    FROM CLIENTE c
    JOIN CIDADE ci ON ci.ID = c.CIDADE_ID
    JOIN ESTADO es ON es.ID = ci.ESTADO_ID
    WHERE (TRIM(COALESCE(p_busca, '')) = ''
           OR fn_busca(c.NOME, v_texto)
           OR (v_digitos <> '' AND (fn_busca(c.DOCUMENTO, v_numeros) OR fn_busca(c.TELEFONE, v_numeros))))
      AND (p_status NOT IN ('ATIVO', 'INATIVO') OR c.STATUS = p_status)
    ORDER BY fn_ord(c.NOME)
    LIMIT p_limite OFFSET p_deslocamento) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_cliente_contagem;
CREATE OR REPLACE FUNCTION sp_cliente_contagem(p_busca varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    v_digitos varchar DEFAULT fn_somente_digitos(p_busca);
    v_numeros varchar DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');
BEGIN




    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT COUNT(*) AS todos,
           COALESCE(SUM((c.STATUS = 'ATIVO')::int), 0) AS ativos,
           COALESCE(SUM((c.STATUS = 'INATIVO')::int), 0) AS inativos
    FROM CLIENTE c
    WHERE TRIM(COALESCE(p_busca, '')) = ''
       OR fn_busca(c.NOME, v_texto)
       OR (v_digitos <> '' AND (fn_busca(c.DOCUMENTO, v_numeros) OR fn_busca(c.TELEFONE, v_numeros)))) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_cliente_obter;
CREATE OR REPLACE FUNCTION sp_cliente_obter(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT c.ID AS id, c.NOME AS nome, c.DOCUMENTO AS documento, c.ENDERECO AS endereco,
           c.ENDERECO_NUMERO AS endereco_numero, c.TELEFONE AS telefone, c.CONTATO AS contato,
           c.STATUS AS status, ci.NOME AS cidade, es.SIGLA AS estado
    FROM CLIENTE c
    JOIN CIDADE ci ON ci.ID = c.CIDADE_ID
    JOIN ESTADO es ON es.ID = ci.ESTADO_ID
    WHERE c.ID = p_id) _t;
END
$fn$;

-- p_id = 0 cadastra; p_id > 0 atualiza. Devolve o id do cliente.
DROP FUNCTION IF EXISTS sp_cliente_salvar;
CREATE OR REPLACE FUNCTION sp_cliente_salvar(p_id integer, p_nome varchar, p_documento varchar, p_endereco varchar, p_numero varchar, p_telefone varchar, p_contato varchar, p_status varchar, p_uf varchar, p_cidade varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_documento varchar DEFAULT fn_somente_digitos(p_documento);
    v_telefone varchar DEFAULT fn_somente_digitos(p_telefone);
    v_estado_id integer DEFAULT NULL;
    v_cidade_id integer DEFAULT NULL;
    v_id integer DEFAULT COALESCE(p_id, 0);
BEGIN






    IF TRIM(COALESCE(p_nome, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe o nome do cliente.';
    END IF;
    IF CHAR_LENGTH(v_documento) NOT IN (11, 14) THEN
        RAISE EXCEPTION '%', 'Informe um CPF (11 números) ou CNPJ (14 números) válido.';
    END IF;
    IF CHAR_LENGTH(v_telefone) NOT IN (10, 11) THEN
        RAISE EXCEPTION '%', 'Informe o telefone com DDD (10 ou 11 números).';
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

    IF v_id > 0 AND NOT EXISTS (SELECT 1 FROM CLIENTE WHERE ID = v_id) THEN
        RAISE EXCEPTION '%', 'Cliente não encontrado.';
    END IF;
    IF EXISTS (SELECT 1 FROM CLIENTE WHERE DOCUMENTO = v_documento AND ID <> v_id) THEN
        RAISE EXCEPTION '%', 'Já existe um cliente com esse CPF/CNPJ.';
    END IF;

    -- A cidade é cadastrada na hora se ainda não existir.
    SELECT ID INTO v_cidade_id FROM CIDADE WHERE NOME = TRIM(p_cidade) AND ESTADO_ID = v_estado_id LIMIT 1;
    IF v_cidade_id IS NULL THEN
        INSERT INTO CIDADE (NOME, ESTADO_ID) VALUES (TRIM(p_cidade), v_estado_id);
        v_cidade_id := lastval();
    END IF;

    IF v_id = 0 THEN
        INSERT INTO CLIENTE (NOME, DOCUMENTO, ENDERECO, ENDERECO_NUMERO, TELEFONE, CONTATO, STATUS, CIDADE_ID)
        VALUES (TRIM(p_nome), v_documento, TRIM(p_endereco), TRIM(p_numero), v_telefone,
                NULLIF(TRIM(COALESCE(p_contato, '')), ''), p_status, v_cidade_id);
        v_id := lastval();
    ELSE
        UPDATE CLIENTE
        SET NOME = TRIM(p_nome), DOCUMENTO = v_documento, ENDERECO = TRIM(p_endereco),
            ENDERECO_NUMERO = TRIM(p_numero), TELEFONE = v_telefone,
            CONTATO = NULLIF(TRIM(COALESCE(p_contato, '')), ''), STATUS = p_status, CIDADE_ID = v_cidade_id
        WHERE ID = v_id;
    END IF;

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v_id AS id) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_cliente_status;
CREATE OR REPLACE FUNCTION sp_cliente_status(p_id integer, p_status varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        RAISE EXCEPTION '%', 'Situação inválida.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM CLIENTE WHERE ID = p_id) THEN
        RAISE EXCEPTION '%', 'Cliente não encontrado.';
    END IF;

    UPDATE CLIENTE SET STATUS = p_status WHERE ID = p_id;
END
$fn$;

-- Exclui; se o cliente tem pedidos, apenas inativa. Devolve EXCLUIDO ou INATIVADO.
DROP FUNCTION IF EXISTS sp_cliente_excluir;
CREATE OR REPLACE FUNCTION sp_cliente_excluir(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM CLIENTE WHERE ID = p_id) THEN
        RAISE EXCEPTION '%', 'Cliente não encontrado.';
    END IF;

    IF EXISTS (SELECT 1 FROM VENDA WHERE CLIENTE_ID = p_id) THEN
        UPDATE CLIENTE SET STATUS = 'INATIVO' WHERE ID = p_id;
        RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT 'INATIVADO' AS resultado) _t;
    ELSE
        DELETE FROM CLIENTE WHERE ID = p_id;
        RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT 'EXCLUIDO' AS resultado) _t;
    END IF;
END
$fn$;

-- p_tipo vazio devolve todas.
DROP FUNCTION IF EXISTS sp_categoria_listar;
CREATE OR REPLACE FUNCTION sp_categoria_listar(p_tipo varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT c.ID AS id, c.NOME AS nome, c.DESCRICAO AS descricao, c.STATUS AS status, c.TIPO AS tipo,
           (SELECT COUNT(*) FROM PRODUTO p WHERE p.CATEGORIA_ID = c.ID) AS itens
    FROM PRODUTO_CATEGORIA c
    WHERE p_tipo NOT IN ('CARDAPIO', 'INSUMO') OR c.TIPO = p_tipo
    ORDER BY fn_ord(c.NOME)) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_categoria_obter;
CREATE OR REPLACE FUNCTION sp_categoria_obter(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT c.ID AS id, c.NOME AS nome, c.DESCRICAO AS descricao, c.STATUS AS status, c.TIPO AS tipo
    FROM PRODUTO_CATEGORIA c
    WHERE c.ID = p_id) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_categoria_salvar;
CREATE OR REPLACE FUNCTION sp_categoria_salvar(p_id integer, p_nome varchar, p_descricao varchar, p_status varchar, p_tipo varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_id integer DEFAULT COALESCE(p_id, 0);
    v_nome varchar DEFAULT TRIM(COALESCE(p_nome, ''));
BEGIN



    IF v_nome = '' THEN
        RAISE EXCEPTION '%', 'Informe o nome da categoria.';
    END IF;
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        p_status := 'ATIVO';
    END IF;
    IF EXISTS (SELECT 1 FROM PRODUTO_CATEGORIA WHERE NOME = v_nome AND ID <> v_id) THEN
        RAISE EXCEPTION '%', 'Já existe uma categoria com esse nome.';
    END IF;

    IF v_id = 0 THEN
        IF p_tipo NOT IN ('CARDAPIO', 'INSUMO') THEN
            RAISE EXCEPTION '%', 'Tipo de categoria inválido.';
        END IF;

        INSERT INTO PRODUTO_CATEGORIA (NOME, DESCRICAO, STATUS, TIPO)
        VALUES (v_nome, COALESCE(NULLIF(TRIM(COALESCE(p_descricao, '')), ''), v_nome), p_status, p_tipo);
        v_id := lastval();
    ELSE
        IF NOT EXISTS (SELECT 1 FROM PRODUTO_CATEGORIA WHERE ID = v_id) THEN
            RAISE EXCEPTION '%', 'Categoria não encontrada.';
        END IF;

        UPDATE PRODUTO_CATEGORIA
        SET NOME = v_nome,
            DESCRICAO = COALESCE(NULLIF(TRIM(COALESCE(p_descricao, '')), ''), v_nome),
            STATUS = p_status
        WHERE ID = v_id;
    END IF;

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v_id AS id) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_categoria_excluir;
CREATE OR REPLACE FUNCTION sp_categoria_excluir(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM PRODUTO_CATEGORIA WHERE ID = p_id) THEN
        RAISE EXCEPTION '%', 'Categoria não encontrada.';
    END IF;
    IF EXISTS (SELECT 1 FROM PRODUTO WHERE CATEGORIA_ID = p_id) THEN
        RAISE EXCEPTION '%', 'A categoria tem itens. Mova ou remova os itens antes, ou apenas inative a categoria.';
    END IF;

    DELETE FROM PRODUTO_CATEGORIA WHERE ID = p_id;
END
$fn$;

-- ==========================================================================
-- 5. PRODUTOS: CARDÁPIO E ESTOQUE (mesma tabela PRODUTO)
--    p_modo = 'CARDAPIO' (só campos de venda) ou 'ESTOQUE' (todos os campos)
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_cardapio_listar;
CREATE OR REPLACE FUNCTION sp_cardapio_listar(p_busca varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
BEGIN


    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT p.ID AS id, p.NOME AS nome, p.DESCRICAO AS descricao, p.PRECO_UNITARIO AS preco,
           p.STATUS AS status, p.CATEGORIA_ID AS categoria_id, c.NOME AS categoria,
           p.CODIGO_BARRAS AS codigo_barras, p.CONTROLA_ESTOQUE AS controla_estoque, p.ESTOQUE AS estoque,
           fn_produto_custo(p.ID) AS custo, fn_produto_produziveis(p.ID) AS produziveis,
           (SELECT COUNT(*) FROM FICHA_TECNICA f WHERE f.PRODUTO_ID = p.ID) AS insumos
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE c.TIPO = 'CARDAPIO'
      AND (TRIM(COALESCE(p_busca, '')) = '' OR fn_busca(p.NOME, v_texto) OR fn_busca(p.DESCRICAO, v_texto))
    ORDER BY fn_ord(c.NOME), fn_ord(p.NOME)) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_produto_obter;
CREATE OR REPLACE FUNCTION sp_produto_obter(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT p.ID AS id, p.NOME AS nome, p.DESCRICAO AS descricao, p.PRECO_UNITARIO AS preco,
           p.ESTOQUE AS estoque, p.ESTOQUE_MINIMO AS estoque_minimo, p.UNIDADE_MEDIDA AS unidade,
           p.DATA_VALIDADE AS validade, p.STATUS AS status, p.CATEGORIA_ID AS categoria_id,
           c.NOME AS categoria, c.TIPO AS categoria_tipo, p.CODIGO_BARRAS AS codigo_barras, p.NCM AS ncm,
           p.CONTROLA_ESTOQUE AS controla_estoque
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE p.ID = p_id) _t;
END
$fn$;

-- p_id = 0 cadastra; p_id > 0 atualiza. Devolve o id do produto.
-- p_estoque_inicial só vale no cadastro pelo estoque (gera a entrada "Estoque inicial").
DROP FUNCTION IF EXISTS sp_produto_salvar;
CREATE OR REPLACE FUNCTION sp_produto_salvar(p_id integer, p_modo varchar, p_nome varchar, p_descricao varchar, p_preco numeric(10, 2), p_categoria_id integer, p_status varchar, p_codigo_barras varchar, p_ncm varchar, p_unidade varchar, p_estoque_minimo integer, p_validade date, p_estoque_inicial integer, p_usuario_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_id integer DEFAULT COALESCE(p_id, 0);
    v_nome varchar DEFAULT TRIM(COALESCE(p_nome, ''));
    v_codigo varchar DEFAULT NULLIF(fn_somente_digitos(p_codigo_barras), '');
    v_ncm varchar DEFAULT NULLIF(fn_somente_digitos(p_ncm), '');
    v_unidade varchar DEFAULT UPPER(TRIM(COALESCE(p_unidade, '')));
    v_tipo varchar DEFAULT NULL;
    v_inicial integer DEFAULT COALESCE(p_estoque_inicial, 0);
BEGIN








    /* (transação implícita) */

    IF p_modo NOT IN ('CARDAPIO', 'ESTOQUE') THEN
        RAISE EXCEPTION '%', 'Modo de cadastro inválido.';
    END IF;
    IF v_nome = '' THEN
        RAISE EXCEPTION '%', 'Informe o nome do item.';
    END IF;
    IF TRIM(COALESCE(p_descricao, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe a descrição do item.';
    END IF;
    IF p_status NOT IN ('ATIVO', 'INATIVO') THEN
        p_status := 'ATIVO';
    END IF;

    IF p_modo = 'CARDAPIO' AND COALESCE(p_preco, 0) < 0.01 THEN
        RAISE EXCEPTION '%', 'Informe um preço maior que zero.';
    END IF;
    IF p_modo = 'ESTOQUE' AND COALESCE(p_preco, 0) < 0 THEN
        RAISE EXCEPTION '%', 'O preço não pode ser negativo.';
    END IF;

    IF v_codigo IS NOT NULL AND CHAR_LENGTH(v_codigo) > 14 THEN
        RAISE EXCEPTION '%', 'O código de barras deve ter até 14 números.';
    END IF;
    IF v_ncm IS NOT NULL AND CHAR_LENGTH(v_ncm) <> 8 THEN
        RAISE EXCEPTION '%', 'O NCM deve ter 8 números.';
    END IF;

    SELECT TIPO INTO v_tipo FROM PRODUTO_CATEGORIA WHERE ID = p_categoria_id LIMIT 1;
    IF v_tipo IS NULL THEN
        RAISE EXCEPTION '%', 'Categoria não encontrada.';
    END IF;
    IF p_modo = 'CARDAPIO' AND v_tipo <> 'CARDAPIO' THEN
        RAISE EXCEPTION '%', 'Escolha uma categoria do cardápio.';
    END IF;

    IF v_id > 0 AND NOT EXISTS (SELECT 1 FROM PRODUTO WHERE ID = v_id) THEN
        RAISE EXCEPTION '%', 'Item não encontrado.';
    END IF;
    IF EXISTS (SELECT 1 FROM PRODUTO WHERE NOME = v_nome AND ID <> v_id) THEN
        RAISE EXCEPTION '%', 'Já existe um item com esse nome.';
    END IF;
    IF v_codigo IS NOT NULL AND EXISTS (SELECT 1 FROM PRODUTO WHERE CODIGO_BARRAS = v_codigo AND ID <> v_id) THEN
        RAISE EXCEPTION '%', 'Esse código de barras já está cadastrado em outro item.';
    END IF;

    IF p_modo = 'ESTOQUE' THEN
        IF v_unidade = '' THEN
            v_unidade := 'UN';
        END IF;
        IF CHAR_LENGTH(v_unidade) > 6 THEN
            RAISE EXCEPTION '%', 'A unidade de medida deve ter até 6 caracteres.';
        END IF;
        IF COALESCE(p_estoque_minimo, 0) < 0 OR v_inicial < 0 THEN
            RAISE EXCEPTION '%', 'As quantidades não podem ser negativas.';
        END IF;
    END IF;

    

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
        v_id := lastval();

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

    

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v_id AS id) _t;
END
$fn$;

-- Marca o item do cardápio como disponível (1) ou indisponível (0).
DROP FUNCTION IF EXISTS sp_cardapio_disponibilidade;
CREATE OR REPLACE FUNCTION sp_cardapio_disponibilidade(p_id integer, p_disponivel smallint)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM PRODUTO p JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
        WHERE p.ID = p_id AND c.TIPO = 'CARDAPIO'
    ) THEN
        RAISE EXCEPTION '%', 'Item do cardápio não encontrado.';
    END IF;

    UPDATE PRODUTO SET STATUS = (CASE WHEN p_disponivel = 1 THEN 'ATIVO' ELSE 'INATIVO' END) WHERE ID = p_id;
END
$fn$;

-- Exclui; se o item tem histórico (vendas, compras, movimentos) apenas inativa.
-- Devolve EXCLUIDO ou INATIVADO.
DROP FUNCTION IF EXISTS sp_produto_excluir;
CREATE OR REPLACE FUNCTION sp_produto_excluir(p_id integer, p_modo varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM PRODUTO WHERE ID = p_id) THEN
        RAISE EXCEPTION '%', 'Item não encontrado.';
    END IF;
    IF p_modo = 'CARDAPIO' AND NOT EXISTS (
        SELECT 1 FROM PRODUTO p JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
        WHERE p.ID = p_id AND c.TIPO = 'CARDAPIO'
    ) THEN
        RAISE EXCEPTION '%', 'Item do cardápio não encontrado.';
    END IF;

    IF fn_produto_usos(p_id) > 0 THEN
        UPDATE PRODUTO SET STATUS = 'INATIVO' WHERE ID = p_id;
        RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT 'INATIVADO' AS resultado) _t;
    ELSE
        DELETE FROM PRODUTO WHERE ID = p_id;
        RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT 'EXCLUIDO' AS resultado) _t;
    END IF;
END
$fn$;

-- p_situacao: TODOS | CRITICO (inclui vencidos) | VENCENDO | OK
-- p_categoria: 0 = todas | p_dias = antecedência do alerta de validade
DROP FUNCTION IF EXISTS sp_estoque_listar;
CREATE OR REPLACE FUNCTION sp_estoque_listar(p_busca varchar, p_situacao varchar, p_categoria integer, p_dias integer, p_limite integer, p_deslocamento integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    v_digitos varchar DEFAULT fn_somente_digitos(p_busca);
    v_numeros varchar DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');
BEGIN




    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT t.*
    FROM (
        SELECT p.ID AS id, p.NOME AS nome, p.DESCRICAO AS descricao, p.PRECO_UNITARIO AS preco,
               p.ESTOQUE AS estoque, p.ESTOQUE_MINIMO AS estoque_minimo, p.UNIDADE_MEDIDA AS unidade,
               p.DATA_VALIDADE AS validade, p.STATUS AS status, p.CATEGORIA_ID AS categoria_id,
               c.NOME AS categoria, p.CODIGO_BARRAS AS codigo_barras, p.NCM AS ncm,
               ((p.DATA_VALIDADE)::date - (CURRENT_DATE)::date) AS dias_para_vencer,
               LEAST(100, ROUND(p.ESTOQUE::numeric / GREATEST(p.ESTOQUE_MINIMO * 2, 1) * 100)) AS nivel,
               fn_produto_situacao(p.STATUS, p.ESTOQUE, p.ESTOQUE_MINIMO, p.DATA_VALIDADE, p_dias) AS situacao
        FROM PRODUTO p
        JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
        WHERE (TRIM(COALESCE(p_busca, '')) = ''
               OR fn_busca(p.NOME, v_texto)
               OR (v_digitos <> '' AND fn_busca(p.CODIGO_BARRAS, v_numeros)))
          AND (p_categoria = 0 OR p.CATEGORIA_ID = p_categoria)
    ) t
    WHERE p_situacao NOT IN ('CRITICO', 'VENCENDO', 'OK')
       OR (p_situacao = 'CRITICO' AND t.situacao IN ('CRITICO', 'VENCIDO'))
       OR (p_situacao = 'VENCENDO' AND t.situacao = 'VENCENDO')
       OR (p_situacao = 'OK' AND t.situacao = 'OK')
    ORDER BY fn_ord(t.nome)
    LIMIT p_limite OFFSET p_deslocamento) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_estoque_contagem;
CREATE OR REPLACE FUNCTION sp_estoque_contagem(p_busca varchar, p_categoria integer, p_dias integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_texto varchar DEFAULT CONCAT('%', TRIM(COALESCE(p_busca, '')), '%');
    v_digitos varchar DEFAULT fn_somente_digitos(p_busca);
    v_numeros varchar DEFAULT CONCAT('%', fn_somente_digitos(p_busca), '%');
BEGIN




    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT COUNT(*) AS todos,
           COALESCE(SUM((t.situacao IN ('CRITICO', 'VENCIDO'))::int), 0) AS critico,
           COALESCE(SUM((t.situacao = 'VENCENDO')::int), 0) AS vencendo,
           COALESCE(SUM((t.situacao = 'OK')::int), 0) AS ok
    FROM (
        SELECT fn_produto_situacao(p.STATUS, p.ESTOQUE, p.ESTOQUE_MINIMO, p.DATA_VALIDADE, p_dias) AS situacao
        FROM PRODUTO p
        WHERE (TRIM(COALESCE(p_busca, '')) = ''
               OR fn_busca(p.NOME, v_texto)
               OR (v_digitos <> '' AND fn_busca(p.CODIGO_BARRAS, v_numeros)))
          AND (p_categoria = 0 OR p.CATEGORIA_ID = p_categoria)
    ) t) _t;
END
$fn$;

-- Cartões do topo da tela de estoque (só itens ativos).
DROP FUNCTION IF EXISTS sp_estoque_resumo;
CREATE OR REPLACE FUNCTION sp_estoque_resumo(p_dias integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT COUNT(*) AS itens,
           COUNT(DISTINCT p.CATEGORIA_ID) AS categorias,
           COALESCE(SUM((p.ESTOQUE_MINIMO > 0 AND p.ESTOQUE <= p.ESTOQUE_MINIMO)::int), 0) AS abaixo_minimo,
           COALESCE(SUM((p.DATA_VALIDADE IS NOT NULL AND p.DATA_VALIDADE <= ((CURRENT_DATE + (p_dias) * INTERVAL '1 day'))::date)::int), 0) AS vencendo,
           COALESCE(SUM(p.ESTOQUE * p.PRECO_UNITARIO), 0) AS valor_estoque
    FROM PRODUTO p
    WHERE p.STATUS = 'ATIVO') _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_estoque_movimentos_hoje;
CREATE OR REPLACE FUNCTION sp_estoque_movimentos_hoje()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT m.ID AS id, m.TIPO AS tipo, m.QUANTIDADE AS quantidade, p.UNIDADE_MEDIDA AS unidade,
           p.NOME AS produto, u.NOME AS usuario, to_char(m.DATA_MOVIMENTACAO, 'HH24:MI') AS hora,
           m.OBSERVACAO AS observacao
    FROM MOVIMENTACAO_ESTOQUE m
    JOIN PRODUTO p ON p.ID = m.PRODUTO_ID
    JOIN USUARIOS u ON u.ID = m.USUARIO_ID
    WHERE (m.DATA_MOVIMENTACAO)::date = CURRENT_DATE
    ORDER BY m.ID DESC
    LIMIT 30) _t;
END
$fn$;

-- Itens ativos para a lista de "Registrar entrada/saída".
DROP FUNCTION IF EXISTS sp_estoque_produtos_ativos;
CREATE OR REPLACE FUNCTION sp_estoque_produtos_ativos()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT ID AS id, NOME AS nome, UNIDADE_MEDIDA AS unidade, ESTOQUE AS estoque
    FROM PRODUTO
    WHERE STATUS = 'ATIVO'
    ORDER BY fn_ord(NOME)) _t;
END
$fn$;

-- Entrada ou saída de estoque. Devolve o novo saldo.
DROP FUNCTION IF EXISTS sp_estoque_movimentar;
CREATE OR REPLACE FUNCTION sp_estoque_movimentar(p_produto_id integer, p_tipo varchar, p_quantidade integer, p_observacao varchar, p_usuario_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_atual integer DEFAULT NULL;
    v_status varchar DEFAULT NULL;
    v_novo integer DEFAULT 0;
BEGIN




    /* (transação implícita) */

    IF p_tipo NOT IN ('ENTRADA', 'SAIDA') THEN
        RAISE EXCEPTION '%', 'Tipo de movimentação inválido.';
    END IF;
    IF p_quantidade IS NULL OR p_quantidade < 1 OR p_quantidade > 9999999 THEN
        RAISE EXCEPTION '%', 'Informe uma quantidade válida.';
    END IF;

    

    SELECT ESTOQUE, STATUS INTO v_atual, v_status FROM PRODUTO WHERE ID = p_produto_id FOR UPDATE;

    IF v_atual IS NULL THEN
        RAISE EXCEPTION '%', 'Item não encontrado.';
    END IF;
    IF v_status = 'INATIVO' THEN
        RAISE EXCEPTION '%', 'Item inativo não pode receber movimentações.';
    END IF;
    IF p_tipo = 'SAIDA' AND p_quantidade > v_atual THEN
        RAISE EXCEPTION '%', 'Estoque insuficiente para essa saída.';
    END IF;

    v_novo := (CASE WHEN p_tipo = 'ENTRADA' THEN v_atual + p_quantidade ELSE v_atual - p_quantidade END);

    UPDATE PRODUTO SET ESTOQUE = v_novo WHERE ID = p_produto_id;
    INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, OBSERVACAO)
    VALUES (p_produto_id, p_usuario_id, p_tipo, p_quantidade, NULLIF(TRIM(COALESCE(p_observacao, '')), ''));

    

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v_novo AS estoque) _t;
END
$fn$;

-- Pedidos em andamento + entregues hoje.
DROP FUNCTION IF EXISTS sp_pedido_quadro;
CREATE OR REPLACE FUNCTION sp_pedido_quadro()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v.ID AS id, v.TIPO_PEDIDO AS tipo, v.MESA AS mesa, v.CLIENTE_ID AS cliente_id, cl.NOME AS cliente,
           v.ETAPA AS etapa, v.FORMA_PAGAMENTO AS forma_pagamento, v.DESCONTO AS desconto, v.VALOR AS valor,
           v.TOTAL AS total, v.OBSERVACAO AS observacao, u.NOME AS atendente,
           FLOOR(EXTRACT(EPOCH FROM ((localtimestamp(0)) - (v.DATA_CADASTRO))) / 60)::int AS minutos,
           FLOOR(EXTRACT(EPOCH FROM ((localtimestamp(0)) - (v.DATA_ETAPA))) / 60)::int AS minutos_etapa,
           to_char(v.DATA_CADASTRO, 'HH24:MI') AS hora,
           to_char(v.DATA_ETAPA, 'HH24:MI') AS hora_etapa
    FROM VENDA v
    LEFT JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    WHERE v.ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
       OR (v.ETAPA = 'ENTREGUE' AND (v.DATA_ETAPA)::date = CURRENT_DATE)
    ORDER BY v.DATA_CADASTRO, v.ID
    LIMIT 300) _t;
END
$fn$;

-- Itens dos mesmos pedidos do quadro.
DROP FUNCTION IF EXISTS sp_pedido_quadro_itens;
CREATE OR REPLACE FUNCTION sp_pedido_quadro_itens()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT vi.VENDA_ID AS venda_id, vi.PRODUTO_ID AS produto_id, p.NOME AS nome,
           vi.QUANTIDADE AS quantidade, vi.PRECO_UNITARIO AS preco, vi.OBSERVACAO AS observacao
    FROM VENDA_ITEM vi
    JOIN VENDA v ON v.ID = vi.VENDA_ID
    JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID
    WHERE v.ETAPA IN ('NOVO', 'PREPARO', 'PRONTO')
       OR (v.ETAPA = 'ENTREGUE' AND (v.DATA_ETAPA)::date = CURRENT_DATE)
    ORDER BY vi.VENDA_ID, fn_ord(p.NOME)) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_pedido_obter;
CREATE OR REPLACE FUNCTION sp_pedido_obter(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v.ID AS id, v.TIPO_PEDIDO AS tipo, v.MESA AS mesa, v.CLIENTE_ID AS cliente_id, cl.NOME AS cliente,
           v.ETAPA AS etapa, v.FORMA_PAGAMENTO AS forma_pagamento, v.DESCONTO AS desconto, v.VALOR AS valor,
           v.TOTAL AS total, v.OBSERVACAO AS observacao, u.NOME AS atendente
    FROM VENDA v
    LEFT JOIN CLIENTE cl ON cl.ID = v.CLIENTE_ID
    JOIN USUARIOS u ON u.ID = v.FUNCIONARIO_ID
    WHERE v.ID = p_id) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_pedido_itens;
CREATE OR REPLACE FUNCTION sp_pedido_itens(p_venda_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT vi.PRODUTO_ID AS produto_id, p.NOME AS nome, vi.QUANTIDADE AS quantidade,
           vi.PRECO_UNITARIO AS preco, vi.OBSERVACAO AS observacao
    FROM VENDA_ITEM vi
    JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID
    WHERE vi.VENDA_ID = p_venda_id
    ORDER BY fn_ord(p.NOME)) _t;
END
$fn$;

-- Itens do cardápio que podem ser vendidos agora.
DROP FUNCTION IF EXISTS sp_pedido_opcoes_produtos;
CREATE OR REPLACE FUNCTION sp_pedido_opcoes_produtos()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT p.ID AS id, p.NOME AS nome, p.PRECO_UNITARIO AS preco, c.NOME AS categoria
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE p.STATUS = 'ATIVO' AND c.TIPO = 'CARDAPIO'
    ORDER BY fn_ord(c.NOME), fn_ord(p.NOME)) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_pedido_opcoes_clientes;
CREATE OR REPLACE FUNCTION sp_pedido_opcoes_clientes()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT ID AS id, NOME AS nome, TELEFONE AS telefone
    FROM CLIENTE
    WHERE STATUS = 'ATIVO'
    ORDER BY fn_ord(NOME)
    LIMIT 1000) _t;
END
$fn$;

-- Cria o cabeçalho do pedido (sem itens). Rode dentro de uma transação do PHP
-- junto com sp_pedido_item_adicionar e sp_pedido_totais. Devolve o id.
DROP FUNCTION IF EXISTS sp_pedido_criar;
CREATE OR REPLACE FUNCTION sp_pedido_criar(p_tipo varchar, p_mesa varchar, p_cliente_id integer, p_forma varchar, p_observacao varchar, p_usuario_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_cliente integer DEFAULT NULLIF(p_cliente_id, 0);
BEGIN


    IF p_tipo NOT IN ('SALAO', 'BALCAO', 'DELIVERY') THEN
        RAISE EXCEPTION '%', 'Escolha o tipo do pedido.';
    END IF;
    IF p_tipo = 'DELIVERY' AND fn_config(0, 'aceita_delivery', '1') <> '1' THEN
        RAISE EXCEPTION '%', 'O delivery está pausado nas Configurações.';
    END IF;
    IF p_tipo = 'SALAO' AND TRIM(COALESCE(p_mesa, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe a mesa do pedido.';
    END IF;
    IF p_tipo = 'DELIVERY' AND v_cliente IS NULL THEN
        RAISE EXCEPTION '%', 'Escolha o cliente do delivery.';
    END IF;
    IF v_cliente IS NOT NULL AND NOT EXISTS (SELECT 1 FROM CLIENTE WHERE ID = v_cliente) THEN
        RAISE EXCEPTION '%', 'Cliente não encontrado.';
    END IF;
    IF NOT (p_forma = ANY(string_to_array(fn_config(0, 'pagamentos', 'PIX,CREDITO,DEBITO,DINHEIRO'), ','))) THEN
        RAISE EXCEPTION '%', 'Forma de pagamento não aceita pela loja.';
    END IF;

    INSERT INTO VENDA (VALOR, DESCONTO, TOTAL, CLIENTE_ID, FUNCIONARIO_ID, FORMA_PAGAMENTO, STATUS,
                       TIPO_PEDIDO, MESA, ETAPA, OBSERVACAO)
    VALUES (0, 0, 0, v_cliente, p_usuario_id, p_forma, 'EM_ABERTO',
            p_tipo, (CASE WHEN p_tipo = 'SALAO' THEN TRIM(p_mesa) ELSE NULL END), 'NOVO', NULLIF(TRIM(COALESCE(p_observacao, '')), ''));

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT lastval() AS id) _t;
END
$fn$;

-- Atualiza o cabeçalho de um pedido ainda aberto (os itens são regravados em seguida).
DROP FUNCTION IF EXISTS sp_pedido_atualizar;
CREATE OR REPLACE FUNCTION sp_pedido_atualizar(p_id integer, p_tipo varchar, p_mesa varchar, p_cliente_id integer, p_forma varchar, p_observacao varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_cliente integer DEFAULT NULLIF(p_cliente_id, 0);
    v_etapa varchar DEFAULT NULL;
    v_tipo_atual varchar DEFAULT NULL;
    v_forma_atual varchar DEFAULT NULL;
BEGIN





    SELECT ETAPA, TIPO_PEDIDO, FORMA_PAGAMENTO INTO v_etapa, v_tipo_atual, v_forma_atual
    FROM VENDA WHERE ID = p_id;

    IF v_etapa IS NULL THEN
        RAISE EXCEPTION '%', 'Pedido não encontrado.';
    END IF;
    IF v_etapa NOT IN ('NOVO', 'PREPARO', 'PRONTO') THEN
        RAISE EXCEPTION '%', 'Pedidos entregues ou cancelados não podem ser editados.';
    END IF;
    IF p_tipo NOT IN ('SALAO', 'BALCAO', 'DELIVERY') THEN
        RAISE EXCEPTION '%', 'Escolha o tipo do pedido.';
    END IF;
    IF p_tipo = 'DELIVERY' AND v_tipo_atual <> 'DELIVERY' AND fn_config(0, 'aceita_delivery', '1') <> '1' THEN
        RAISE EXCEPTION '%', 'O delivery está pausado nas Configurações.';
    END IF;
    IF p_tipo = 'SALAO' AND TRIM(COALESCE(p_mesa, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe a mesa do pedido.';
    END IF;
    IF p_tipo = 'DELIVERY' AND v_cliente IS NULL THEN
        RAISE EXCEPTION '%', 'Escolha o cliente do delivery.';
    END IF;
    IF v_cliente IS NOT NULL AND NOT EXISTS (SELECT 1 FROM CLIENTE WHERE ID = v_cliente) THEN
        RAISE EXCEPTION '%', 'Cliente não encontrado.';
    END IF;
    IF p_forma <> v_forma_atual
       AND NOT (p_forma = ANY(string_to_array(fn_config(0, 'pagamentos', 'PIX,CREDITO,DEBITO,DINHEIRO'), ','))) THEN
        RAISE EXCEPTION '%', 'Forma de pagamento não aceita pela loja.';
    END IF;

    UPDATE VENDA
    SET TIPO_PEDIDO = p_tipo, MESA = (CASE WHEN p_tipo = 'SALAO' THEN TRIM(p_mesa) ELSE NULL END), CLIENTE_ID = v_cliente,
        FORMA_PAGAMENTO = p_forma, OBSERVACAO = NULLIF(TRIM(COALESCE(p_observacao, '')), '')
    WHERE ID = p_id;
END
$fn$;

DROP FUNCTION IF EXISTS sp_pedido_item_limpar;
CREATE OR REPLACE FUNCTION sp_pedido_item_limpar(p_venda_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    DELETE FROM VENDA_ITEM WHERE VENDA_ID = p_venda_id;
END
$fn$;

-- Adiciona um item (com o preço atual do cardápio). Se o produto já está no pedido, soma a quantidade.
DROP FUNCTION IF EXISTS sp_pedido_item_adicionar;
CREATE OR REPLACE FUNCTION sp_pedido_item_adicionar(p_venda_id integer, p_produto_id integer, p_quantidade integer, p_observacao varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_preco numeric(10, 2) DEFAULT NULL;
BEGIN


    IF p_quantidade IS NULL OR p_quantidade < 1 OR p_quantidade > 999 THEN
        RAISE EXCEPTION '%', 'Informe uma quantidade válida (1 a 999) para cada item.';
    END IF;

    SELECT p.PRECO_UNITARIO INTO v_preco
    FROM PRODUTO p
    JOIN PRODUTO_CATEGORIA c ON c.ID = p.CATEGORIA_ID
    WHERE p.ID = p_produto_id AND p.STATUS = 'ATIVO' AND c.TIPO = 'CARDAPIO';

    IF v_preco IS NULL THEN
        RAISE EXCEPTION '%', 'Um dos itens não está mais disponível no cardápio.';
    END IF;

    INSERT INTO VENDA_ITEM (VENDA_ID, PRODUTO_ID, QUANTIDADE, PRECO_UNITARIO, VALOR, DESCONTO, TOTAL, STATUS, OBSERVACAO)
    VALUES (p_venda_id, p_produto_id, p_quantidade, v_preco, p_quantidade * v_preco, 0, p_quantidade * v_preco,
            'EM_ABERTO', NULLIF(TRIM(COALESCE(p_observacao, '')), ''))
    ON CONFLICT (venda_id, produto_id) DO UPDATE SET
        QUANTIDADE = LEAST(999, VENDA_ITEM.QUANTIDADE + p_quantidade),
        OBSERVACAO = NULLIF(TRIM(CONCAT_WS(' ', VENDA_ITEM.OBSERVACAO, NULLIF(TRIM(COALESCE(p_observacao, '')), ''))), '');

    UPDATE VENDA_ITEM
    SET VALOR = QUANTIDADE * PRECO_UNITARIO, TOTAL = QUANTIDADE * PRECO_UNITARIO
    WHERE VENDA_ID = p_venda_id AND PRODUTO_ID = p_produto_id;
END
$fn$;

-- Recalcula valor/total do pedido a partir dos itens e cria/atualiza a conta a receber.
DROP FUNCTION IF EXISTS sp_pedido_totais;
CREATE OR REPLACE FUNCTION sp_pedido_totais(p_venda_id integer, p_desconto numeric(10, 2))
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_valor numeric(10, 2) DEFAULT 0;
    v_itens integer DEFAULT 0;
    v_desconto numeric(10, 2) DEFAULT COALESCE(p_desconto, 0);
BEGIN




    SELECT COALESCE(SUM(TOTAL), 0), COUNT(*) INTO v_valor, v_itens
    FROM VENDA_ITEM WHERE VENDA_ID = p_venda_id;

    IF v_itens = 0 THEN
        RAISE EXCEPTION '%', 'Adicione ao menos um item ao pedido.';
    END IF;
    IF v_desconto < 0 OR v_desconto > v_valor THEN
        RAISE EXCEPTION '%', 'O desconto não pode ser maior que o valor dos itens.';
    END IF;

    UPDATE VENDA SET VALOR = v_valor, DESCONTO = v_desconto, TOTAL = v_valor - v_desconto WHERE ID = p_venda_id;

    IF EXISTS (SELECT 1 FROM CONTA_RECEBER WHERE VENDA_ID = p_venda_id) THEN
        UPDATE CONTA_RECEBER
        SET VALOR = v_valor, DESCONTO = v_desconto, TOTAL = v_valor - v_desconto
        WHERE VENDA_ID = p_venda_id AND STATUS IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO');
    ELSE
        INSERT INTO CONTA_RECEBER (VENDA_ID, DATA_VENCIMENTO, VALOR, DESCONTO, TOTAL, STATUS)
        VALUES (p_venda_id, CURRENT_DATE, v_valor, v_desconto, v_valor - v_desconto, 'PENDENTE');
    END IF;
END
$fn$;

-- Move o pedido uma etapa para frente ou para trás.
DROP FUNCTION IF EXISTS sp_pedido_etapa;
CREATE OR REPLACE FUNCTION sp_pedido_etapa(p_id integer, p_destino varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_atual varchar DEFAULT NULL;
    v_pos_atual integer DEFAULT 0;
    v_pos_destino integer DEFAULT 0;
BEGIN




    /* (transação implícita) */

    SELECT ETAPA INTO v_atual FROM VENDA WHERE ID = p_id;

    IF v_atual IS NULL THEN
        RAISE EXCEPTION '%', 'Pedido não encontrado.';
    END IF;

    v_pos_atual := COALESCE(array_position(string_to_array('NOVO,PREPARO,PRONTO,ENTREGUE', ','), v_atual), 0);
    v_pos_destino := COALESCE(array_position(string_to_array('NOVO,PREPARO,PRONTO,ENTREGUE', ','), p_destino), 0);

    IF v_pos_atual = 0 THEN
        RAISE EXCEPTION '%', 'Este pedido não pode mais mudar de etapa.';
    END IF;
    IF v_atual = 'ENTREGUE' THEN
        RAISE EXCEPTION '%', 'Pedidos entregues não voltam para a cozinha.';
    END IF;
    IF v_pos_destino = 0 OR ABS(v_pos_destino - v_pos_atual) <> 1 THEN
        RAISE EXCEPTION '%', 'Movimento de etapa inválido.';
    END IF;

    

    UPDATE VENDA SET ETAPA = p_destino, DATA_ETAPA = localtimestamp(0) WHERE ID = p_id;

    IF p_destino = 'ENTREGUE' THEN
        UPDATE VENDA SET STATUS = 'FINALIZADA' WHERE ID = p_id;
        UPDATE VENDA_ITEM SET STATUS = 'FINALIZADA' WHERE VENDA_ID = p_id;
        UPDATE CONTA_RECEBER
        SET STATUS = 'PAGO', DATA_PAGAMENTO = CURRENT_DATE
        WHERE VENDA_ID = p_id AND STATUS IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO');
        -- baixa automática dos insumos da ficha técnica (procedures_modulos.sql)
        PERFORM sp_estoque_baixa_pedido(p_id, -1);
    END IF;
END
$fn$;

-- Cancela o pedido (e a conta a receber). Pedido já entregue só o gerente estorna.
DROP FUNCTION IF EXISTS sp_pedido_cancelar;
CREATE OR REPLACE FUNCTION sp_pedido_cancelar(p_id integer, p_eh_gerente smallint)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_etapa varchar DEFAULT NULL;
BEGIN


    /* (transação implícita) */

    SELECT ETAPA INTO v_etapa FROM VENDA WHERE ID = p_id;

    IF v_etapa IS NULL THEN
        RAISE EXCEPTION '%', 'Pedido não encontrado.';
    END IF;
    IF v_etapa = 'CANCELADO' THEN
        RAISE EXCEPTION '%', 'Este pedido já está cancelado.';
    END IF;
    IF v_etapa = 'ENTREGUE' AND p_eh_gerente <> 1 THEN
        RAISE EXCEPTION '%', 'Somente administradores e gerentes podem estornar um pedido entregue.';
    END IF;

    

    UPDATE VENDA SET ETAPA = 'CANCELADO', STATUS = 'CANCELADA', DATA_ETAPA = localtimestamp(0) WHERE ID = p_id;
    UPDATE VENDA_ITEM SET STATUS = 'CANCELADA' WHERE VENDA_ID = p_id;
    UPDATE CONTA_RECEBER SET STATUS = 'CANCELADO', DATA_PAGAMENTO = NULL WHERE VENDA_ID = p_id;
    -- devolve ao estoque os insumos que já tinham recebido baixa
    PERFORM sp_estoque_baixa_pedido(p_id, 1);
END
$fn$;

-- Exclui o pedido por completo (não vale para entregues: cancele-os).
DROP FUNCTION IF EXISTS sp_pedido_excluir;
CREATE OR REPLACE FUNCTION sp_pedido_excluir(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_etapa varchar DEFAULT NULL;
BEGIN


    /* (transação implícita) */

    SELECT ETAPA INTO v_etapa FROM VENDA WHERE ID = p_id;

    IF v_etapa IS NULL THEN
        RAISE EXCEPTION '%', 'Pedido não encontrado.';
    END IF;
    IF v_etapa = 'ENTREGUE' THEN
        RAISE EXCEPTION '%', 'Pedidos entregues fazem parte do faturamento. Cancele em vez de excluir.';
    END IF;

    

    DELETE FROM CONTA_RECEBER WHERE VENDA_ID = p_id;
    DELETE FROM VENDA_ITEM WHERE VENDA_ID = p_id;
    DELETE FROM VENDA WHERE ID = p_id;
END
$fn$;

-- p_tipo: TODOS | ENTRADA | SAIDA   p_situacao: TODAS | PENDENTE | PAGO | ATRASADO | CANCELADO
DROP FUNCTION IF EXISTS sp_fluxo_listar;
CREATE OR REPLACE FUNCTION sp_fluxo_listar(p_de date, p_ate date, p_tipo varchar, p_situacao varchar, p_limite integer, p_deslocamento integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT f.tipo, f.origem, f.id, f.referencia, f.vencimento, f.pagamento, f.total, f.status,
           f.contraparte, f.categoria, f.situacao
    FROM vw_fluxo_caixa f
    WHERE f.vencimento BETWEEN p_de AND p_ate
      AND (p_tipo NOT IN ('ENTRADA', 'SAIDA') OR f.tipo = p_tipo)
      AND (p_situacao = 'TODAS' OR f.situacao = p_situacao)
    ORDER BY f.vencimento DESC, f.id DESC
    LIMIT p_limite OFFSET p_deslocamento) _t;
END
$fn$;

-- Quantos lançamentos há no período/situação (todos, entradas e saídas) para os filtros.
DROP FUNCTION IF EXISTS sp_fluxo_contagem;
CREATE OR REPLACE FUNCTION sp_fluxo_contagem(p_de date, p_ate date, p_situacao varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT COUNT(*) AS todos,
           COALESCE(SUM((f.tipo = 'ENTRADA')::int), 0) AS entradas,
           COALESCE(SUM((f.tipo = 'SAIDA')::int), 0) AS saidas
    FROM vw_fluxo_caixa f
    WHERE f.vencimento BETWEEN p_de AND p_ate
      AND (p_situacao = 'TODAS' OR f.situacao = p_situacao)) _t;
END
$fn$;

-- Cartões do topo: saldo, pagos, a receber/pagar e atrasados.
DROP FUNCTION IF EXISTS sp_fluxo_resumo;
CREATE OR REPLACE FUNCTION sp_fluxo_resumo(p_de date, p_ate date)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT
        COALESCE(SUM(CASE WHEN f.tipo = 'ENTRADA' AND f.status = 'PAGO' THEN f.total END), 0) AS entradas_pagas,
        COALESCE(SUM(CASE WHEN f.tipo = 'SAIDA' AND f.status = 'PAGO' THEN f.total END), 0) AS saidas_pagas,
        COALESCE(SUM(CASE WHEN f.tipo = 'ENTRADA' AND f.status IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO', 'AGENDADO') THEN f.total END), 0) AS a_receber,
        COALESCE(SUM(CASE WHEN f.tipo = 'SAIDA' AND f.status IN ('PENDENTE', 'ATRASADO', 'NEGOCIANDO', 'AGENDADO') THEN f.total END), 0) AS a_pagar,
        COALESCE(SUM(CASE WHEN f.situacao = 'ATRASADO' THEN f.total END), 0) AS atrasado_valor,
        COALESCE(SUM((f.situacao = 'ATRASADO')::int), 0) AS atrasado_qtd
    FROM vw_fluxo_caixa f
    WHERE f.vencimento BETWEEN p_de AND p_ate) _t;
END
$fn$;

-- Entradas e saídas das últimas 4 semanas (7 dias cada), da mais antiga para a atual.
DROP FUNCTION IF EXISTS sp_fluxo_semanas;
CREATE OR REPLACE FUNCTION sp_fluxo_semanas()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT 3 AS semana, to_char(((CURRENT_DATE - (27) * INTERVAL '1 day'))::date, 'DD/MM') AS rotulo,
           COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' THEN total END), 0) AS entrada,
           COALESCE(SUM(CASE WHEN tipo = 'SAIDA' THEN total END), 0) AS saida
    FROM vw_fluxo_caixa
    WHERE status <> 'CANCELADO' AND vencimento BETWEEN ((CURRENT_DATE - (27) * INTERVAL '1 day'))::date AND ((CURRENT_DATE - (21) * INTERVAL '1 day'))::date
    UNION ALL
    SELECT 2, to_char(((CURRENT_DATE - (20) * INTERVAL '1 day'))::date, 'DD/MM'),
           COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' THEN total END), 0),
           COALESCE(SUM(CASE WHEN tipo = 'SAIDA' THEN total END), 0)
    FROM vw_fluxo_caixa
    WHERE status <> 'CANCELADO' AND vencimento BETWEEN ((CURRENT_DATE - (20) * INTERVAL '1 day'))::date AND ((CURRENT_DATE - (14) * INTERVAL '1 day'))::date
    UNION ALL
    SELECT 1, to_char(((CURRENT_DATE - (13) * INTERVAL '1 day'))::date, 'DD/MM'),
           COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' THEN total END), 0),
           COALESCE(SUM(CASE WHEN tipo = 'SAIDA' THEN total END), 0)
    FROM vw_fluxo_caixa
    WHERE status <> 'CANCELADO' AND vencimento BETWEEN ((CURRENT_DATE - (13) * INTERVAL '1 day'))::date AND ((CURRENT_DATE - (7) * INTERVAL '1 day'))::date
    UNION ALL
    SELECT 0, to_char(((CURRENT_DATE - (6) * INTERVAL '1 day'))::date, 'DD/MM'),
           COALESCE(SUM(CASE WHEN tipo = 'ENTRADA' THEN total END), 0),
           COALESCE(SUM(CASE WHEN tipo = 'SAIDA' THEN total END), 0)
    FROM vw_fluxo_caixa
    WHERE status <> 'CANCELADO' AND vencimento BETWEEN ((CURRENT_DATE - (6) * INTERVAL '1 day'))::date AND CURRENT_DATE) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_fluxo_obter;
CREATE OR REPLACE FUNCTION sp_fluxo_obter(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT ID AS id, TIPO AS tipo, DESCRICAO AS descricao, CATEGORIA AS categoria, VALOR AS valor,
           DATA_MOVIMENTACAO AS data_movimentacao, STATUS AS status
    FROM MOVIMENTACAO_CAIXA
    WHERE ID = p_id) _t;
END
$fn$;

-- Lançamento manual: p_id = 0 cria; p_id > 0 edita (se não estiver cancelado).
DROP FUNCTION IF EXISTS sp_fluxo_salvar;
CREATE OR REPLACE FUNCTION sp_fluxo_salvar(p_id integer, p_tipo varchar, p_descricao varchar, p_categoria varchar, p_valor numeric(10, 2), p_data date, p_status varchar, p_usuario_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_id integer DEFAULT COALESCE(p_id, 0);
BEGIN


    IF p_tipo NOT IN ('ENTRADA', 'SAIDA') THEN
        RAISE EXCEPTION '%', 'Escolha se o lançamento é entrada ou saída.';
    END IF;
    IF TRIM(COALESCE(p_descricao, '')) = '' OR TRIM(COALESCE(p_categoria, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe a descrição e a categoria.';
    END IF;
    IF p_valor IS NULL OR p_valor <= 0 THEN
        RAISE EXCEPTION '%', 'Informe um valor maior que zero.';
    END IF;
    IF p_data IS NULL THEN
        RAISE EXCEPTION '%', 'Informe a data do lançamento.';
    END IF;
    IF p_status NOT IN ('PENDENTE', 'PAGO') THEN
        RAISE EXCEPTION '%', 'Situação inválida.';
    END IF;

    IF v_id = 0 THEN
        INSERT INTO MOVIMENTACAO_CAIXA (TIPO, DESCRICAO, CATEGORIA, VALOR, DATA_MOVIMENTACAO, DATA_PAGAMENTO, STATUS, USUARIO_ID)
        VALUES (p_tipo, TRIM(p_descricao), TRIM(p_categoria), p_valor, p_data,
                (CASE WHEN p_status = 'PAGO' THEN p_data ELSE NULL END), p_status, p_usuario_id);
        v_id := lastval();
    ELSE
        IF NOT EXISTS (SELECT 1 FROM MOVIMENTACAO_CAIXA WHERE ID = v_id AND STATUS <> 'CANCELADO') THEN
            RAISE EXCEPTION '%', 'Lançamento cancelado ou inexistente não pode ser editado.';
        END IF;

        UPDATE MOVIMENTACAO_CAIXA
        SET TIPO = p_tipo, DESCRICAO = TRIM(p_descricao), CATEGORIA = TRIM(p_categoria), VALOR = p_valor,
            DATA_MOVIMENTACAO = p_data, STATUS = p_status, DATA_PAGAMENTO = (CASE WHEN p_status = 'PAGO' THEN p_data ELSE NULL END)
        WHERE ID = v_id;
    END IF;

    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT v_id AS id) _t;
END
$fn$;

-- Marca como PAGO, volta para PENDENTE ou CANCELA um lançamento (venda, compra ou manual).
DROP FUNCTION IF EXISTS sp_fluxo_status;
CREATE OR REPLACE FUNCTION sp_fluxo_status(p_id integer, p_tipo varchar, p_origem varchar, p_status varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_atual varchar DEFAULT NULL;
BEGIN


    IF p_status NOT IN ('PAGO', 'PENDENTE', 'CANCELADO') OR p_tipo NOT IN ('ENTRADA', 'SAIDA') THEN
        RAISE EXCEPTION '%', 'Situação ou tipo inválido.';
    END IF;

    IF p_origem = 'MANUAL' THEN
        SELECT STATUS INTO v_atual FROM MOVIMENTACAO_CAIXA WHERE ID = p_id AND TIPO = p_tipo;
        IF v_atual IS NULL THEN
            RAISE EXCEPTION '%', 'Lançamento não encontrado.';
        END IF;
        IF v_atual = 'CANCELADO' AND p_status <> 'CANCELADO' THEN
            RAISE EXCEPTION '%', 'Um lançamento cancelado não pode ser reaberto.';
        END IF;

        UPDATE MOVIMENTACAO_CAIXA
        SET STATUS = p_status, DATA_PAGAMENTO = (CASE WHEN p_status = 'PAGO' THEN CURRENT_DATE ELSE NULL END)
        WHERE ID = p_id;
    ELSIF p_origem = 'VENDA' AND p_tipo = 'ENTRADA' THEN
        SELECT STATUS INTO v_atual FROM CONTA_RECEBER WHERE ID = p_id;
        IF v_atual IS NULL THEN
            RAISE EXCEPTION '%', 'Lançamento não encontrado.';
        END IF;
        IF v_atual = 'CANCELADO' AND p_status <> 'CANCELADO' THEN
            RAISE EXCEPTION '%', 'Um lançamento cancelado não pode ser reaberto.';
        END IF;

        UPDATE CONTA_RECEBER
        SET STATUS = p_status, DATA_PAGAMENTO = (CASE WHEN p_status = 'PAGO' THEN CURRENT_DATE ELSE NULL END)
        WHERE ID = p_id;
    ELSIF p_origem = 'COMPRA' AND p_tipo = 'SAIDA' THEN
        SELECT STATUS INTO v_atual FROM CONTA_PAGAR WHERE ID = p_id;
        IF v_atual IS NULL THEN
            RAISE EXCEPTION '%', 'Lançamento não encontrado.';
        END IF;
        IF v_atual = 'CANCELADO' AND p_status <> 'CANCELADO' THEN
            RAISE EXCEPTION '%', 'Um lançamento cancelado não pode ser reaberto.';
        END IF;

        UPDATE CONTA_PAGAR
        SET STATUS = p_status, DATA_PAGAMENTO = (CASE WHEN p_status = 'PAGO' THEN CURRENT_DATE ELSE NULL END)
        WHERE ID = p_id;
    ELSE
        RAISE EXCEPTION '%', 'Origem do lançamento inválida.';
    END IF;
END
$fn$;

DROP FUNCTION IF EXISTS sp_fluxo_excluir;
CREATE OR REPLACE FUNCTION sp_fluxo_excluir(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM MOVIMENTACAO_CAIXA WHERE ID = p_id) THEN
        RAISE EXCEPTION '%', 'Lançamento não encontrado.';
    END IF;

    DELETE FROM MOVIMENTACAO_CAIXA WHERE ID = p_id;
END
$fn$;

-- ==========================================================================
-- 9. EMPRESA, HORÁRIOS E EQUIPE
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_empresa_obter;
CREATE OR REPLACE FUNCTION sp_empresa_obter()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT NOME_FANTASIA AS nome_fantasia, RAZAO_SOCIAL AS razao_social, CNPJ AS cnpj, TELEFONE AS telefone,
           EMAIL AS email_contato, ENDERECO AS endereco, ENDERECO_NUMERO AS endereco_numero,
           ESTADO AS estado, CIDADE AS cidade
    FROM EMPRESA
    WHERE ID = 1) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_empresa_horarios;
CREATE OR REPLACE FUNCTION sp_empresa_horarios()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT DIA_SEMANA AS dia, ABERTO AS aberto,
           to_char(ABRE, 'HH24:MI') AS abre, to_char(FECHA, 'HH24:MI') AS fecha
    FROM EMPRESA_HORARIO
    ORDER BY DIA_SEMANA) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_empresa_salvar;
CREATE OR REPLACE FUNCTION sp_empresa_salvar(p_nome_fantasia varchar, p_razao_social varchar, p_cnpj varchar, p_telefone varchar, p_email varchar, p_endereco varchar, p_numero varchar, p_estado varchar, p_cidade varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_cnpj varchar DEFAULT fn_somente_digitos(p_cnpj);
    v_telefone varchar DEFAULT fn_somente_digitos(p_telefone);
BEGIN



    IF TRIM(COALESCE(p_nome_fantasia, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe o nome fantasia.';
    END IF;
    IF v_cnpj <> '' AND CHAR_LENGTH(v_cnpj) <> 14 THEN
        RAISE EXCEPTION '%', 'O CNPJ deve ter 14 números.';
    END IF;
    IF v_telefone <> '' AND CHAR_LENGTH(v_telefone) NOT IN (10, 11) THEN
        RAISE EXCEPTION '%', 'Informe o telefone com DDD (10 ou 11 números).';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM ESTADO WHERE SIGLA = p_estado) THEN
        RAISE EXCEPTION '%', 'Estado inválido.';
    END IF;

    INSERT INTO EMPRESA (ID, NOME_FANTASIA, RAZAO_SOCIAL, CNPJ, TELEFONE, EMAIL, ENDERECO, ENDERECO_NUMERO, ESTADO, CIDADE)
    VALUES (1, TRIM(p_nome_fantasia), TRIM(COALESCE(p_razao_social, '')), fn_formatar_cnpj(v_cnpj), v_telefone,
            TRIM(COALESCE(p_email, '')), TRIM(COALESCE(p_endereco, '')), TRIM(COALESCE(p_numero, '')),
            p_estado, TRIM(COALESCE(p_cidade, '')))
    ON CONFLICT (id) DO UPDATE SET
        NOME_FANTASIA = EXCLUDED.NOME_FANTASIA, RAZAO_SOCIAL = EXCLUDED.RAZAO_SOCIAL, CNPJ = EXCLUDED.CNPJ,
        TELEFONE = EXCLUDED.TELEFONE, EMAIL = EXCLUDED.EMAIL, ENDERECO = EXCLUDED.ENDERECO,
        ENDERECO_NUMERO = EXCLUDED.ENDERECO_NUMERO, ESTADO = EXCLUDED.ESTADO, CIDADE = EXCLUDED.CIDADE;
END
$fn$;

DROP FUNCTION IF EXISTS sp_empresa_horario_salvar;
CREATE OR REPLACE FUNCTION sp_empresa_horario_salvar(p_dia integer, p_aberto smallint, p_abre time, p_fecha time)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF p_dia < 1 OR p_dia > 7 THEN
        RAISE EXCEPTION '%', 'Dia da semana inválido.';
    END IF;
    IF p_aberto = 1 AND p_fecha <= p_abre THEN
        RAISE EXCEPTION '%', 'O horário de fechamento deve ser depois do de abertura.';
    END IF;

    INSERT INTO EMPRESA_HORARIO (DIA_SEMANA, ABERTO, ABRE, FECHA)
    VALUES (p_dia, (CASE WHEN p_aberto = 1 THEN 1 ELSE 0 END), p_abre, p_fecha)
    ON CONFLICT (dia_semana) DO UPDATE SET ABERTO = EXCLUDED.ABERTO, ABRE = EXCLUDED.ABRE, FECHA = EXCLUDED.FECHA;
END
$fn$;

DROP FUNCTION IF EXISTS sp_equipe_listar;
CREATE OR REPLACE FUNCTION sp_equipe_listar()
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT ID AS id, NOME AS nome, EMAIL AS email, NIVEL AS nivel, COALESCE(CARGO, 'FUNCIONARIO') AS cargo,
           ATIVO AS ativo, to_char(COALESCE(ULTIMO_ACESSO, ULTIMO_LOGIN), 'DD/MM HH24:MI') AS ultimo_login,
           COALESCE(COALESCE(ULTIMO_ACESSO, ULTIMO_LOGIN) >= localtimestamp(0) - (15) * INTERVAL '1 minute', false) AS online
    FROM USUARIOS
    ORDER BY (ATIVO = 'INATIVO'), fn_ord(NOME)) _t;
END
$fn$;

DROP FUNCTION IF EXISTS sp_equipe_obter;
CREATE OR REPLACE FUNCTION sp_equipe_obter(p_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT ID AS id, NOME AS nome, EMAIL AS email, NIVEL AS nivel, COALESCE(CARGO, 'FUNCIONARIO') AS cargo, ATIVO AS ativo
    FROM USUARIOS
    WHERE ID = p_id) _t;
END
$fn$;

-- p_ator_admin = 1 quando quem edita é administrador.
DROP FUNCTION IF EXISTS sp_equipe_atualizar;
CREATE OR REPLACE FUNCTION sp_equipe_atualizar(p_id integer, p_nome varchar, p_cargo varchar, p_nivel varchar, p_ativo varchar, p_ator_id integer, p_ator_admin smallint)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_nivel_atual varchar DEFAULT NULL;
BEGIN


    SELECT NIVEL INTO v_nivel_atual FROM USUARIOS WHERE ID = p_id;

    IF v_nivel_atual IS NULL THEN
        RAISE EXCEPTION '%', 'Funcionário não encontrado.';
    END IF;
    IF p_id = p_ator_id THEN
        RAISE EXCEPTION '%', 'Altere os seus próprios dados em Configurações.';
    END IF;
    IF v_nivel_atual = 'ADMIN' AND p_ator_admin <> 1 THEN
        RAISE EXCEPTION '%', 'Somente administradores podem alterar outro administrador.';
    END IF;
    IF TRIM(COALESCE(p_nome, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe o nome do funcionário.';
    END IF;
    IF p_cargo NOT IN ('Atendente / Caixa', 'Cozinheiro', 'Entregador', 'Gerente', 'FUNCIONARIO') THEN
        RAISE EXCEPTION '%', 'Cargo inválido.';
    END IF;
    IF p_ativo NOT IN ('ATIVO', 'INATIVO') OR p_nivel NOT IN ('ADMIN', 'FUNCIONARIO') THEN
        RAISE EXCEPTION '%', 'Situação ou nível inválido.';
    END IF;
    IF p_nivel <> v_nivel_atual AND p_ator_admin <> 1 THEN
        RAISE EXCEPTION '%', 'Somente administradores podem mudar o nível de acesso.';
    END IF;

    UPDATE USUARIOS SET NOME = TRIM(p_nome), CARGO = p_cargo, NIVEL = p_nivel, ATIVO = p_ativo WHERE ID = p_id;
END
$fn$;

-- Remove o funcionário; se ele tem histórico, apenas desativa o acesso. Devolve EXCLUIDO ou INATIVADO.
DROP FUNCTION IF EXISTS sp_equipe_remover;
CREATE OR REPLACE FUNCTION sp_equipe_remover(p_id integer, p_ator_id integer, p_ator_admin smallint)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
DECLARE
    v_nivel_atual varchar DEFAULT NULL;
BEGIN


    SELECT NIVEL INTO v_nivel_atual FROM USUARIOS WHERE ID = p_id;

    IF v_nivel_atual IS NULL THEN
        RAISE EXCEPTION '%', 'Funcionário não encontrado.';
    END IF;
    IF p_id = p_ator_id THEN
        RAISE EXCEPTION '%', 'Você não pode remover a sua própria conta.';
    END IF;
    IF v_nivel_atual = 'ADMIN' AND p_ator_admin <> 1 THEN
        RAISE EXCEPTION '%', 'Somente administradores podem remover outro administrador.';
    END IF;

    IF EXISTS (SELECT 1 FROM VENDA WHERE FUNCIONARIO_ID = p_id)
       OR EXISTS (SELECT 1 FROM COMPRA WHERE FUNCIONARIO_ID = p_id)
       OR EXISTS (SELECT 1 FROM MOVIMENTACAO_ESTOQUE WHERE USUARIO_ID = p_id)
       OR EXISTS (SELECT 1 FROM MOVIMENTACAO_CAIXA WHERE USUARIO_ID = p_id) THEN
        UPDATE USUARIOS SET ATIVO = 'INATIVO' WHERE ID = p_id;
        RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT 'INATIVADO' AS resultado) _t;
    ELSE
        DELETE FROM CONFIGURACAO WHERE USUARIO_ID = p_id;
        DELETE FROM USUARIOS WHERE ID = p_id;
        RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT 'EXCLUIDO' AS resultado) _t;
    END IF;
END
$fn$;

-- ==========================================================================
-- 10. CONFIGURAÇÕES (conta, notificações e operação)
-- ==========================================================================
DROP FUNCTION IF EXISTS sp_config_conta;
CREATE OR REPLACE FUNCTION sp_config_conta(p_usuario_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT NOME AS nome, EMAIL AS email, NIVEL AS nivel, COALESCE(CARGO, 'FUNCIONARIO') AS cargo
    FROM USUARIOS
    WHERE ID = p_usuario_id) _t;
END
$fn$;

-- Hash da senha gravada (o PHP confere com password_verify).
DROP FUNCTION IF EXISTS sp_config_senha_atual;
CREATE OR REPLACE FUNCTION sp_config_senha_atual(p_usuario_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT SENHA AS senha FROM USUARIOS WHERE ID = p_usuario_id) _t;
END
$fn$;

-- p_senha_hash vazio mantém a senha atual.
DROP FUNCTION IF EXISTS sp_config_conta_salvar;
CREATE OR REPLACE FUNCTION sp_config_conta_salvar(p_usuario_id integer, p_nome varchar, p_email varchar, p_senha_hash varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF TRIM(COALESCE(p_nome, '')) = '' THEN
        RAISE EXCEPTION '%', 'Informe o seu nome.';
    END IF;
    IF TRIM(COALESCE(p_email, '')) = '' OR p_email NOT ILIKE '%_@_%.__%' THEN
        RAISE EXCEPTION '%', 'Informe um e-mail válido.';
    END IF;
    IF EXISTS (SELECT 1 FROM USUARIOS WHERE EMAIL = TRIM(p_email) AND ID <> p_usuario_id) THEN
        RAISE EXCEPTION '%', 'Esse e-mail já é usado por outra conta.';
    END IF;

    UPDATE USUARIOS
    SET NOME = TRIM(p_nome), EMAIL = TRIM(p_email), SENHA = (CASE WHEN COALESCE(p_senha_hash, '') = '' THEN SENHA ELSE p_senha_hash END)
    WHERE ID = p_usuario_id;
END
$fn$;

-- Grava (ou atualiza) uma configuração. p_usuario_id = 0 vale para toda a operação.
DROP FUNCTION IF EXISTS sp_config_definir;
CREATE OR REPLACE FUNCTION sp_config_definir(p_usuario_id integer, p_chave varchar, p_valor varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    INSERT INTO CONFIGURACAO (USUARIO_ID, CHAVE, VALOR) VALUES (p_usuario_id, p_chave, p_valor)
    ON CONFLICT (usuario_id, chave) DO UPDATE SET VALOR = EXCLUDED.VALOR;
END
$fn$;

-- Configurações do usuário + globais, já com os padrões (uma linha por chave).
DROP FUNCTION IF EXISTS sp_config_listar;
CREATE OR REPLACE FUNCTION sp_config_listar(p_usuario_id integer)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    RETURN QUERY SELECT to_jsonb(_t) FROM (SELECT fn_config(p_usuario_id, 'notif_pedido', '1') AS notif_pedido,
           fn_config(p_usuario_id, 'notif_minimo', '1') AS notif_minimo,
           fn_config(p_usuario_id, 'notif_validade', '1') AS notif_validade,
           fn_config(p_usuario_id, 'notif_contas', '0') AS notif_contas,
           fn_config(p_usuario_id, 'dias_validade', '3') AS dias_validade,
           fn_config(0, 'aceita_delivery', '1') AS aceita_delivery,
           fn_config(0, 'tempo_preparo', '20') AS tempo_preparo,
           fn_config(0, 'pagamentos', 'PIX,CREDITO,DEBITO,DINHEIRO') AS pagamentos) _t;
END
$fn$;

-- Salva a operação da loja (somente gerência): delivery, tempo de preparo e formas de pagamento.
DROP FUNCTION IF EXISTS sp_config_operacao_salvar;
CREATE OR REPLACE FUNCTION sp_config_operacao_salvar(p_aceita_delivery smallint, p_tempo_preparo integer, p_pagamentos varchar)
RETURNS SETOF jsonb
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF p_tempo_preparo < 5 OR p_tempo_preparo > 240 THEN
        RAISE EXCEPTION '%', 'O tempo de preparo deve ficar entre 5 e 240 minutos.';
    END IF;
    IF TRIM(COALESCE(p_pagamentos, '')) = '' THEN
        RAISE EXCEPTION '%', 'Escolha ao menos uma forma de pagamento.';
    END IF;

    PERFORM sp_config_definir(0, 'aceita_delivery', (CASE WHEN p_aceita_delivery = 1 THEN '1' ELSE '0' END));
    PERFORM sp_config_definir(0, 'tempo_preparo', CAST(p_tempo_preparo AS text));
    PERFORM sp_config_definir(0, 'pagamentos', p_pagamentos);
END
$fn$;
