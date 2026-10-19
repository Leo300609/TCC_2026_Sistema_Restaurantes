<?php
session_start();

// 1. Segurança
if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_nivel'] !== 'ADMIN' && $_SESSION['usuario_cargo'] !== 'Gerente')) {
    die("Acesso negado.");
}

// 2. Bibliotecas e Conexão
require_once '../../vendor/autoload.php';
require_once '../conexao.php'; // Ajuste para 'conexão.php' se tiver acento

$mysql = new BancodeDados();
$mysql->conecta();
$con = $mysql->con;

// 3. Buscar Dados
$faturamento = mysqli_fetch_assoc(mysqli_query($con, "SELECT SUM(TOTAL) as total FROM VENDA WHERE STATUS='FINALIZADA' AND DATE(DATA_CADASTRO) = CURDATE()"))['total'] ?? 0;
$total_pedidos = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as total FROM VENDA WHERE DATE(DATA_CADASTRO) = CURDATE()"))['total'] ?? 0;

$pedidos = mysqli_query($con, "SELECT V.ID, U.NOME as cliente, V.TOTAL, V.STATUS, V.DATA_CADASTRO FROM VENDA V JOIN CLIENTE U ON V.CLIENTE_ID = U.ID ORDER BY V.DATA_CADASTRO DESC LIMIT 20");

$mysql->fechar();

// 4. Gerar a Planilha Excel
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// --- CORREÇÃO DOS ESTILOS (Sintaxe correta do PhpSpreadsheet) ---
$styleTitle = [
    'font' => [
        'bold' => true,
        'size' => 14,
        'color' => ['rgb' => 'b12e2f'] // <-- Corrigido: array com 'rgb'
    ]
];

$styleHeader = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'] // <-- Corrigido
    ],
    'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['rgb' => '3b0b12'] // <-- Corrigido
    ]
];

$styleText = [
    'font' => ['size' => 11]
];
// ---------------------------------------------------------------

// Cabeçalho e Resumo
$sheet->setCellValue('A1', 'XTEC - Relatório Gerencial')->getStyle('A1')->applyFromArray($styleTitle);
$sheet->setCellValue('A2', "Gerado em: " . date('d/m/Y H:i') . " por " . $_SESSION['usuario_nome']);
$sheet->setCellValue('A4', 'Faturamento Hoje:');
$sheet->setCellValue('B4', "R$ " . number_format($faturamento, 2, ',', '.'));
$sheet->setCellValue('A5', 'Total de Pedidos:');
$sheet->setCellValue('B5', $total_pedidos);

// Tabela de Pedidos (Começa na linha 7)
$sheet->setCellValue('A7', 'ID')->getStyle('A7')->applyFromArray($styleHeader);
$sheet->setCellValue('B7', 'Cliente')->getStyle('B7')->applyFromArray($styleHeader);
$sheet->setCellValue('C7', 'Data')->getStyle('C7')->applyFromArray($styleHeader);
$sheet->setCellValue('D7', 'Total')->getStyle('D7')->applyFromArray($styleHeader);
$sheet->setCellValue('E7', 'Status')->getStyle('E7')->applyFromArray($styleHeader);

$row = 8;
while ($p = mysqli_fetch_assoc($pedidos)) {
    $sheet->setCellValue('A' . $row, $p['ID']);
    $sheet->setCellValue('B' . $row, $p['cliente']);
    $sheet->setCellValue('C' . $row, date('d/m/Y H:i', strtotime($p['DATA_CADASTRO'])));
    $sheet->setCellValue('D' . $row, "R$ " . number_format($p['TOTAL'], 2, ',', '.'));
    $sheet->setCellValue('E' . $row, $p['STATUS']);
    $row++;
}

// Ajustar largura das colunas automaticamente
foreach (range('A', 'E') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// 5. Forçar Download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="relatorio_xtec_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save('php://output');
exit;
