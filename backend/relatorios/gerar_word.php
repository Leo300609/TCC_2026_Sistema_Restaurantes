<?php
declare(strict_types=1);

/** Relatório gerencial do período em Word (PhpWord). */
require __DIR__ . '/_dados.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

$formas = formas_pagamento();
$tipos = ['SALAO' => 'Salão', 'BALCAO' => 'Balcão', 'DELIVERY' => 'Delivery'];

$word = new PhpWord();
$word->setDefaultFontName('Calibri');
$word->setDefaultFontSize(10.5);
$word->addTitleStyle(1, ['size' => 18, 'bold' => true, 'color' => 'B81F26']);
$word->addTitleStyle(2, ['size' => 13, 'bold' => true, 'color' => '1C1F24'], ['spaceBefore' => 240, 'spaceAfter' => 80]);
$estiloTabela = ['borderSize' => 4, 'borderColor' => 'C9CEC6', 'cellMargin' => 70];
$cabecalho = ['bold' => true, 'color' => 'FFFFFF'];
$fundoCabecalho = ['bgColor' => '1C1F24'];
$direita = ['alignment' => 'right'];

$secao = $word->addSection(['marginTop' => 900, 'marginBottom' => 900, 'marginLeft' => 1000, 'marginRight' => 1000]);
$secao->addFooter()->addText('Gerado em ' . $rel['gerado_em'] . ' por ' . $rel['gerado_por'] . ' · Sistema XTEC', ['size' => 8, 'color' => '6B7380'], ['alignment' => 'center']);

$secao->addTitle(($rel['empresa']['nome_fantasia'] ?: 'XTEC') . ' · Relatório gerencial', 1);
$secao->addText('Período: ' . data_br($rel['de']) . ' a ' . data_br($rel['ate']), ['color' => '6B7380']);

/** Tabela simples com cabeçalho escuro; colunas numéricas alinhadas à direita. */
$tabela = static function (string $titulo, array $colunas, array $linhas, array $larguras, array $numericas = []) use ($secao, $estiloTabela, $cabecalho, $fundoCabecalho, $direita): void {
    $secao->addTitle($titulo, 2);
    $t = $secao->addTable($estiloTabela);
    $t->addRow();
    foreach ($colunas as $i => $c) {
        $t->addCell($larguras[$i], $fundoCabecalho)->addText($c, $cabecalho, in_array($i, $numericas, true) ? $direita : []);
    }
    if (!$linhas) {
        $t->addRow();
        $t->addCell(array_sum($larguras), ['gridSpan' => count($colunas)])->addText('Sem dados no período.', ['color' => '6B7380']);
    }
    foreach ($linhas as $linha) {
        $t->addRow();
        foreach ($linha as $i => $valor) {
            $t->addCell($larguras[$i])->addText((string) $valor, [], in_array($i, $numericas, true) ? $direita : []);
        }
    }
};

$tabela('Indicadores do período', ['Indicador', 'Valor'], indicadores_relatorio($rel), [6000, 3600], [1]);
$tabela('Produtos mais vendidos', ['Produto', 'Categoria', 'Qtd.', 'Receita'],
    array_map(static fn($p) => [$p['nome'], $p['categoria'], numero($p['quantidade']), moeda($p['receita'])], array_slice($rel['produtos'], 0, 15)),
    [3800, 2400, 1200, 2200], [2, 3]);
$tabela('Formas de pagamento', ['Forma', 'Pedidos', 'Total'],
    array_map(static fn($f) => [$formas[$f['forma']][0] ?? $f['forma'], numero($f['pedidos']), moeda($f['total'])], $rel['formas']),
    [4600, 2200, 2800], [1, 2]);
$tabela('Canais de venda', ['Canal', 'Pedidos', 'Total'],
    array_map(static fn($t) => [$tipos[$t['tipo']] ?? $t['tipo'], numero($t['pedidos']), moeda($t['total'])], $rel['tipos']),
    [4600, 2200, 2800], [1, 2]);
$tabela('Desempenho da equipe', ['Atendente', 'Pedidos', 'Ticket médio', 'Total'],
    array_map(static fn($a) => [$a['nome'], numero($a['pedidos']), moeda($a['ticket']), moeda($a['total'])], $rel['atendentes']),
    [3800, 1600, 2000, 2200], [1, 2, 3]);

$arquivo = tempnam(sys_get_temp_dir(), 'xtec_');
IOFactory::createWriter($word, 'Word2007')->save($arquivo);

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $rel['nome_arquivo'] . '.docx"');
header('Content-Length: ' . filesize($arquivo));
header('Cache-Control: must-revalidate');
readfile($arquivo);
unlink($arquivo);
exit;
