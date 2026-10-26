<?php
declare(strict_types=1);

require_once __DIR__ . '/_produto.php';

/**
 * Estoque: produtos (insumos e itens do cardápio) e suas movimentações.
 * Acesso: administrador, gerente e cozinheiro. Excluir produto: gerente.
 */
Api::iniciar(['listar', 'opcoes'], ['criar', 'atualizar', 'excluir', 'movimentar']);
Api::exigirEstoque();

/** Dias de antecedência do alerta de validade (Configurações do usuário). */
function diasValidade(): int
{
    return min(60, max(0, (int) Api::configuracao('dias_validade', '3', Api::usuarioId())));
}

/** Expressão SQL que classifica o produto (precedência: inativo > vencido > crítico > vencendo > ok). */
function situacaoSql(int $dias): string
{
    return "CASE
        WHEN p.STATUS='INATIVO' THEN 'INATIVO'
        WHEN p.DATA_VALIDADE IS NOT NULL AND p.DATA_VALIDADE < CURDATE() THEN 'VENCIDO'
        WHEN p.ESTOQUE <= p.ESTOQUE_MINIMO THEN 'CRITICO'
        WHEN p.DATA_VALIDADE IS NOT NULL AND p.DATA_VALIDADE <= DATE_ADD(CURDATE(), INTERVAL {$dias} DAY) THEN 'VENCENDO'
        ELSE 'OK'
    END";
}

function registrarMovimento(int $produtoId, string $tipo, int $quantidade, ?string $observacao): void
{
    Api::comando(
        'INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID, USUARIO_ID, TIPO, QUANTIDADE, OBSERVACAO) VALUES (?, ?, ?, ?, ?)',
        'iisis',
        [$produtoId, Api::usuarioId(), $tipo, $quantidade, $observacao],
    );
}

