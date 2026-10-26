<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

/**
 * Pedidos (comandas) = tabelas VENDA + VENDA_ITEM.
 *
 * Fluxo de etapas:  NOVO → PREPARO → PRONTO → ENTREGUE   (ou CANCELADO)
 *  - ao criar o pedido, nasce uma CONTA_RECEBER pendente (aparece no Fluxo de Caixa);
 *  - ao entregar, a venda é FINALIZADA e a conta é marcada como paga;
 *  - ao cancelar, a venda e a conta são canceladas (o histórico fica guardado).
 * Todos os usuários logados operam pedidos; excluir e estornar entregues: gerente.
 */
Api::iniciar(['quadro', 'opcoes'], ['criar', 'atualizar', 'etapa', 'cancelar', 'excluir']);

const ETAPAS = ['NOVO', 'PREPARO', 'PRONTO', 'ENTREGUE'];
const ETAPAS_ABERTAS = ['NOVO', 'PREPARO', 'PRONTO'];

function exigirPedido(int $id): array
{
    return Api::linha(
        'SELECT ID, ETAPA, TIPO_PEDIDO, FORMA_PAGAMENTO FROM VENDA WHERE ID=?',
        'i',
        [$id],
    ) ?? Api::falhar('Pedido não encontrado.', 404);
}

/** Lê o array "itens" do corpo e devolve [produto_id => dados], já com o preço atual do cardápio. */
function lerItens(): array
{
    $brutos = Api::$corpo['itens'] ?? null;

    if (!is_array($brutos) || $brutos === []) {
        Api::falhar('Adicione ao menos um item ao pedido.');
    }
    if (count($brutos) > 60) {
        Api::falhar('O pedido tem itens demais.');
    }

    $itens = [];
    foreach ($brutos as $bruto) {
        if (!is_array($bruto)) {
            Api::falhar('Item inválido no pedido.');
        }

        $produtoId = filter_var($bruto['produto_id'] ?? null, FILTER_VALIDATE_INT);
        $quantidade = filter_var($bruto['quantidade'] ?? null, FILTER_VALIDATE_INT);
        $observacao = trim((string) ($bruto['observacao'] ?? ''));

        if ($produtoId === false || $produtoId < 1 || $quantidade === false || $quantidade < 1 || $quantidade > 999) {
            Api::falhar('Informe uma quantidade válida (1 a 999) para cada item.');
        }
        if (Api::tamanho($observacao) > 255) {
            Api::falhar('A observação do item excede 255 caracteres.');
        }

        if (isset($itens[$produtoId])) {
            $itens[$produtoId]['quantidade'] += $quantidade;
            if ($observacao !== '') {
                $itens[$produtoId]['observacao'] = trim($itens[$produtoId]['observacao'] . ' ' . $observacao);
            }
        } else {
            $itens[$produtoId] = ['produto_id' => $produtoId, 'quantidade' => $quantidade, 'observacao' => $observacao];
        }
    }

    $ids = array_keys($itens);
    $produtos = Api::linhas(
        "SELECT p.ID, p.PRECO_UNITARIO
         FROM PRODUTO p
         JOIN PRODUTO_CATEGORIA c ON c.ID=p.CATEGORIA_ID
         WHERE p.ID IN (" . Api::marcadores(count($ids)) . ") AND p.STATUS='ATIVO' AND c.TIPO='CARDAPIO'",
        str_repeat('i', count($ids)),
        $ids,
    );

    if (count($produtos) !== count($ids)) {
        Api::falhar('Um dos itens não está mais disponível no cardápio. Atualize a página e confira o pedido.');
    }

    foreach ($produtos as $produto) {
        $itens[(int) $produto['ID']]['preco'] = (float) $produto['PRECO_UNITARIO'];
    }

    return $itens;
}

