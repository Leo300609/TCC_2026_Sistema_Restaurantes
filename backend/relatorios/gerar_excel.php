<?php
declare(strict_types=1);

/** Relatório do período em Excel: abas Resumo, Vendas e Produtos (PhpSpreadsheet). */
require __DIR__ . '/_dados.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$formas = formas_pagamento();
$tipos = ['SALAO' => 'Salão', 'BALCAO' => 'Balcão', 'DELIVERY' => 'Delivery'];
$formatoMoeda = '"R$" #,##0.00';
$estiloTitulo = ['font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'B81F26']]];
$estiloCabecalho = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1C1F24']],
];

$planilha = new Spreadsheet();
$planilha->getProperties()->setCreator('Sistema XTEC')->setTitle('Relatório gerencial');

// Aba 1: resumo
$resumo = $planilha->getActiveSheet()->setTitle('Resumo');
$resumo->setCellValue('A1', ($rel['empresa']['nome_fantasia'] ?: 'XTEC') . ' · Relatório gerencial')->getStyle('A1')->applyFromArray($estiloTitulo);
$resumo->setCellValue('A2', 'Período: ' . data_br($rel['de']) . ' a ' . data_br($rel['ate']) . ' · gerado em ' . $rel['gerado_em'] . ' por ' . $rel['gerado_por']);
$linha = 4;
$resumo->fromArray(['Indicador', 'Valor'], null, "A{$linha}");
$resumo->getStyle("A{$linha}:B{$linha}")->applyFromArray($estiloCabecalho);
foreach (indicadores_relatorio($rel) as [$rotulo, $valor]) {
    $linha++;
    $resumo->setCellValue("A{$linha}", $rotulo)->setCellValue("B{$linha}", $valor);
}
$linha += 2;
$resumo->fromArray(['Forma de pagamento', 'Pedidos', 'Total'], null, "A{$linha}");
$resumo->getStyle("A{$linha}:C{$linha}")->applyFromArray($estiloCabecalho);
foreach ($rel['formas'] as $f) {
    $linha++;
    $resumo->fromArray([$formas[$f['forma']][0] ?? $f['forma'], (int) $f['pedidos'], (float) $f['total']], null, "A{$linha}");
    $resumo->getStyle("C{$linha}")->getNumberFormat()->setFormatCode($formatoMoeda);
}
$linha += 2;
$resumo->fromArray(['Canal de venda', 'Pedidos', 'Total'], null, "A{$linha}");
$resumo->getStyle("A{$linha}:C{$linha}")->applyFromArray($estiloCabecalho);
foreach ($rel['tipos'] as $t) {
    $linha++;
    $resumo->fromArray([$tipos[$t['tipo']] ?? $t['tipo'], (int) $t['pedidos'], (float) $t['total']], null, "A{$linha}");
    $resumo->getStyle("C{$linha}")->getNumberFormat()->setFormatCode($formatoMoeda);
}
foreach (['A', 'B', 'C'] as $col) {
    $resumo->getColumnDimension($col)->setAutoSize(true);
}

// Aba 2: todas as vendas do período
$vendas = $planilha->createSheet()->setTitle('Vendas');
$vendas->fromArray(['Pedido', 'Data e hora', 'Canal', 'Mesa', 'Cliente', 'Atendente', 'Pagamento', 'Itens', 'Valor', 'Desconto', 'Total', 'Situação'], null, 'A1');
$vendas->getStyle('A1:L1')->applyFromArray($estiloCabecalho);
$linha = 1;
foreach ($rel['vendas'] as $v) {
    $linha++;
    $vendas->fromArray([
        (int) $v['id'], $v['data_hora'], $tipos[$v['tipo']] ?? $v['tipo'], (string) $v['mesa'], $v['cliente'], $v['atendente'],
        $formas[$v['forma']][0] ?? $v['forma'], (string) $v['itens'], (float) $v['valor'], (float) $v['desconto'], (float) $v['total'],
        $v['status'] === 'FINALIZADA' ? 'Entregue' : ($v['status'] === 'CANCELADA' ? 'Cancelado' : 'Em aberto'),
    ], null, "A{$linha}");
}
if ($linha > 1) {
    $vendas->getStyle("I2:K{$linha}")->getNumberFormat()->setFormatCode($formatoMoeda);
    $vendas->setAutoFilter("A1:L{$linha}");
}
$vendas->freezePane('A2');
foreach (range('A', 'L') as $col) {
    $vendas->getColumnDimension($col)->setAutoSize($col !== 'H');
}
$vendas->getColumnDimension('H')->setWidth(48);

// Aba 3: produtos
$produtos = $planilha->createSheet()->setTitle('Produtos');
$produtos->fromArray(['Produto', 'Categoria', 'Quantidade', 'Receita', 'Custo estimado', 'Margem'], null, 'A1');
$produtos->getStyle('A1:F1')->applyFromArray($estiloCabecalho);
$linha = 1;
foreach ($rel['produtos'] as $p) {
    $linha++;
    $receita = (float) $p['receita'];
    $custo = (float) $p['custo'];
    $produtos->fromArray([$p['nome'], $p['categoria'], (int) $p['quantidade'], $receita, $custo, $receita > 0 && $custo > 0 ? ($receita - $custo) / $receita : null], null, "A{$linha}");
}
if ($linha > 1) {
    $produtos->getStyle("D2:E{$linha}")->getNumberFormat()->setFormatCode($formatoMoeda);
    $produtos->getStyle("F2:F{$linha}")->getNumberFormat()->setFormatCode('0.0%');
}
foreach (range('A', 'F') as $col) {
    $produtos->getColumnDimension($col)->setAutoSize(true);
}

$planilha->setActiveSheetIndex(0);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $rel['nome_arquivo'] . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($planilha))->save('php://output');
exit;