Api::executar(function (): void {
    switch (Api::$acao) {
        case 'listar':
            $dias = diasValidade();
            $situacao = situacaoSql($dias);
            $busca = Api::parametro('q');
            $filtroSituacao = Api::parametro('situacao', 'TODOS');
            $categoria = (int) ($_GET['categoria_id'] ?? 0);
            [$pagina, $porPagina, $deslocamento] = Api::paginacao(12);

            $filtros = [];
            $tipos = '';
            $valores = [];

            if ($busca !== '') {
                $filtros[] = '(p.NOME LIKE ? OR p.CODIGO_BARRAS LIKE ?)';
                $tipos .= 'ss';
                $valores[] = Api::like($busca);
                $valores[] = Api::like(Api::digitos($busca) ?: $busca);
            }
            if ($categoria > 0) {
                $filtros[] = 'p.CATEGORIA_ID=?';
                $tipos .= 'i';
                $valores[] = $categoria;
            }
            $onde = $filtros ? 'WHERE ' . implode(' AND ', $filtros) : '';

            $base = "SELECT p.ID AS id, p.NOME AS nome, p.DESCRICAO AS descricao, p.PRECO_UNITARIO AS preco,
                            p.ESTOQUE AS estoque, p.ESTOQUE_MINIMO AS estoque_minimo, p.UNIDADE_MEDIDA AS unidade,
                            p.DATA_VALIDADE AS validade, p.STATUS AS status, p.CATEGORIA_ID AS categoria_id,
                            c.NOME AS categoria, p.CODIGO_BARRAS AS codigo_barras, p.NCM AS ncm,
                            DATEDIFF(p.DATA_VALIDADE, CURDATE()) AS dias_para_vencer,
                            LEAST(100, ROUND(p.ESTOQUE / GREATEST(p.ESTOQUE_MINIMO * 2, 1) * 100)) AS nivel,
                            {$situacao} AS situacao
                     FROM PRODUTO p
                     JOIN PRODUTO_CATEGORIA c ON c.ID=p.CATEGORIA_ID
                     {$onde}";

            $contagem = ['todos' => 0, 'critico' => 0, 'vencendo' => 0, 'ok' => 0];
            foreach (Api::linhas("SELECT situacao, COUNT(*) AS total FROM ({$base}) t GROUP BY situacao", $tipos, $valores) as $linha) {
                $contagem['todos'] += (int) $linha['total'];
                match ($linha['situacao']) {
                    'CRITICO', 'VENCIDO' => $contagem['critico'] += (int) $linha['total'],
                    'VENCENDO' => $contagem['vencendo'] += (int) $linha['total'],
                    'OK' => $contagem['ok'] += (int) $linha['total'],
                    default => null,
                };
            }

            $filtroExterno = match ($filtroSituacao) {
                'CRITICO' => "WHERE situacao IN ('CRITICO','VENCIDO')",
                'VENCENDO' => "WHERE situacao='VENCENDO'",
                'OK' => "WHERE situacao='OK'",
                default => '',
            };

            $total = (int) Api::valor("SELECT COUNT(*) FROM ({$base}) t {$filtroExterno}", $tipos, $valores);
            $dados = Api::linhas(
                "SELECT * FROM ({$base}) t {$filtroExterno} ORDER BY nome LIMIT ? OFFSET ?",
                $tipos . 'ii',
                array_merge($valores, [$porPagina, $deslocamento]),
            );

            $resumo = Api::linha(
                "SELECT COUNT(*) AS itens,
                        COUNT(DISTINCT p.CATEGORIA_ID) AS categorias,
                        COALESCE(SUM(p.ESTOQUE <= p.ESTOQUE_MINIMO), 0) AS abaixo_minimo,
                        COALESCE(SUM(p.DATA_VALIDADE IS NOT NULL AND p.DATA_VALIDADE <= DATE_ADD(CURDATE(), INTERVAL {$dias} DAY)), 0) AS vencendo,
                        COALESCE(SUM(p.ESTOQUE * p.PRECO_UNITARIO), 0) AS valor_estoque
                 FROM PRODUTO p WHERE p.STATUS='ATIVO'",
            );

            $movimentos = Api::linhas(
                "SELECT m.ID AS id, m.TIPO AS tipo, m.QUANTIDADE AS quantidade, p.UNIDADE_MEDIDA AS unidade,
                        p.NOME AS produto, u.NOME AS usuario, DATE_FORMAT(m.DATA_MOVIMENTACAO, '%H:%i') AS hora,
                        m.OBSERVACAO AS observacao
                 FROM MOVIMENTACAO_ESTOQUE m
                 JOIN PRODUTO p ON p.ID=m.PRODUTO_ID
                 JOIN USUARIOS u ON u.ID=m.USUARIO_ID
                 WHERE DATE(m.DATA_MOVIMENTACAO)=CURDATE()
                 ORDER BY m.ID DESC
                 LIMIT 30",
            );

            Api::responder([
                'data' => $dados,
                'total' => $total,
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'paginas' => max(1, (int) ceil($total / $porPagina)),
                'contagem' => $contagem,
                'resumo' => [
                    'itens' => (int) $resumo['itens'],
                    'categorias' => (int) $resumo['categorias'],
                    'abaixo_minimo' => (int) $resumo['abaixo_minimo'],
                    'vencendo' => (int) $resumo['vencendo'],
                    'valor_estoque' => (float) $resumo['valor_estoque'],
                ],
                'dias_validade' => $dias,
                'movimentos' => $movimentos,
                'pode_excluir' => Api::ehGerente(),
            ]);

        case 'opcoes':
            Api::responder([
                'produtos' => Api::linhas(
                    "SELECT ID AS id, NOME AS nome, UNIDADE_MEDIDA AS unidade, ESTOQUE AS estoque FROM PRODUTO WHERE STATUS='ATIVO' ORDER BY NOME",
                ),
                'categorias' => Api::linhas(
                    "SELECT ID AS id, NOME AS nome, TIPO AS tipo FROM PRODUTO_CATEGORIA WHERE STATUS='ATIVO' ORDER BY NOME",
                ),
            ]);

        case 'criar':
            $campos = Produto::lerCampos(false, null);
            $inicial = Api::inteiro('estoque', false, 0, 9999999, 'estoque inicial') ?? 0;
            $campos['ESTOQUE'] = $inicial;

            $id = Api::transacao(static function () use ($campos, $inicial): int {
                $novo = Produto::inserir($campos);
                if ($inicial > 0) {
                    registrarMovimento($novo, 'ENTRADA', $inicial, 'Estoque inicial');
                }
                return $novo;
            });

            Api::responder(['ok' => true, 'id' => $id, 'message' => 'Item cadastrado no estoque.'], 201);

        case 'atualizar':
            $id = Api::inteiro('id');
            Produto::exigir($id);
            Produto::atualizar($id, Produto::lerCampos(false, $id));
            Api::responder(['ok' => true, 'message' => 'Item atualizado.']);

        case 'excluir':
            Api::exigirGerente();
            $id = Api::inteiro('id');

            if (Produto::excluirOuInativar($id)) {
                Api::responder([
                    'ok' => true,
                    'inativado' => true,
                    'message' => 'O item tem vendas ou movimentações no histórico, então foi inativado em vez de excluído.',
                ]);
            }

            Api::responder(['ok' => true, 'message' => 'Item excluído.']);

        case 'movimentar':
            $id = Api::inteiro('produto_id', true, 1, null, 'produto');
            $tipo = Api::opcao('tipo', ['ENTRADA', 'SAIDA'], null, 'tipo');
            $quantidade = Api::inteiro('quantidade', true, 1, 9999999, 'quantidade');
            $observacao = Api::texto('observacao', 255, false, 'observação');

            $novoEstoque = Api::transacao(static function () use ($id, $tipo, $quantidade, $observacao): int {
                $produto = Api::linha('SELECT ESTOQUE, STATUS FROM PRODUTO WHERE ID=? FOR UPDATE', 'i', [$id]);

                if ($produto === null) {
                    Api::falhar('Item não encontrado.', 404);
                }
                if ($produto['STATUS'] === 'INATIVO') {
                    Api::falhar('Item inativo não pode receber movimentações.', 409);
                }

                $atual = (int) $produto['ESTOQUE'];
                if ($tipo === 'SAIDA' && $quantidade > $atual) {
                    Api::falhar("Estoque insuficiente: há apenas {$atual} em estoque.", 409);
                }

                $novo = $tipo === 'ENTRADA' ? $atual + $quantidade : $atual - $quantidade;
                Api::comando('UPDATE PRODUTO SET ESTOQUE=? WHERE ID=?', 'ii', [$novo, $id]);
                registrarMovimento($id, $tipo, $quantidade, $observacao === '' ? null : $observacao);

                return $novo;
            });

            Api::responder([
                'ok' => true,
                'estoque' => $novoEstoque,
                'message' => $tipo === 'ENTRADA' ? 'Entrada registrada.' : 'Saída registrada.',
            ]);
    }
});
