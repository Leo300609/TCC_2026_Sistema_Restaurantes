<?php
session_start();
header('Content-Type: application/json');

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

// 2. Conexão com o Banco (Sobe 1 nível para a pasta backend)
require_once '../conexao.php'; // Se o seu arquivo for "conexão.php" com acento, mude aqui!
$mysql = new BancodeDados();
$mysql->conecta();
$con = $mysql->con;

$response = [];

// 3. DADOS FINANCEIROS (Apenas Admin/Gerente)
if ($verFinanceiro) {
    $fat_hoje = mysqli_fetch_assoc(mysqli_query($con, "SELECT SUM(TOTAL) as total FROM VENDA WHERE STATUS='FINALIZADA' AND DATE(DATA_CADASTRO) = CURDATE()"))['total'] ?? 0;
    $desp_hoje = mysqli_fetch_assoc(mysqli_query($con, "SELECT SUM(TOTAL) as total FROM COMPRA WHERE STATUS='FINALIZADA' AND DATE(DATA_CADASTRO) = CURDATE()"))['total'] ?? 0;

    $total_mov = $fat_hoje + $desp_hoje;
    $perc_lucro = $total_mov > 0 ? round(($fat_hoje / $total_mov) * 100) : 50;
    $perc_despesa = 100 - $perc_lucro;

    $response['financeiro'] = [
        'faturamento_hoje' => number_format($fat_hoje, 2, ',', '.'),
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
$response['pedidos_semana'] = [12, 19, 15, 25, 22, 30, (int)$pedidos_hoje];

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
echo json_encode($response);
