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

$pedidos = mysqli_query($con, "SELECT V.ID, U.NOME as cliente, V.TOTAL, V.STATUS FROM VENDA V JOIN CLIENTE U ON V.CLIENTE_ID = U.ID ORDER BY V.DATA_CADASTRO DESC LIMIT 5");

$mysql->fechar();

// 4. Gerar o Documento Word
$phpWord = new \PhpOffice\PhpWord\PhpWord();

// Estilos base
$styleTitle = ['name' => 'Arial', 'size' => 16, 'bold' => true, 'color' => 'b12e2f'];
$styleText = ['name' => 'Arial', 'size' => 11];
$styleHeader = ['name' => 'Arial', 'size' => 11, 'bold' => true, 'bgColor' => '3b0b12', 'color' => 'FFFFFF'];

// Título e Cabeçalho
$section = $phpWord->addSection();
$section->addTitle('XTEC - Relatório Gerencial', 1);
$section->addText("Gerado em: " . date('d/m/Y H:i') . " por " . $_SESSION['usuario_nome'], $styleText);
$section->addTextBreak(1);

// Resumo
$section->addTitle('Resumo do Dia', 2);
$section->addText("Faturamento Hoje: R$ " . number_format($faturamento, 2, ',', '.'), $styleText);
$section->addText("Total de Pedidos: {$total_pedidos}", $styleText);
$section->addTextBreak(1);

// Tabela de Pedidos
$section->addTitle('Últimos Pedidos', 2);
$table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);

// Cabeçalho da tabela
$table->addRow();
$table->addCell(2000)->addText('ID', $styleHeader);
$table->addCell(4000)->addText('Cliente', $styleHeader);
$table->addCell(2000)->addText('Total', $styleHeader);
$table->addCell(2000)->addText('Status', $styleHeader);

// Linhas da tabela
while ($p = mysqli_fetch_assoc($pedidos)) {
    $table->addRow();
    $table->addCell(2000)->addText("#{$p['ID']}", $styleText);
    $table->addCell(4000)->addText($p['cliente'], $styleText);
    $table->addCell(2000)->addText("R$ " . number_format($p['TOTAL'], 2, ',', '.'), $styleText);
    $table->addCell(2000)->addText($p['STATUS'], $styleText);
}

// 5. Forçar Download
$tempFile = tempnam(sys_get_temp_dir(), 'word_') . '.docx';
$objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$objWriter->save($tempFile);

header('Content-Description: File Transfer');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="relatorio_xtec_' . date('Y-m-d') . '.docx"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($tempFile));
readfile($tempFile);
unlink($tempFile);
exit;
