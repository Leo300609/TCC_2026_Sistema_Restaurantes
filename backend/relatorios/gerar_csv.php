<?php
declare(strict_types=1);

/** Todas as vendas do período em CSV (separador ";" e BOM para abrir direto no Excel). */
require __DIR__ . '/_dados.php';

$formas = formas_pagamento();
$tipos = ['SALAO' => 'Salão', 'BALCAO' => 'Balcão', 'DELIVERY' => 'Delivery'];
$decimal = static fn($v) => number_format((float) $v, 2, ',', '');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="vendas_' . $rel['de'] . '_a_' . $rel['ate'] . '.csv"');

$saida = fopen('php://output', 'w');
fwrite($saida, "\xEF\xBB\xBF");
fputcsv($saida, ['Pedido', 'Data e hora', 'Canal', 'Mesa', 'Cliente', 'Atendente', 'Pagamento', 'Itens', 'Valor', 'Desconto', 'Total', 'Situação'], ';');
foreach ($rel['vendas'] as $v) {
    fputcsv($saida, [
        $v['id'], $v['data_hora'], $tipos[$v['tipo']] ?? $v['tipo'], $v['mesa'], $v['cliente'], $v['atendente'],
        $formas[$v['forma']][0] ?? $v['forma'], $v['itens'], $decimal($v['valor']), $decimal($v['desconto']), $decimal($v['total']),
        $v['status'] === 'FINALIZADA' ? 'Entregue' : ($v['status'] === 'CANCELADA' ? 'Cancelado' : 'Em aberto'),
    ], ';');
}
fclose($saida);
exit;