/** Lê e valida o cabeçalho do pedido (tipo, mesa, cliente, pagamento, desconto). */
function lerCabecalho(array $itens, ?array $atual): array
{
    $operacao = Api::operacao();
    $tipo = Api::opcao('tipo_pedido', ['SALAO', 'BALCAO', 'DELIVERY'], null, 'tipo do pedido');

    if ($tipo === 'DELIVERY' && !$operacao['aceita_delivery'] && ($atual['TIPO_PEDIDO'] ?? '') !== 'DELIVERY') {
        Api::falhar('O delivery está pausado nas Configurações.', 409);
    }

    $mesa = Api::texto('mesa', 20, $tipo === 'SALAO', 'mesa');
    $clienteId = Api::inteiro('cliente_id', $tipo === 'DELIVERY', 1, null, 'cliente');

    if ($clienteId !== null && Api::linha('SELECT ID FROM CLIENTE WHERE ID=?', 'i', [$clienteId]) === null) {
        Api::falhar('Cliente não encontrado.');
    }

    $permitidas = $operacao['pagamentos'];
    if ($atual !== null) {
        $permitidas[] = $atual['FORMA_PAGAMENTO'];
    }
    $forma = Api::opcao('forma_pagamento', array_values(array_unique($permitidas)), null, 'forma de pagamento');

    $valor = 0.0;
    foreach ($itens as $item) {
        $valor += $item['quantidade'] * $item['preco'];
    }
    $valor = round($valor, 2);
    $desconto = Api::decimal('desconto', 0.0, $valor, false, 'desconto') ?? 0.0;

    return [
        'tipo' => $tipo,
        'mesa' => $tipo === 'SALAO' ? $mesa : null,
        'cliente_id' => $clienteId,
        'forma' => $forma,
        'valor' => $valor,
        'desconto' => $desconto,
        'total' => round($valor - $desconto, 2),
        'observacao' => Api::texto('observacao', 255, false, 'observação') ?: null,
    ];
}

function gravarItens(int $vendaId, array $itens): void
{
    foreach ($itens as $item) {
        $valor = round($item['quantidade'] * $item['preco'], 2);
        Api::comando(
            "INSERT INTO VENDA_ITEM (VENDA_ID, PRODUTO_ID, QUANTIDADE, PRECO_UNITARIO, VALOR, DESCONTO, TOTAL, STATUS, OBSERVACAO)
             VALUES (?, ?, ?, ?, ?, 0, ?, 'EM_ABERTO', ?)",
            'iiiddds',
            [$vendaId, $item['produto_id'], $item['quantidade'], $item['preco'], $valor, $valor, $item['observacao'] ?: null],
        );
    }
}

