'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina } = require('../layout');
const {
  h, cru, ler, moeda, entrada, entradaInt, entradaDecimal, lista, csrfCampo, tratarPost, url, flash,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;
const FORMAS = { PIX: 'Pix', CREDITO: 'Crédito', DEBITO: 'Débito', DINHEIRO: 'Dinheiro', BOLETO: 'Boleto' };
const COLUNAS = [['NOVO', 'Novos'], ['PREPARO', 'Em preparo'], ['PRONTO', 'Pronto / a caminho'], ['ENTREGUE', 'Entregues']];
const ANTERIOR = { PREPARO: 'NOVO', PRONTO: 'PREPARO' };
const PROXIMA = { NOVO: ['PREPARO', 'Aceitar', true], PREPARO: ['PRONTO', 'Marcar pronto', false], PRONTO: ['ENTREGUE', 'Confirmar entrega', false] };

/** Lê as linhas de itens do formulário (itens_produto[], itens_qtd[], itens_obs[]). */
function itensDoFormulario(ctx) {
  const produtos = lista(ctx, 'itens_produto'), qtds = lista(ctx, 'itens_qtd'), obs = lista(ctx, 'itens_obs');
  const itens = [];
  produtos.forEach((p, n) => {
    const produto = parseInt(p, 10) || 0;
    if (produto <= 0) return; // linha em branco
    itens.push([produto, parseInt(qtds[n], 10) || 1, String(obs[n] ?? '').trim()]);
  });
  return itens;
}

const tempo = (min) => (min >= 60 ? `${Math.floor(min / 60)}h ${String(min % 60).padStart(2, '0')}min` : `${min} min`);
const origem = (p) => {
  const cli = p.cliente ? h` · ${p.cliente}` : '';
  if (p.tipo === 'SALAO') return h`<i class="fa-solid fa-table-cells-large" aria-hidden="true"></i> Mesa ${p.mesa}${cli}`;
  if (p.tipo === 'BALCAO') return h`<i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Balcão${cli}`;
  return h`<i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery · ${p.cliente || 'sem cliente'}`;
};

module.exports = async function pedidos(ctx) {
  const u = await sessao.autenticar(ctx);
  const { perm } = u;
  const ehGerente = perm.financeiro; // administrador ou gerente: exclui e estorna

  // ---------- ações (POST) ----------
  await tratarPost(ctx, async () => {
    const acao = entrada(ctx, 'acao');
    const id = entradaInt(ctx, 'id');
    if (acao === 'salvar') {
      const itens = itensDoFormulario(ctx);
      if (!itens.length) throw new bd.ErroNegocio('Adicione ao menos um item ao pedido.');
      const tipo = entrada(ctx, 'tipo_pedido');
      const desconto = entradaDecimal(ctx, 'desconto');
      // cabeçalho, itens e totais: tudo ou nada
      const numero = await bd.transacao(async (tx) => {
        let n;
        if (id) {
          await tx.chamar('sp_pedido_atualizar', [id, tipo, entrada(ctx, 'mesa'), entradaInt(ctx, 'cliente_id'), entrada(ctx, 'forma_pagamento'), entrada(ctx, 'observacao')]);
          await tx.chamar('sp_pedido_item_limpar', [id]);
          n = id;
        } else {
          n = Number(await tx.chamarValor('sp_pedido_criar', [tipo, entrada(ctx, 'mesa'), entradaInt(ctx, 'cliente_id'), entrada(ctx, 'forma_pagamento'), entrada(ctx, 'observacao'), u.id]));
        }
        for (const [produto, qtd, obs] of itens) await tx.chamar('sp_pedido_item_adicionar', [n, produto, qtd, obs]);
        await tx.chamar('sp_pedido_totais', [n, desconto]);
        return n;
      });
      return [id ? `Pedido #${numero} atualizado.` : `Pedido #${numero} criado.`, url(ctx, { novo: null, editar: null })];
    }
    if (acao === 'etapa') {
      const destino = entrada(ctx, 'etapa');
      await bd.chamar('sp_pedido_etapa', [id, destino]);
      return destino === 'ENTREGUE' ? `Pedido #${id} entregue e registrado no Fluxo de Caixa.` : `Pedido #${id} atualizado.`;
    }
    if (acao === 'cancelar') {
      await bd.chamar('sp_pedido_cancelar', [id, ehGerente ? 1 : 0]);
      return [ehGerente && entrada(ctx, 'etapa') === 'ENTREGUE' ? `Pedido #${id} cancelado e estornado.` : `Pedido #${id} cancelado.`, url(ctx, { cancelar: null, editar: null, detalhes: null })];
    }
    if (acao === 'excluir') {
      if (!ehGerente) throw new bd.ErroNegocio('Somente administradores e gerentes podem excluir pedidos.');
      await bd.chamar('sp_pedido_excluir', [id]);
      return [`Pedido #${id} excluído.`, url(ctx, { excluir: null, editar: null })];
    }
    throw new bd.ErroNegocio('Ação não reconhecida.');
  });

  // ---------- dados (GET) ----------
  const cfg = await ler(ctx, () => bd.chamarUm('sp_config_listar', [u.id]), { tempo_preparo: 20, aceita_delivery: 1, pagamentos: 'PIX,CREDITO,DEBITO,DINHEIRO' });
  const limitePreparo = Math.max(1, N(cfg.tempo_preparo));
  const aceitaDelivery = String(cfg.aceita_delivery) === '1';
  const pagamentosAceitos = String(cfg.pagamentos || '').split(',').filter(Boolean);

  const busca = entrada(ctx, 'q');
  const tipoFiltro = ['SALAO', 'BALCAO', 'DELIVERY'].includes(entrada(ctx, 'tipo')) ? entrada(ctx, 'tipo') : '';

  const [todos, itensQuadro] = await Promise.all([
    ler(ctx, () => bd.chamar('sp_pedido_quadro')),
    ler(ctx, () => bd.chamar('sp_pedido_quadro_itens')),
  ]);
  const itensPorPedido = {};
  for (const it of itensQuadro) (itensPorPedido[N(it.venda_id)] ??= []).push(it);

  const termo = busca.replace(/^#+/, '').toLowerCase();
  const base = todos.filter((p) => termo === '' || String(p.id).includes(termo)
    || String(p.cliente ?? '').toLowerCase().includes(termo) || String(p.mesa ?? '').toLowerCase().includes(termo));
  const contaTipo = (t) => base.filter((p) => t === '' || p.tipo === t).length;
  const lst = base.filter((p) => tipoFiltro === '' || p.tipo === tipoFiltro);

  // formulário (novo / editar)
  const editarId = entradaInt(ctx, 'editar');
  const formAberto = 'novo' in ctx.query || editarId > 0;
  let pedido = null;
  let itensPedido = [];
  if (editarId > 0) {
    pedido = await ler(ctx, () => bd.chamarUm('sp_pedido_obter', [editarId]), null);
    if (pedido && ['NOVO', 'PREPARO', 'PRONTO'].includes(pedido.etapa)) itensPedido = await ler(ctx, () => bd.chamar('sp_pedido_itens', [editarId]));
    else { pedido = null; flash(ctx, 'erro', 'Esse pedido não existe ou não pode mais ser editado.'); }
  }
  const p = pedido || {
    id: 0, tipo: entrada(ctx, 'mesa') !== '' || !entrada(ctx, 'tipo_novo') ? 'SALAO' : entrada(ctx, 'tipo_novo'),
    mesa: entrada(ctx, 'mesa'), cliente_id: 0, forma_pagamento: pagamentosAceitos[0] || 'PIX', desconto: 0, observacao: '',
  };
  const [produtos, clientes, mesasOpcoes] = formAberto ? await Promise.all([
    ler(ctx, () => bd.chamar('sp_pedido_opcoes_produtos')),
    ler(ctx, () => bd.chamar('sp_pedido_opcoes_clientes')),
    ler(ctx, () => bd.chamar('sp_mesa_opcoes')),
  ]) : [[], [], []];
  const formasForm = [...new Set([...pagamentosAceitos, p.forma_pagamento])];
  const linhasItens = Math.max(6, itensPedido.length + 3);

  const detalheId = entradaInt(ctx, 'detalhes');
  const detalhe = detalheId > 0 ? await ler(ctx, () => bd.chamarUm('sp_pedido_obter', [detalheId]), null) : null;
  const itensDetalhe = detalhe ? await ler(ctx, () => bd.chamar('sp_pedido_itens', [detalheId])) : [];

  const cancelarId = entradaInt(ctx, 'cancelar');
  const alvoCancelar = cancelarId > 0 ? await ler(ctx, () => bd.chamarUm('sp_pedido_obter', [cancelarId]), null) : null;
  const excluirId = entradaInt(ctx, 'excluir');
  const alvoExcluir = ehGerente && excluirId > 0 ? await ler(ctx, () => bd.chamarUm('sp_pedido_obter', [excluirId]), null) : null;

  const confirmacao = ([param, alvo, tituloConf, botao, icone]) => (!alvo ? '' : h`
            <section class="painel-card confirmar-card" aria-labelledby="t-${param}">
              <h2 id="t-${param}">${tituloConf}</h2>
              <p>
                ${param === 'cancelar'
                  ? h`Cancelar o pedido <strong>#${N(alvo.id)}</strong>?${alvo.etapa === 'ENTREGUE' ? ' Como ele já foi entregue, o valor será estornado do Fluxo de Caixa.' : ''}`
                  : h`Excluir definitivamente o pedido <strong>#${N(alvo.id)}</strong>? Essa ação não pode ser desfeita.`}
              </p>
              <form method="post" action="${url(ctx)}">
                ${csrfCampo(ctx)}
                <input type="hidden" name="acao" value="${param}">
                <input type="hidden" name="id" value="${N(alvo.id)}">
                <input type="hidden" name="etapa" value="${alvo.etapa}">
                <button type="submit" class="btn btn-perigo"><i class="fa-solid ${icone}" aria-hidden="true"></i> ${botao}</button>
                <a class="btn btn-secundario" href="${url(ctx, { [param]: null })}" data-fechar>Voltar</a>
              </form>
            </section>`);

  const optionsItens = (atual) => {
    let anterior = null;
    const saida = [h`<option value="0">— Escolha um item do cardápio —</option>`];
    for (const pr of produtos) {
      if (pr.categoria !== anterior) {
        if (anterior !== null) saida.push(cru('</optgroup>'));
        saida.push(h`<optgroup label="${pr.categoria}">`);
        anterior = pr.categoria;
      }
      saida.push(h`<option value="${N(pr.id)}"${atual && N(atual.produto_id) === N(pr.id) ? cru(' selected') : ''}>${pr.nome} — ${moeda(pr.preco)}</option>`);
    }
    if (anterior !== null) saida.push(cru('</optgroup>'));
    return saida;
  };

  const deliveryOk = aceitaDelivery || p.tipo === 'DELIVERY';
  const conteudo = h`
<script>document.body.dataset.atualizar = '${formAberto || detalhe || alvoCancelar || alvoExcluir ? '0' : '30'}';</script>

        <form class="toolbar" method="get" action="pedidos">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar pedido</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="${busca}" placeholder="Buscar por número, mesa ou cliente" autocomplete="off">
            </label>
            ${tipoFiltro ? h`<input type="hidden" name="tipo" value="${tipoFiltro}">` : ''}
            <button type="submit" class="btn btn-secundario">Buscar</button>
            <div class="filtros" role="group" aria-label="Tipo de pedido">
              ${[['', 'Todos'], ['SALAO', 'Salão'], ['BALCAO', 'Balcão'], ['DELIVERY', 'Delivery']].map(([valor, rotulo]) => h`<a class="filtro${tipoFiltro === valor ? ' ativo' : ''}" href="${url(ctx, { tipo: valor || null })}">${rotulo} <span>${contaTipo(valor)}</span></a>`)}
            </div>
          </div>
          <div class="toolbar-grupo">
            <a class="btn" href="cozinha"><i class="fa-solid fa-fire-burner" aria-hidden="true"></i> Tela da cozinha</a>
            ${perm.pdv ? h`<a class="btn" href="pdv"><i class="fa-solid fa-cash-register" aria-hidden="true"></i> Abrir PDV</a>` : ''}
            <a class="btn btn-primario" href="${url(ctx, { novo: 1, editar: null, detalhes: null })}#formulario"><i class="fa-solid fa-plus" aria-hidden="true"></i> Novo pedido</a>
          </div>
        </form>

        ${[['cancelar', alvoCancelar, 'Cancelar pedido', 'Cancelar', 'fa-ban'], ['excluir', alvoExcluir, 'Excluir pedido', 'Excluir', 'fa-trash']].map(confirmacao)}

        ${detalhe ? h`
          <section class="painel-card form-card" aria-labelledby="t-det">
            <div class="painel-header"><h2 id="t-det">Pedido #${N(detalhe.id)}</h2><a class="gaveta-fechar" href="${url(ctx, { detalhes: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a></div>
            <div class="gaveta-corpo">
              <p class="comanda-origem">${origem(detalhe)}</p>
              <ul class="comanda-itens">
                ${itensDetalhe.map((it) => h`<li><span class="qtd">${N(it.quantidade)}x</span> ${it.nome} <span class="item-valor">${moeda(N(it.quantidade) * N(it.preco))}</span>${it.observacao ? h` <em>${it.observacao}</em>` : ''}</li>`)}
              </ul>
              ${detalhe.observacao ? h`<p class="modal-texto">Observação: ${detalhe.observacao}</p>` : ''}
              <p class="modal-texto">Atendido por ${detalhe.atendente} · ${FORMAS[detalhe.forma_pagamento] || detalhe.forma_pagamento}${N(detalhe.desconto) > 0 ? ' · desconto de ' + moeda(detalhe.desconto) : ''}</p>
              <p class="total-pedido"><span>Total</span><strong>${moeda(detalhe.total)}</strong></p>
              <div class="form-acoes">
                <a class="btn" href="comprovante?id=${N(detalhe.id)}" target="_blank" rel="noopener"><i class="fa-solid fa-print" aria-hidden="true"></i> Comprovante</a>
                ${ehGerente && detalhe.etapa !== 'CANCELADO' ? h`<a class="btn btn-perigo empurra" href="${url(ctx, { cancelar: detalhe.id, detalhes: null })}"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar pedido (estornar)</a>` : ''}
              </div>
            </div>
          </section>` : ''}

        ${formAberto && (editarId === 0 || pedido) ? h`
          <section class="painel-card form-card" id="formulario" aria-labelledby="t-form">
            <div class="painel-header"><h2 id="t-form">${p.id ? 'Editar pedido #' + N(p.id) : 'Novo pedido'}</h2><a class="gaveta-fechar" href="${url(ctx, { novo: null, editar: null, mesa: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a></div>
            ${!produtos.length ? h`<p class="vazio">Não há itens disponíveis no cardápio. Cadastre itens na tela de Cardápio antes de criar pedidos.</p>` : h`
              <form method="post" action="${url(ctx)}">
                ${csrfCampo(ctx)}
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" value="${N(p.id)}">

                <fieldset class="segmentado segmentado-3">
                  <legend class="sr-only">Tipo do pedido</legend>
                  <label><input type="radio" name="tipo_pedido" value="SALAO"${p.tipo === 'SALAO' ? cru(' checked') : ''}><span><i class="fa-solid fa-table-cells-large" aria-hidden="true"></i> Salão</span></label>
                  <label><input type="radio" name="tipo_pedido" value="BALCAO"${p.tipo === 'BALCAO' ? cru(' checked') : ''}><span><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Balcão</span></label>
                  <label><input type="radio" name="tipo_pedido" value="DELIVERY"${p.tipo === 'DELIVERY' ? cru(' checked') : ''}${deliveryOk ? '' : cru(' disabled')}><span><i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery${deliveryOk ? '' : ' (pausado)'}</span></label>
                </fieldset>

                <div class="form-grid">
                  <div class="grupo-input">
                    <label for="p-mesa">Mesa (só no salão)</label>
                    <input type="text" id="p-mesa" name="mesa" class="input-dash" maxlength="20" placeholder="Ex.: 4" list="lista-mesas" value="${p.mesa ?? ''}">
                    <datalist id="lista-mesas">${mesasOpcoes.map((m) => h`<option value="${m.numero}">${m.area} · ${N(m.lugares)} lugares</option>`)}</datalist>
                  </div>
                  <div class="grupo-input">
                    <label for="p-cliente">Cliente (obrigatório no delivery)</label>
                    <select id="p-cliente" name="cliente_id" class="input-dash">
                      <option value="0">Sem cliente identificado</option>
                      ${clientes.map((c) => h`<option value="${N(c.id)}"${N(c.id) === N(p.cliente_id) ? cru(' selected') : ''}>${c.nome}</option>`)}
                    </select>
                  </div>
                </div>

                <h3 class="subtitulo-form">Itens do pedido</h3>
                <div class="itens-pedido">
                  ${Array.from({ length: linhasItens }, (_, n) => {
                    const atual = itensPedido[n] || null;
                    return h`<div class="item-pedido">
                      <select name="itens_produto[]" class="input-dash" aria-label="Item ${n + 1}">
                        ${optionsItens(atual)}
                      </select>
                      <input type="number" name="itens_qtd[]" class="input-dash" min="1" max="999" step="1" value="${atual ? N(atual.quantidade) : 1}" aria-label="Quantidade do item ${n + 1}">
                      <input type="text" name="itens_obs[]" class="input-dash" maxlength="255" placeholder="Observação (ex.: sem sal)" value="${atual?.observacao ?? ''}" aria-label="Observação do item ${n + 1}">
                    </div>`;
                  })}
                </div>
                <p class="painel-meta">Linhas em branco são ignoradas. O total é calculado ao salvar.</p>

                <div class="form-grid">
                  <div class="grupo-input">
                    <label for="p-forma">Forma de pagamento</label>
                    <select id="p-forma" name="forma_pagamento" class="input-dash" required>
                      ${formasForm.map((f) => h`<option value="${f}"${f === p.forma_pagamento ? cru(' selected') : ''}>${FORMAS[f] || f}</option>`)}
                    </select>
                  </div>
                  <div class="grupo-input">
                    <label for="p-desc">Desconto (R$)</label>
                    <input type="number" id="p-desc" name="desconto" class="input-dash" min="0" step="0.01" inputmode="decimal" value="${N(p.desconto) || 0}">
                  </div>
                  <div class="grupo-input largo">
                    <label for="p-obs">Observação do pedido (opcional)</label>
                    <input type="text" id="p-obs" name="observacao" class="input-dash" maxlength="255" value="${p.observacao ?? ''}">
                  </div>
                </div>

                <div class="form-acoes">
                  <button type="submit" class="btn btn-primario">${p.id ? 'Salvar alterações' : 'Criar pedido'}</button>
                  <a class="btn btn-secundario" href="${url(ctx, { novo: null, editar: null, mesa: null })}" data-fechar>Cancelar</a>
                  ${p.id ? h`
                    <a class="btn btn-fantasma" href="${url(ctx, { cancelar: p.id, editar: null })}"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar pedido</a>
                    ${ehGerente ? h`<a class="btn btn-perigo" href="${url(ctx, { excluir: p.id, editar: null })}"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</a>` : ''}` : ''}
                </div>
              </form>`}
          </section>` : ''}

        <div class="quadro">
          ${COLUNAS.map(([etapa, rotuloColuna], n) => {
            let doGrupo = lst.filter((x) => x.etapa === etapa);
            if (etapa === 'ENTREGUE') doGrupo = doGrupo.slice().reverse();
            return h`
            <section class="coluna" data-etapa="${etapa}" aria-labelledby="col-${n}">
              <header class="coluna-topo">
                <h2 id="col-${n}">${rotuloColuna}</h2>
                <span class="contagem">${doGrupo.length}</span>
              </header>
              ${!doGrupo.length ? h`<p class="coluna-vazia">Nenhum pedido</p>` : ''}
              ${doGrupo.map((pd) => {
                const entregue = pd.etapa === 'ENTREGUE';
                const atrasado = ['NOVO', 'PREPARO'].includes(pd.etapa) && N(pd.minutos) > limitePreparo;
                const itensCard = itensPorPedido[N(pd.id)] || [];
                const [destino, rotuloBotao, destaque] = PROXIMA[pd.etapa] || [];
                return h`
                <article class="comanda${entregue ? ' concluida' : ''}">
                  <header class="comanda-topo">
                    <strong class="comanda-numero">#${N(pd.id)}</strong>
                    ${entregue ? h`<span class="status entregue">Entregue</span>`
                      : pd.etapa === 'PRONTO' ? h`<span class="comanda-tempo"><i class="fa-regular fa-clock" aria-hidden="true"></i> ${pd.tipo === 'DELIVERY' ? 'saiu' : 'pronto'} há ${tempo(N(pd.minutos_etapa))}</span>`
                      : h`<span class="comanda-tempo${atrasado ? ' atrasado' : ''}"><i class="fa-regular fa-clock" aria-hidden="true"></i> há ${tempo(N(pd.minutos))}</span>`}
                  </header>
                  <p class="comanda-origem">${origem(pd)}</p>
                  <ul class="comanda-itens">
                    ${itensCard.map((it) => h`<li><span class="qtd">${N(it.quantidade)}x</span> ${it.nome}${it.observacao ? h` <em>${it.observacao}</em>` : ''}</li>`)}
                  </ul>
                  ${pd.observacao ? h`<p class="comanda-obs"><i class="fa-regular fa-note-sticky" aria-hidden="true"></i> ${pd.observacao}</p>` : ''}
                  <footer class="comanda-rodape">
                    <div class="comanda-total">
                      <strong>${moeda(pd.total)}</strong>
                      <small>${FORMAS[pd.forma_pagamento] || pd.forma_pagamento}</small>
                    </div>
                    <div class="comanda-acoes">
                      ${entregue ? h`
                        <span class="comanda-hora">${pd.hora_etapa}</span>
                        <a class="btn btn-pequeno btn-fantasma btn-icone" href="comprovante?id=${N(pd.id)}" target="_blank" rel="noopener" title="Comprovante" aria-label="Comprovante do pedido ${N(pd.id)}"><i class="fa-solid fa-print" aria-hidden="true"></i></a>
                        <a class="btn btn-pequeno btn-fantasma" href="${url(ctx, { detalhes: pd.id, editar: null, novo: null })}">Detalhes<span class="sr-only"> do pedido ${N(pd.id)}</span></a>` : h`
                        ${ANTERIOR[pd.etapa] ? h`
                          <form class="em-linha" method="post" action="${url(ctx)}">
                            ${csrfCampo(ctx)}
                            <input type="hidden" name="acao" value="etapa">
                            <input type="hidden" name="id" value="${N(pd.id)}">
                            <input type="hidden" name="etapa" value="${ANTERIOR[pd.etapa]}">
                            <button type="submit" class="btn btn-pequeno btn-fantasma btn-icone" title="Voltar etapa" aria-label="Voltar o pedido ${N(pd.id)} para a etapa anterior"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button>
                          </form>` : ''}
                        <a class="btn btn-pequeno btn-fantasma btn-icone" href="${url(ctx, { editar: pd.id, novo: null, detalhes: null })}#formulario" title="Editar pedido" aria-label="Editar pedido ${N(pd.id)}"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                        <form class="em-linha" method="post" action="${url(ctx)}">
                          ${csrfCampo(ctx)}
                          <input type="hidden" name="acao" value="etapa">
                          <input type="hidden" name="id" value="${N(pd.id)}">
                          <input type="hidden" name="etapa" value="${destino}">
                          <button type="submit" class="btn btn-pequeno${destaque ? ' btn-primario' : ''}">${rotuloBotao}<span class="sr-only"> pedido ${N(pd.id)}</span></button>
                        </form>`}
                    </div>
                  </footer>
                </article>`;
              })}
            </section>`;
          })}
        </div>`;

  return pagina(ctx, { titulo: 'Pedidos', subtitulo: 'Comandas do dia, da cozinha até a entrega', paginaAtual: 'pedidos' }, conteudo);
};
