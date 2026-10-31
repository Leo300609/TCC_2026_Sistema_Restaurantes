<?php
require __DIR__ . '/_partials/auth.php';

$id = entrada_int('id');
$v = $id > 0 ? ler(fn() => chamar_um('sp_pedido_comprovante', [$id]), null) : null;
if (!$v) {
  flash('erro', 'Pedido não encontrado.');
  redirecionar('pedidos.php');
}
$itens = ler(fn() => chamar('sp_pedido_itens', [$id]));
$formas = formas_pagamento();
$troco = $v['valor_recebido'] !== null ? (float) $v['valor_recebido'] - (float) $v['total'] : 0;
$origem = match ($v['tipo']) {
  'SALAO' => 'Mesa ' . $v['mesa'],
  'DELIVERY' => 'Delivery',
  default => 'Balcão',
};
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Comprovante #<?= (int) $v['id'] ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600;700&display=swap">
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #e9eaec; font-family: "IBM Plex Mono", ui-monospace, monospace; color: #1b1b1b; font-size: 12.5px; }
    .acoes { display: flex; justify-content: center; gap: 8px; padding: 18px; font-family: system-ui, sans-serif; }
    .acoes button, .acoes a { padding: 9px 16px; border: 1px solid #c9cec6; border-radius: 9px; background: #fff; font: 600 14px system-ui, sans-serif; color: #1c1f24; text-decoration: none; cursor: pointer; }
    .acoes button { background: #d7262e; border-color: #d7262e; color: #fff; }
    .cupom { width: 80mm; max-width: calc(100vw - 24px); margin: 0 auto 30px; padding: 18px 16px 22px; background: #fffdf6; box-shadow: 0 10px 30px -10px rgba(0, 0, 0, .3); }
    .centro { text-align: center; }
    h1 { font-size: 16px; letter-spacing: .02em; }
    .tracejado { border: 0; border-top: 1px dashed #999; margin: 10px 0; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 2px 0; vertical-align: top; }
    td.v { text-align: right; white-space: nowrap; padding-left: 8px; }
    .obs { padding-left: 14px; font-size: 11px; color: #555; }
    .linha { display: flex; justify-content: space-between; gap: 8px; }
    .total { font-size: 16px; font-weight: 700; }
    .pequeno { font-size: 11px; color: #555; }
    @media print {
      @page { size: 80mm auto; margin: 0; }
      body { background: #fff; }
      .acoes { display: none; }
      .cupom { width: auto; margin: 0; box-shadow: none; }
    }
  </style>
</head>

<body>
  <div class="acoes">
    <button type="button" onclick="window.print()">Imprimir</button>
    <a href="javascript:window.close()">Fechar</a>
  </div>

  <main class="cupom">
    <div class="centro">
      <h1><?= e($v['empresa'] ?: config('app_nome')) ?></h1>
      <?php if ($v['razao_social']): ?><p class="pequeno"><?= e($v['razao_social']) ?></p><?php endif; ?>
      <?php if ($v['cnpj']): ?><p class="pequeno">CNPJ <?= e($v['cnpj']) ?></p><?php endif; ?>
      <?php if ($v['empresa_endereco']): ?><p class="pequeno"><?= e($v['empresa_endereco']) ?></p><?php endif; ?>
      <?php if ($v['empresa_telefone']): ?><p class="pequeno">Tel. <?= e(telefone_br($v['empresa_telefone'])) ?></p><?php endif; ?>
    </div>
    <hr class="tracejado">
    <p class="centro"><strong>CUPOM NÃO FISCAL</strong></p>
    <div class="linha"><span>Pedido #<?= (int) $v['id'] ?></span><span><?= e($v['data_hora']) ?></span></div>
    <div class="linha"><span><?= e($origem) ?></span><span>Atendente: <?= e(explode(' ', $v['atendente'])[0]) ?></span></div>
    <?php if ($v['cliente']): ?>
      <p>Cliente: <?= e($v['cliente']) ?></p>
      <?php if ($v['tipo'] === 'DELIVERY'): ?><p class="pequeno"><?= e($v['cliente_endereco']) ?> · <?= e(telefone_br($v['cliente_telefone'])) ?></p><?php endif; ?>
    <?php endif; ?>
    <hr class="tracejado">
    <table>
      <?php foreach ($itens as $it): ?>
        <tr>
          <td><?= (int) $it['quantidade'] ?>x <?= e($it['nome']) ?></td>
          <td class="v"><?= moeda($it['quantidade'] * $it['preco']) ?></td>
        </tr>
        <?php if ($it['observacao']): ?><tr><td colspan="2" class="obs"><?= e($it['observacao']) ?></td></tr><?php endif; ?>
      <?php endforeach; ?>
    </table>
    <hr class="tracejado">
    <div class="linha"><span>Subtotal</span><span><?= moeda($v['valor']) ?></span></div>
    <?php if ((float) $v['desconto'] > 0): ?><div class="linha"><span>Desconto</span><span>- <?= moeda($v['desconto']) ?></span></div><?php endif; ?>
    <div class="linha total"><span>TOTAL</span><span><?= moeda($v['total']) ?></span></div>
    <div class="linha"><span>Pagamento</span><span><?= e($formas[$v['forma_pagamento']][0] ?? $v['forma_pagamento']) ?></span></div>
    <?php if ($v['valor_recebido'] !== null): ?>
      <div class="linha"><span>Recebido</span><span><?= moeda($v['valor_recebido']) ?></span></div>
      <div class="linha"><span>Troco</span><span><?= moeda(max(0, $troco)) ?></span></div>
    <?php endif; ?>
    <?php if ($v['status'] === 'CANCELADA'): ?><p class="centro total" style="margin-top:8px">*** PEDIDO CANCELADO ***</p><?php endif; ?>
    <?php if ($v['observacao']): ?><hr class="tracejado"><p>Obs.: <?= e($v['observacao']) ?></p><?php endif; ?>
    <hr class="tracejado">
    <p class="centro pequeno">Obrigado pela preferência!<br>Documento sem valor fiscal.</p>
  </main>
  <script>
    if (new URLSearchParams(location.search).get('imprimir') === '1') window.addEventListener('load', () => window.print());
  </script>
</body>

</html>