Api::executar(function (): void {
    switch (Api::$acao) {
        case 'quadro':
            $pedidos = Api::linhas(
                "SELECT v.ID AS id, v.TIPO_PEDIDO AS tipo, v.MESA AS mesa, v.CLIENTE_ID AS cliente_id, cl.NOME AS cliente,
                        v.ETAPA AS etapa, v.FORMA_PAGAMENTO AS forma_pagamento, v.DESCONTO AS desconto, v.VALOR AS valor,
                        v.TOTAL AS total, v.OBSERVACAO AS observacao, u.NOME AS atendente,
                        TIMESTAMPDIFF(MINUTE, v.DATA_CADASTRO, NOW()) AS minutos,
                        TIMESTAMPDIFF(MINUTE, v.DATA_ETAPA, NOW()) AS minutos_etapa,
                        DATE_FORMAT(v.DATA_CADASTRO, '%H:%i') AS hora,
                        DATE_FORMAT(v.DATA_ETAPA, '%H:%i') AS hora_etapa
                 FROM VENDA v
                 LEFT JOIN CLIENTE cl ON cl.ID=v.CLIENTE_ID
                 JOIN USUARIOS u ON u.ID=v.FUNCIONARIO_ID
                 WHERE v.ETAPA IN ('NOVO','PREPARO','PRONTO')
                    OR (v.ETAPA='ENTREGUE' AND DATE(v.DATA_ETAPA)=CURDATE())
                 ORDER BY v.DATA_CADASTRO, v.ID
                 LIMIT 300",
            );

            $porPedido = [];
            if ($pedidos !== []) {
                $ids = array_map(static fn(array $p): int => (int) $p['id'], $pedidos);
                $linhas = Api::linhas(
                    'SELECT vi.VENDA_ID AS venda_id, vi.PRODUTO_ID AS produto_id, p.NOME AS nome,
                            vi.QUANTIDADE AS quantidade, vi.PRECO_UNITARIO AS preco, vi.OBSERVACAO AS observacao
                     FROM VENDA_ITEM vi
                     JOIN PRODUTO p ON p.ID=vi.PRODUTO_ID
                     WHERE vi.VENDA_ID IN (' . Api::marcadores(count($ids)) . ')
                     ORDER BY p.NOME',
                    str_repeat('i', count($ids)),
                    $ids,
                );

                foreach ($linhas as $linha) {
                    $porPedido[(int) $linha['venda_id']][] = [
                        'produto_id' => (int) $linha['produto_id'],
                        'nome' => $linha['nome'],
                        'quantidade' => (int) $linha['quantidade'],
                        'preco' => (float) $linha['preco'],
                        'observacao' => $linha['observacao'],
                    ];
                }
            }

            foreach ($pedidos as &$pedido) {
                $pedido['id'] = (int) $pedido['id'];
                $pedido['minutos'] = (int) $pedido['minutos'];
                $pedido['minutos_etapa'] = (int) $pedido['minutos_etapa'];
                $pedido['valor'] = (float) $pedido['valor'];
                $pedido['desconto'] = (float) $pedido['desconto'];
                $pedido['total'] = (float) $pedido['total'];
                $pedido['itens'] = $porPedido[$pedido['id']] ?? [];
            }
            unset($pedido);

            Api::responder([
                'data' => $pedidos,
                'operacao' => Api::operacao(),
                'pode_excluir' => Api::ehGerente(),
                'pode_estornar' => Api::ehGerente(),
            ]);

        case 'opcoes':
            Api::responder([
                'produtos' => Api::linhas(
                    "SELECT p.ID AS id, p.NOME AS nome, p.PRECO_UNITARIO AS preco, c.NOME AS categoria
                     FROM PRODUTO p
                     JOIN PRODUTO_CATEGORIA c ON c.ID=p.CATEGORIA_ID
                     WHERE p.STATUS='ATIVO' AND c.TIPO='CARDAPIO'
                     ORDER BY c.NOME, p.NOME",
                ),
                'clientes' => Api::linhas(
                    "SELECT ID AS id, NOME AS nome, TELEFONE AS telefone FROM CLIENTE WHERE STATUS='ATIVO' ORDER BY NOME LIMIT 1000",
                ),
                'operacao' => Api::operacao(),
            ]);

        case 'criar':
            $itens = lerItens();
            $c = lerCabecalho($itens, null);

            $id = Api::transacao(static function () use ($itens, $c): int {
                Api::comando(
                    "INSERT INTO VENDA (VALOR, DESCONTO, TOTAL, CLIENTE_ID, FUNCIONARIO_ID, FORMA_PAGAMENTO, STATUS, TIPO_PEDIDO, MESA, ETAPA, OBSERVACAO)
                     VALUES (?, ?, ?, ?, ?, ?, 'EM_ABERTO', ?, ?, 'NOVO', ?)",
                    'dddiissss',
                    [$c['valor'], $c['desconto'], $c['total'], $c['cliente_id'], Api::usuarioId(), $c['forma'], $c['tipo'], $c['mesa'], $c['observacao']],
                );
                $vendaId = Api::idInserido();
                gravarItens($vendaId, $itens);

                Api::comando(
                    "INSERT INTO CONTA_RECEBER (VENDA_ID, DATA_VENCIMENTO, VALOR, DESCONTO, TOTAL, STATUS) VALUES (?, CURDATE(), ?, ?, ?, 'PENDENTE')",
                    'iddd',
                    [$vendaId, $c['valor'], $c['desconto'], $c['total']],
                );

                return $vendaId;
            });

            Api::responder(['ok' => true, 'id' => $id, 'message' => "Pedido #{$id} criado."], 201);

        case 'atualizar':
            $id = Api::inteiro('id');
            $pedido = exigirPedido($id);

            if (!in_array($pedido['ETAPA'], ETAPAS_ABERTAS, true)) {
                Api::falhar('Pedidos entregues ou cancelados não podem ser editados.', 409);
            }

            $itens = lerItens();
            $c = lerCabecalho($itens, $pedido);

            Api::transacao(static function () use ($id, $itens, $c): void {
                Api::comando(
                    'UPDATE VENDA SET VALOR=?, DESCONTO=?, TOTAL=?, CLIENTE_ID=?, FORMA_PAGAMENTO=?, TIPO_PEDIDO=?, MESA=?, OBSERVACAO=? WHERE ID=?',
                    'dddissssi',
                    [$c['valor'], $c['desconto'], $c['total'], $c['cliente_id'], $c['forma'], $c['tipo'], $c['mesa'], $c['observacao'], $id],
                );
                Api::comando('DELETE FROM VENDA_ITEM WHERE VENDA_ID=?', 'i', [$id]);
                gravarItens($id, $itens);
                Api::comando(
                    "UPDATE CONTA_RECEBER SET VALOR=?, DESCONTO=?, TOTAL=? WHERE VENDA_ID=? AND STATUS IN ('PENDENTE','ATRASADO','NEGOCIANDO')",
                    'dddi',
                    [$c['valor'], $c['desconto'], $c['total'], $id],
                );
            });

            Api::responder(['ok' => true, 'message' => "Pedido #{$id} atualizado."]);

        case 'etapa':
            $id = Api::inteiro('id');
            $destino = Api::opcao('etapa', ETAPAS, null, 'etapa');
            $pedido = exigirPedido($id);

            $posicaoAtual = array_search($pedido['ETAPA'], ETAPAS, true);
            if ($posicaoAtual === false) {
                Api::falhar('Este pedido não pode mais mudar de etapa.', 409);
            }
            if (abs(array_search($destino, ETAPAS, true) - $posicaoAtual) !== 1) {
                Api::falhar('Movimento de etapa inválido.', 409);
            }
            if ($pedido['ETAPA'] === 'ENTREGUE') {
                Api::falhar('Pedidos entregues não voltam para a cozinha.', 409);
            }

            Api::transacao(static function () use ($id, $destino): void {
                Api::comando('UPDATE VENDA SET ETAPA=?, DATA_ETAPA=NOW() WHERE ID=?', 'si', [$destino, $id]);

                if ($destino === 'ENTREGUE') {
                    Api::comando("UPDATE VENDA SET STATUS='FINALIZADA' WHERE ID=?", 'i', [$id]);
                    Api::comando("UPDATE VENDA_ITEM SET STATUS='FINALIZADA' WHERE VENDA_ID=?", 'i', [$id]);
                    Api::comando(
                        "UPDATE CONTA_RECEBER SET STATUS='PAGO', DATA_PAGAMENTO=CURDATE() WHERE VENDA_ID=? AND STATUS IN ('PENDENTE','ATRASADO','NEGOCIANDO')",
                        'i',
                        [$id],
                    );
                }
            });

            Api::responder(['ok' => true, 'message' => "Pedido #{$id} atualizado."]);

        case 'cancelar':
            $id = Api::inteiro('id');
            $pedido = exigirPedido($id);

            if ($pedido['ETAPA'] === 'CANCELADO') {
                Api::falhar('Este pedido já está cancelado.', 409);
            }
            if ($pedido['ETAPA'] === 'ENTREGUE') {
                Api::exigirGerente();
            }

            Api::transacao(static function () use ($id): void {
                Api::comando("UPDATE VENDA SET ETAPA='CANCELADO', STATUS='CANCELADA', DATA_ETAPA=NOW() WHERE ID=?", 'i', [$id]);
                Api::comando("UPDATE VENDA_ITEM SET STATUS='CANCELADA' WHERE VENDA_ID=?", 'i', [$id]);
                Api::comando("UPDATE CONTA_RECEBER SET STATUS='CANCELADO', DATA_PAGAMENTO=NULL WHERE VENDA_ID=?", 'i', [$id]);
            });

            Api::responder(['ok' => true, 'message' => "Pedido #{$id} cancelado."]);

        case 'excluir':
            Api::exigirGerente();
            $id = Api::inteiro('id');
            $pedido = exigirPedido($id);

            if ($pedido['ETAPA'] === 'ENTREGUE') {
                Api::falhar('Pedidos entregues fazem parte do faturamento. Cancele o pedido em vez de excluir.', 409);
            }

            Api::transacao(static function () use ($id): void {
                Api::comando('DELETE FROM CONTA_RECEBER WHERE VENDA_ID=?', 'i', [$id]);
                Api::comando('DELETE FROM VENDA_ITEM WHERE VENDA_ID=?', 'i', [$id]);
                Api::comando('DELETE FROM VENDA WHERE ID=?', 'i', [$id]);
            });

            Api::responder(['ok' => true, 'message' => "Pedido #{$id} excluído."]);
    }
});
