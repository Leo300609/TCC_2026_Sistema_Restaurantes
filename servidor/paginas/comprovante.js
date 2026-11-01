'use strict';
/** Comprovante não fiscal de um pedido (cupom de 80 mm para impressora térmica). */
const bd = require('../bd');
const sessao = require('../sessao');
const config = require('../config');
const { h, cru, ler, moeda, entradaInt, flash, redirecionar, formasPagamento, telefoneBr } = require('../web');

const N = (v) => Number(v ?? 0) || 0;

module.exports = async function comprovante(ctx) {
  await sessao.autenticar(ctx);
  const id = entradaInt(ctx, 'id');
  const v = id > 0 ? await ler(ctx, () => bd.chamarUm('sp_pedido_comprovante', [id]), null) : null;
  if (!v) {
    flash(ctx, 'erro', 'Pedido não encontrado.');
    redirecionar('pedidos');
  }
  const itens = await ler(ctx, () => bd.chamar('sp_pedido_itens', [id]));
  const formas = formasPagamento();
  const recebido = v.valor_recebido !== null && v.valor_recebido !== undefined;
  const troco = recebido ? N(v.valor_recebido) - N(v.total) : 0;
  const origem = v.tipo === 'SALAO' ? 'Mesa ' + v.mesa : v.tipo === 'DELIVERY' ? 'Delivery' : 'Balcão';

  return h`<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Comprovante #${N(v.id)}</title>
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
      <h1>${v.empresa || config.appNome}</h1>
      ${v.razao_social ? h`<p class="pequeno">${v.razao_social}</p>` : ''}
      ${v.cnpj ? h`<p class="pequeno">CNPJ ${v.cnpj}</p>` : ''}
      ${v.empresa_endereco ? h`<p class="pequeno">${v.empresa_endereco}</p>` : ''}
      ${v.empresa_telefone ? h`<p class="pequeno">Tel. ${telefoneBr(v.empresa_telefone)}</p>` : ''}
    </div>
    <hr class="tracejado">
    <p class="centro"><strong>CUPOM NÃO FISCAL</strong></p>
    <div class="linha"><span>Pedido #${N(v.id)}</span><span>${v.data_hora}</span></div>
    <div class="linha"><span>${origem}</span><span>Atendente: ${String(v.atendente || '').split(' ')[0]}</span></div>
    ${v.cliente ? h`
      <p>Cliente: ${v.cliente}</p>
      ${v.tipo === 'DELIVERY' ? h`<p class="pequeno">${v.cliente_endereco} · ${telefoneBr(v.cliente_telefone)}</p>` : ''}` : ''}
    <hr class="tracejado">
    <table>
      ${itens.map((it) => h`
        <tr>
          <td>${N(it.quantidade)}x ${it.nome}</td>
          <td class="v">${moeda(N(it.quantidade) * N(it.preco))}</td>
        </tr>
        ${it.observacao ? h`<tr><td colspan="2" class="obs">${it.observacao}</td></tr>` : ''}`)}
    </table>
    <hr class="tracejado">
    <div class="linha"><span>Subtotal</span><span>${moeda(v.valor)}</span></div>
    ${N(v.desconto) > 0 ? h`<div class="linha"><span>Desconto</span><span>- ${moeda(v.desconto)}</span></div>` : ''}
    <div class="linha total"><span>TOTAL</span><span>${moeda(v.total)}</span></div>
    <div class="linha"><span>Pagamento</span><span>${(formas[v.forma_pagamento] || [v.forma_pagamento])[0]}</span></div>
    ${recebido ? h`
      <div class="linha"><span>Recebido</span><span>${moeda(v.valor_recebido)}</span></div>
      <div class="linha"><span>Troco</span><span>${moeda(Math.max(0, troco))}</span></div>` : ''}
    ${v.status === 'CANCELADA' ? h`<p class="centro total" style="margin-top:8px">*** PEDIDO CANCELADO ***</p>` : ''}
    ${v.observacao ? h`<hr class="tracejado"><p>Obs.: ${v.observacao}</p>` : ''}
    <hr class="tracejado">
    <p class="centro pequeno">Obrigado pela preferência!<br>Documento sem valor fiscal.</p>
  </main>
  <script>
    if (new URLSearchParams(location.search).get('imprimir') === '1') window.addEventListener('load', () => window.print());
  </script>
</body>

</html>`;
};
