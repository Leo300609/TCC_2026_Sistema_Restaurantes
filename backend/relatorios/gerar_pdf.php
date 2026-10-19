<?php
session_start();

// 1. Segurança: Apenas Admin ou Gerente pode gerar relatórios
if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_nivel'] !== 'ADMIN' && $_SESSION['usuario_cargo'] !== 'Gerente')) {
    die("<h1>Acesso Negado</h1><p>Você não tem permissão para gerar relatórios.</p><a href='../../frontend/dashboard.php'>Voltar</a>");
}

// 2. Carregar o Composer (Bibliotecas)
require_once '../../vendor/autoload.php';

// 3. Conexão com o Banco
require_once '../conexao.php';
$mysql = new BancodeDados();
$mysql->conecta();
$con = $mysql->con;

// 4. Buscar dados reais do banco
$faturamento = mysqli_fetch_assoc(mysqli_query($con, "SELECT SUM(TOTAL) as total FROM VENDA WHERE STATUS='FINALIZADA' AND DATE(DATA_CADASTRO) = CURDATE()"))['total'] ?? 0;
$total_pedidos = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as total FROM VENDA WHERE DATE(DATA_CADASTRO) = CURDATE()"))['total'] ?? 0;

// Buscar últimos 5 pedidos
$pedidos = mysqli_query($con, "SELECT V.ID, U.NOME as cliente, V.TOTAL, V.STATUS FROM VENDA V JOIN CLIENTE U ON V.CLIENTE_ID = U.ID ORDER BY V.DATA_CADASTRO DESC LIMIT 5");

$linhas_pedidos = "";
while ($p = mysqli_fetch_assoc($pedidos)) {
    $status_cor = ($p['STATUS'] == 'FINALIZADA') ? 'green' : 'orange';
    $linhas_pedidos .= "
        <tr>
            <td style='padding: 8px; border: 1px solid #ddd;'>#{$p['ID']}</td>
            <td style='padding: 8px; border: 1px solid #ddd;'>{$p['cliente']}</td>
            <td style='padding: 8px; border: 1px solid #ddd;'>R$ " . number_format($p['TOTAL'], 2, ',', '.') . "</td>
            <td style='padding: 8px; border: 1px solid #ddd; color: {$status_cor}; font-weight: bold;'>{$p['STATUS']}</td>
        </tr>
    ";
}

$mysql->fechar();

// 5. Montar o HTML do Relatório (Use CSS inline para PDFs)
$html = "
    <div style='font-family: Arial, sans-serif; color: #333;'>
        <h2 style='color: #b12e2f; text-align: center;'>XTEC - Relatório Gerencial</h2>
        <p style='text-align: center; color: #666;'>Gerado em: " . date('d/m/Y H:i') . " por " . $_SESSION['usuario_nome'] . "</p>
        <hr>
        
        <h3 style='color: #25080d;'>Resumo do Dia</h3>
        <table style='width: 100%; margin-bottom: 20px;'>
            <tr>
                <td style='padding: 10px; background: #f2e3c6; font-weight: bold; width: 50%;'>Faturamento Hoje</td>
                <td style='padding: 10px; background: #f2e3c6; font-weight: bold;'>R$ " . number_format($faturamento, 2, ',', '.') . "</td>
            </tr>
            <tr>
                <td style='padding: 10px; border: 1px solid #ddd;'>Total de Pedidos</td>
                <td style='padding: 10px; border: 1px solid #ddd;'>{$total_pedidos} pedidos</td>
            </tr>
        </table>

        <h3 style='color: #25080d;'>Últimos Pedidos Realizados</h3>
        <table style='width: 100%; border-collapse: collapse;'>
            <thead>
                <tr style='background: #3b0b12; color: white;'>
                    <th style='padding: 8px; border: 1px solid #ddd;'>ID</th>
                    <th style='padding: 8px; border: 1px solid #ddd;'>Cliente</th>
                    <th style='padding: 8px; border: 1px solid #ddd;'>Total</th>
                    <th style='padding: 8px; border: 1px solid #ddd;'>Status</th>
                </tr>
            </thead>
            <tbody>
                {$linhas_pedidos}
            </tbody>
        </table>
        
        <p style='margin-top: 30px; font-size: 12px; color: #999; text-align: center;'>Documento gerado automaticamente pelo Sistema XTEC.</p>
    </div>
";

// 6. Gerar o PDF (Usando DomPDF)
use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true); // Permite carregar imagens externas se precisar

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Força o download do arquivo
$dompdf->stream("relatorio_xtec_" . date('Y-m-d') . ".pdf", ["Attachment" => true]);
exit;
