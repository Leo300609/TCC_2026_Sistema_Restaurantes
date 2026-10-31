<?php
declare(strict_types=1);

/** Relatório gerencial do período em PDF (Dompdf). */
require __DIR__ . '/_dados.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$formas = formas_pagamento();
$tipos = ['SALAO' => 'Salão', 'BALCAO' => 'Balcão', 'DELIVERY' => 'Delivery'];

$linhasIndicadores = '';
foreach (indicadores_relatorio($rel) as [$rotulo, $valor]) {
    $linhasIndicadores .= '<tr><td>' . e($rotulo) . '</td><td class="v">' . e($valor) . '</td></tr>';
}
$linhasProdutos = '';
foreach (array_slice($rel['produtos'], 0, 15) as $n => $p) {
    $linhasProdutos .= '<tr><td>' . ($n + 1) . '</td><td>' . e($p['nome']) . '<br><small>' . e($p['categoria']) . '</small></td><td class="v">'
        . numero($p['quantidade']) . '</td><td class="v">' . e(moeda($p['receita'])) . '</td></tr>';
}
$linhasFormas = '';
foreach ($rel['formas'] as $f) {
    $linhasFormas .= '<tr><td>' . e($formas[$f['forma']][0] ?? $f['forma']) . '</td><td class="v">' . numero($f['pedidos']) . '</td><td class="v">' . e(moeda($f['total'])) . '</td></tr>';
}
$linhasTipos = '';
foreach ($rel['tipos'] as $t) {
    $linhasTipos .= '<tr><td>' . e($tipos[$t['tipo']] ?? $t['tipo']) . '</td><td class="v">' . numero($t['pedidos']) . '</td><td class="v">' . e(moeda($t['total'])) . '</td></tr>';
}
$linhasEquipe = '';
foreach ($rel['atendentes'] as $a) {
    $linhasEquipe .= '<tr><td>' . e($a['nome']) . '</td><td class="v">' . numero($a['pedidos']) . '</td><td class="v">' . e(moeda($a['ticket'])) . '</td><td class="v">' . e(moeda($a['total'])) . '</td></tr>';
}

$empresa = e($rel['empresa']['nome_fantasia'] ?: config('app_nome'));
$periodo = e(data_br($rel['de']) . ' a ' . data_br($rel['ate']));
$rodape = e('Gerado em ' . $rel['gerado_em'] . ' por ' . $rel['gerado_por']);
$vazio = '<tr><td colspan="4" class="vazio">Sem vendas no período.</td></tr>';

$html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8">
<style>
  @page { margin: 28px 34px 40px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1c1f24; }
  .topo { border-bottom: 3px solid #d7262e; padding-bottom: 8px; margin-bottom: 14px; }
  .topo h1 { margin: 0; font-size: 20px; }
  .topo p { margin: 2px 0 0; color: #6b7380; }
  h2 { font-size: 13px; margin: 18px 0 6px; color: #b81f26; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #1c1f24; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
  td { padding: 5px 8px; border-bottom: 1px solid #e1e4df; vertical-align: top; }
  td.v, th.v { text-align: right; white-space: nowrap; }
  small { color: #6b7380; }
  .dupla { width: 100%; }
  .dupla > tbody > tr > td { width: 50%; border: 0; padding: 0 8px 0 0; vertical-align: top; }
  .vazio { color: #6b7380; text-align: center; }
  .rodape { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 9px; color: #6b7380; text-align: center; }
</style></head>
<body>
  <div class="topo">
    <h1>{$empresa} · Relatório gerencial</h1>
    <p>Período: {$periodo}</p>
  </div>

  <h2>Indicadores do período</h2>
  <table>{$linhasIndicadores}</table>

  <h2>Produtos mais vendidos</h2>
  <table><thead><tr><th>#</th><th>Produto</th><th class="v">Qtd.</th><th class="v">Receita</th></tr></thead>
  <tbody>{$linhasProdutos}</tbody></table>

  <table class="dupla"><tr>
    <td><h2>Formas de pagamento</h2>
      <table><thead><tr><th>Forma</th><th class="v">Pedidos</th><th class="v">Total</th></tr></thead><tbody>{$linhasFormas}</tbody></table></td>
    <td><h2>Canais de venda</h2>
      <table><thead><tr><th>Canal</th><th class="v">Pedidos</th><th class="v">Total</th></tr></thead><tbody>{$linhasTipos}</tbody></table></td>
  </tr></table>

  <h2>Desempenho da equipe</h2>
  <table><thead><tr><th>Atendente</th><th class="v">Pedidos</th><th class="v">Ticket médio</th><th class="v">Total</th></tr></thead>
  <tbody>{$linhasEquipe}</tbody></table>

  <div class="rodape">{$rodape} · Sistema XTEC de gestão para restaurantes</div>
</body></html>
HTML;
$html = str_replace('<tbody></tbody>', '<tbody>' . $vazio . '</tbody>', $html);

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($rel['nome_arquivo'] . '.pdf', ['Attachment' => true]);
exit;
