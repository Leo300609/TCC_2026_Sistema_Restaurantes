'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const config = require('../config');
const { pagina } = require('../layout');
const {
  h, cru, ler, moeda, entrada, entradaInt, entradaDecimal, lista, csrfCampo, tratarPost, data,
  formasPagamento, telefoneBr,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;

module.exports = async function pdv(ctx) {
  const u = await sessao.autenticar(ctx);
  sessao.exigirPermissao(ctx, u.perm.pdv);

  // ---------- venda (POST) ----------
  await tratarPost(ctx, async () => {
    if (entrada(ctx, 'acao') !== 'vender') throw new bd.ErroNegocio('Ação não reconhecida.');
    const produtos = lista(ctx, 'itens_produto'), qtds = lista(ctx, 'itens_qtd'), obs = lista(ctx, 'itens_obs');
    const itens = [];
    produtos.forEach((p, n) => {
      if ((parseInt(p, 10) || 0) > 0) itens.push([parseInt(p, 10), parseInt(qtds[n], 10) || 1, String(obs[n] ?? '').trim()]);
    });
    if (!itens.length) throw new bd.ErroNegocio('Adicione ao menos um item à venda.');

    const cobrarAgora = entrada(ctx, 'destino') === 'finalizar';
    const forma = entrada(ctx, 'forma_pagamento');
    const recebido = forma === 'DINHEIRO' && entrada(ctx, 'valor_recebido') !== '' ? entradaDecimal(ctx, 'valor_recebido') : null;

    const id = await bd.transacao(async (tx) => {
      const novo = Number(await tx.chamarValor('sp_pedido_criar', [entrada(ctx, 'tipo_pedido'), entrada(ctx, 'mesa'), entradaInt(ctx, 'cliente_id'), forma, entrada(ctx, 'observacao'), u.id]));
      for (const [produto, qtd, observacao] of itens) await tx.chamar('sp_pedido_item_adicionar', [novo, produto, qtd, observacao]);
      await tx.chamar('sp_pedido_totais', [novo, entradaDecimal(ctx, 'desconto')]);
      if (cobrarAgora) await tx.chamar('sp_pedido_finalizar', [novo, recebido]);
      return novo;
    });

    ctx.sessao.pdv_ultima = { id, cobrado: cobrarAgora };
    return [cobrarAgora ? `Venda #${id} concluída.` : `Pedido #${id} enviado para a cozinha.`, 'pdv?venda=' + id];
  });

  // ---------- dados (GET) ----------
  const cfg = await ler(ctx, () => bd.chamarUm('sp_config_listar', [u.id]), { aceita_delivery: 1, pagamentos: 'PIX,CREDITO,DEBITO,DINHEIRO' });
  const pagamentos = String(cfg.pagamentos || '').split(',').filter(Boolean);
  const formas = formasPagamento();

  const produtos = (await ler(ctx, () => bd.chamar('sp_cardapio_listar', ['']))).filter((p) => p.status === 'ATIVO');
  const categorias = [...new Set(produtos.map((p) => p.categoria))];
  const [clientes, mesas] = await Promise.all([
    ler(ctx, () => bd.chamar('sp_pedido_opcoes_clientes')),
    ler(ctx, () => bd.chamar('sp_mesa_opcoes')),
  ]);
  const mesaInicial = entrada(ctx, 'mesa');

  // resultado da última venda (vindo do redirecionamento)
  let ultima = null;
  const vendaId = entradaInt(ctx, 'venda');
  if (vendaId > 0 && N(ctx.sessao.pdv_ultima?.id) === vendaId) {
    ultima = await ler(ctx, () => bd.chamarUm('sp_pedido_comprovante', [vendaId]), null);
  }
  const troco = ultima && ultima.valor_recebido !== null && ultima.valor_recebido !== undefined ? N(ultima.valor_recebido) - N(ultima.total) : 0;

  const conteudo = h`
        ${ultima ? h`
          <div class="aviso aviso-ok" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>
              <strong>${ultima.etapa === 'ENTREGUE' ? 'Venda' : 'Pedido'} #${N(ultima.id)}</strong> · ${moeda(ultima.total)} no ${(formas[ultima.forma_pagamento] || [ultima.forma_pagamento])[0]}
              ${troco > 0 ? h` · <strong>Troco: ${moeda(troco)}</strong>` : ''}
              ${ultima.etapa === 'ENTREGUE' ? '' : ' · aguardando na cozinha'}
            </span>
            <a class="btn btn-pequeno" style="margin-left:auto" href="comprovante?id=${N(ultima.id)}" target="_blank" rel="noopener"><i class="fa-solid fa-print" aria-hidden="true"></i> Comprovante</a>
          </div>` : ''}

        ${!produtos.length ? h`
          <section class="painel-card"><p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-utensils" aria-hidden="true"></i></span>O cardápio não tem itens disponíveis. <a href="cardapios">Cadastre os itens no Cardápio</a> para começar a vender.</p></section>` : h`
        <form class="pdv" id="pdv" method="post" action="pdv" data-limpar="${ultima ? '1' : '0'}">
          ${csrfCampo(ctx)}
          <input type="hidden" name="acao" value="vender">

          <div class="pdv-catalogo">
            <div class="pdv-busca">
              <label class="busca">
                <span class="sr-only">Buscar item</span>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" id="pdv-busca" class="input-dash" placeholder="Buscar item ou código de barras (F2)" autocomplete="off">
              </label>
            </div>
            <div class="filtros" role="group" aria-label="Categorias">
              <button type="button" class="filtro ativo" data-categoria="">Todos <span>${produtos.length}</span></button>
              ${categorias.map((c) => h`<button type="button" class="filtro" data-categoria="${c}">${c} <span>${produtos.filter((p) => p.categoria === c).length}</span></button>`)}
            </div>
            <div class="pdv-produtos" id="pdv-produtos">
              ${produtos.map((p) => {
                const prod = p.produziveis;
                const semLimite = prod === null || prod === undefined;
                const esgotado = !semLimite && N(prod) <= 0;
                return h`<button type="button" class="pdv-produto" data-id="${N(p.id)}" data-nome="${p.nome}" data-preco="${p.preco}"
                        data-categoria="${p.categoria}" data-codigo="${p.codigo_barras ?? ''}" data-limite="${semLimite ? '' : N(prod)}"${esgotado ? cru(' disabled') : ''}>
                  <span class="cat">${p.categoria}</span>
                  <strong>${p.nome}</strong>
                  ${esgotado ? h`<span class="estoque-aviso">Sem estoque</span>` : !semLimite && N(prod) <= 10 ? h`<span class="estoque-aviso">Restam ${N(prod)}</span>` : ''}
                  <span class="preco">${moeda(p.preco)}</span>
                </button>`;
              })}
            </div>
            <p class="vazio" id="pdv-nada" hidden>Nenhum item encontrado.</p>
          </div>

          <a href="#comanda-atual" class="pdv-barra-movel" id="pdv-barra" hidden>
            <span><i class="fa-solid fa-receipt" aria-hidden="true"></i> <strong id="pdv-barra-itens">0 itens</strong></span>
            <span>Ver comanda · <strong id="pdv-barra-total">R$ 0,00</strong> <i class="fa-solid fa-arrow-down" aria-hidden="true"></i></span>
          </a>

          <aside class="pdv-carrinho" id="comanda-atual" aria-label="Comanda atual">
            <fieldset class="segmentado segmentado-3">
              <legend class="sr-only">Tipo do pedido</legend>
              <label><input type="radio" name="tipo_pedido" value="BALCAO"${mesaInicial === '' ? cru(' checked') : ''}><span><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Balcão</span></label>
              <label><input type="radio" name="tipo_pedido" value="SALAO"${mesaInicial !== '' ? cru(' checked') : ''}><span><i class="fa-solid fa-chair" aria-hidden="true"></i> Mesa</span></label>
              <label><input type="radio" name="tipo_pedido" value="DELIVERY"${String(cfg.aceita_delivery) === '1' ? '' : cru(' disabled')}><span><i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery</span></label>
            </fieldset>

            <div class="comanda">
              <div class="comanda-topo">
                <span class="comanda-numero">Comanda</span>
                <span class="comanda-tempo" id="pdv-relogio">${data('H:i')}</span>
              </div>

              <div class="linha-dupla" id="pdv-destino">
                <div class="grupo-input" data-mostrar="SALAO">
                  <label class="sr-only" for="pdv-mesa">Mesa</label>
                  <select id="pdv-mesa" name="mesa" class="input-dash">
                    <option value="">Escolha a mesa</option>
                    ${mesas.map((m) => h`<option value="${m.numero}"${String(m.numero) === mesaInicial ? cru(' selected') : ''}>Mesa ${m.numero} · ${m.area}</option>`)}
                  </select>
                </div>
                <div class="grupo-input" style="grid-column: span 2" data-mostrar="BALCAO DELIVERY SALAO">
                  <label class="sr-only" for="pdv-cliente">Cliente</label>
                  <select id="pdv-cliente" name="cliente_id" class="input-dash">
                    <option value="0">Cliente não identificado</option>
                    ${clientes.map((c) => h`<option value="${N(c.id)}">${c.nome} · ${telefoneBr(c.telefone)}</option>`)}
                  </select>
                </div>
              </div>

              <ul class="carrinho-itens" id="carrinho" aria-live="polite">
                <li class="carrinho-vazio">Toque nos itens para montar a comanda.</li>
              </ul>
              <div id="carrinho-campos"></div>

              <div class="carrinho-totais">
                <div><span>Itens</span><span id="t-subtotal">R$ 0,00</span></div>
                <div>
                  <label for="pdv-desconto">Desconto</label>
                  <input type="number" id="pdv-desconto" name="desconto" min="0" step="0.01" value="0" inputmode="decimal"
                         style="width:90px;height:26px;padding:0 6px;border:1px solid #e3dcc9;border-radius:6px;background:#fffefa;color:#26221b;text-align:right">
                </div>
                <div class="total"><span>Total</span><span id="t-total">R$ 0,00</span></div>
                <div class="troco" id="linha-troco" hidden><span>Troco</span><span id="t-troco">R$ 0,00</span></div>
              </div>
            </div>

            <div class="pdv-pagamento">
              <div class="pdv-formas" role="radiogroup" aria-label="Forma de pagamento">
                ${pagamentos.map((f, i) => h`<label><input type="radio" name="forma_pagamento" value="${f}"${i === 0 ? cru(' checked') : ''}><span><i class="${(formas[f] || [])[1] || 'fa-solid fa-money-bill'}" aria-hidden="true"></i> ${(formas[f] || [f])[0]}</span></label>`)}
              </div>
              <div class="grupo-input" id="campo-recebido" hidden>
                <label for="pdv-recebido">Valor recebido em dinheiro</label>
                <div class="campo-dinheiro"><input type="number" id="pdv-recebido" name="valor_recebido" class="input-dash" min="0" step="0.01" inputmode="decimal" placeholder="0,00"></div>
              </div>
              <input type="text" name="observacao" class="input-dash" maxlength="255" placeholder="Observação do pedido (opcional)">
              <div class="pdv-acoes">
                <button type="submit" name="destino" value="cozinha" class="btn btn-grande" id="btn-cozinha" disabled><i class="fa-solid fa-fire-burner" aria-hidden="true"></i> Enviar à cozinha</button>
                <button type="submit" name="destino" value="finalizar" class="btn btn-grande btn-primario" id="btn-cobrar" disabled><i class="fa-solid fa-check" aria-hidden="true"></i> Cobrar agora</button>
              </div>
              <button type="button" class="btn btn-fantasma btn-pequeno" id="btn-limpar"><i class="fa-solid fa-eraser" aria-hidden="true"></i> Limpar comanda</button>
            </div>
          </aside>
        </form>`}`;

  return pagina(ctx, {
    titulo: 'PDV', subtitulo: 'Frente de caixa: monte a comanda e cobre em segundos', paginaAtual: 'pdv',
    rodapeExtra: cru(`<script src="/frontend/js/painel/pdv.js?v=${config.appVersao}" defer></script>`),
  }, conteudo);
};
