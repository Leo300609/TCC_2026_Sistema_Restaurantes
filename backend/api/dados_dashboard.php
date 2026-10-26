<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
// Erros do PHP não podem sair na tela (quebrariam o JSON); vão para o log e para o campo "detalhe"
ini_set('display_errors', '0');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 1. Verificação de Segurança
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}

$cargo = $_SESSION['usuario_cargo'] ?? 'FUNCIONARIO';
$nivel = $_SESSION['usuario_nivel'] ?? 'FUNCIONARIO';
$verFinanceiro = ($nivel === 'ADMIN' || $cargo === 'Gerente');
$verEstoque = ($nivel === 'ADMIN' || $cargo === 'Gerente' || $cargo === 'Cozinheiro');

try {
    // 2. Conexão com o Banco (Sobe 1 nível para a pasta backend)
    require_once __DIR__ . '/../config/conexao.php';
    $mysql = new BancodeDados();
    $mysql->conecta();
    $con = $mysql->con;

    $response = [];

    // 3. DADOS FINANCEIROS (Apenas Admin/Gerente)
    if ($verFinanceiro) {
        $fat_hoje = mysqli_fetch_assoc(mysqli_query($con, "SELECT SUM(TOTAL) as total FROM VENDA WHERE STATUS='FINALIZADA' AND DATE(DATA_CADASTRO) = CURDATE()"))['total'] ?? 0;
        $desp_hoje = mysqli_fetch_assoc(mysqli_query($con, "SELECT SUM(TOTAL) as total FROM COMPRA WHERE STATUS='FINALIZADA' AND DATE(DATA_CADASTRO) = CURDATE()"))['total'] ?? 0;

        $fat_ontem = mysqli_fetch_assoc(mysqli_query($con, "SELECT SUM(TOTAL) as total FROM VENDA WHERE STATUS='FINALIZADA' AND DATE(DATA_CADASTRO) = CURDATE() - INTERVAL 1 DAY"))['total'] ?? 0;

        $total_mov = $fat_hoje + $desp_hoje;
        $perc_lucro = $total_mov > 0 ? round(($fat_hoje / $total_mov) * 100) : 50;
        $perc_despesa = 100 - $perc_lucro;

        $response['financeiro'] = [
            'faturamento_hoje' => number_format($fat_hoje, 2, ',', '.'),
            'faturamento_ontem' => (float)$fat_ontem,
            'faturamento_hoje_valor' => (float)$fat_hoje,
            'lucro' => $perc_lucro,
            'despesas' => $perc_despesa,
            'capital_inicial' => 50000.00,
            'capital_atual' => 50000.00 + ($fat_hoje - $desp_hoje)
        ];
    } else {
        $response['financeiro'] = null;
    }

    // 4. DADOS DE PEDIDOS (Todos veem)
    $pedidos_hoje = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as total FROM VENDA WHERE DATE(DATA_CADASTRO) = CURDATE()"))['total'] ?? 0;
    $response['pedidos_hoje'] = (int)$pedidos_hoje;

    // Pedidos dos últimos 7 dias (cancelados não entram)
    $por_dia = [];
    $result_semana = mysqli_query($con, "SELECT DATE(DATA_CADASTRO) AS dia, COUNT(*) AS total FROM VENDA WHERE DATA_CADASTRO >= CURDATE() - INTERVAL 6 DAY AND STATUS <> 'CANCELADA' GROUP BY DATE(DATA_CADASTRO)");
    while ($row = mysqli_fetch_assoc($result_semana)) {
        $por_dia[$row['dia']] = (int)$row['total'];
    }
    $dias_pt = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $response['pedidos_semana'] = [];
    $response['pedidos_semana_labels'] = [];
    for ($i = 6; $i >= 0; $i--) {
        $ts = strtotime("-$i day");
        $response['pedidos_semana'][] = $por_dia[date('Y-m-d', $ts)] ?? 0;
        $response['pedidos_semana_labels'][] = $dias_pt[(int)date('w', $ts)];
    }

    $response['pedidos_ultima_hora'] = (int)(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) AS total FROM VENDA WHERE DATA_CADASTRO >= NOW() - INTERVAL 1 HOUR AND STATUS <> 'CANCELADA'"))['total'] ?? 0);

    // Ticket médio, em entrega e últimos pedidos (dependem das colunas de migracao_crud.sql)
    $response['ticket_medio'] = null;
    $response['em_entrega'] = 0;
    $response['ultimos_pedidos'] = [];
    try {
        $ticket = mysqli_fetch_assoc(mysqli_query($con, "SELECT AVG(TOTAL) AS media FROM VENDA WHERE STATUS='FINALIZADA' AND DATE(DATA_CADASTRO) = CURDATE()"))['media'] ?? null;
        $response['ticket_medio'] = $ticket === null ? null : round((float)$ticket, 2);

        $response['em_entrega'] = (int)(mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) AS total FROM VENDA WHERE ETAPA='PRONTO' AND TIPO_PEDIDO='DELIVERY'"))['total'] ?? 0);

        $result_ultimos = mysqli_query($con, "SELECT v.ID, v.TOTAL, v.ETAPA, v.TIPO_PEDIDO, v.MESA, c.NOME AS CLIENTE,
                (SELECT GROUP_CONCAT(CONCAT(vi.QUANTIDADE, 'x ', p.NOME) ORDER BY p.NOME SEPARATOR ', ')
                   FROM VENDA_ITEM vi JOIN PRODUTO p ON p.ID = vi.PRODUTO_ID WHERE vi.VENDA_ID = v.ID) AS ITENS
            FROM VENDA v LEFT JOIN CLIENTE c ON c.ID = v.CLIENTE_ID
            ORDER BY v.ID DESC LIMIT 5");
        while ($row = mysqli_fetch_assoc($result_ultimos)) {
            $origem = $row['CLIENTE'] ?: ($row['TIPO_PEDIDO'] === 'SALAO' ? 'Mesa ' . $row['MESA'] : 'Balcão');
            $response['ultimos_pedidos'][] = [
                'id' => (int)$row['ID'],
                'cliente' => $origem,
                'itens' => $row['ITENS'] ?? '',
                'total' => (float)$row['TOTAL'],
                'etapa' => $row['ETAPA'],
            ];
        }
    } catch (Throwable $e) {
        // banco ainda sem a migração: o painel continua funcionando sem esses blocos
    }

    // 5. DADOS DE ESTOQUE (Admin/Gerente/Cozinheiro)
    if ($verEstoque) {
        $query_alertas = "SELECT NOME, DATA_VALIDADE, ESTOQUE, ESTOQUE_MINIMO FROM PRODUTO 
                          WHERE (DATA_VALIDADE <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND DATA_VALIDADE IS NOT NULL) 
                          OR ESTOQUE <= ESTOQUE_MINIMO";
        $result_alertas = mysqli_query($con, $query_alertas);

        $lista_alertas = [];
        $count = 0;
        while ($row = mysqli_fetch_assoc($result_alertas)) {
            $count++;
            $motivo = ($row['ESTOQUE'] <= $row['ESTOQUE_MINIMO']) ? 'Estoque baixo (' . $row['ESTOQUE'] . ')' : 'Vence em ' . date('d/m', strtotime($row['DATA_VALIDADE']));
            $lista_alertas[] = [
                'nome' => $row['NOME'],
                'motivo' => $motivo,
                'critico' => ($row['ESTOQUE'] <= $row['ESTOQUE_MINIMO'])
            ];
        }
        $response['estoque'] = ['total_alertas' => $count, 'lista' => $lista_alertas];
    } else {
        $response['estoque'] = null;
    }

    // 6. EQUIPE ONLINE (Últimos 15 minutos)
    $query_equipe = "SELECT NOME, CARGO FROM USUARIOS WHERE ULTIMO_LOGIN >= NOW() - INTERVAL 15 MINUTE AND ATIVO='ATIVO'";
    $result_equipe = mysqli_query($con, $query_equipe);
    $lista_equipe = [];
    while ($row = mysqli_fetch_assoc($result_equipe)) {
        $lista_equipe[] = ['nome' => $row['NOME'], 'cargo' => $row['CARGO']];
    }
    $response['equipe_online'] = $lista_equipe;

    $mysql->fechar();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
} catch (Throwable $e) {
    error_log('[tcc] dados_dashboard: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Não foi possível carregar os dados do painel.', 'detalhe' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
