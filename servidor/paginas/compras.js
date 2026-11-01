'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina } = require('../layout');
const {
  h, cru, ler, moeda, dataBr, data, hoje, somarDias, entrada, entradaInt, entradaDecimal, lista, csrfCampo, tratarPost,
  url, paginacao, periodoFiltro, periodosRotulos, sel,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;
const POR_PAGINA = 12;
const SITUACOES = { FINALIZADA: ['finalizada', 'Recebida'], CANCELADA: ['cancelada', 'Cancelada'], EM_ABERTO: ['pendente', 'Em aberto'] };
const SIT_PARCELA = { PAGO: ['pago', 'Paga'], PENDENTE: ['pendente', 'A pagar'], ATRASADO: ['atrasado', 'Vencida'], CANCELADO: ['cancelado', 'Cancelada'], AGENDADO: ['agendado', 'Agendada'] };

module.exports = async function compras(ctx) {
  const u = await sessao.autenticar(ctx);
  sessao.exigirPermissao(ctx, u.perm.compras);

  // ---------- ações (POST) ----------
  await tratarPost(ctx, async () => {
    const acao = entrada(ctx, 'acao');
    if (acao === 'salvar') {
      const qtds = lista(ctx, 'itens_qtd'), precos = lista(ctx, 'itens_preco');
      const itens = [];
      lista(ctx, 'itens_produto').forEach((p, n) => {
        if ((parseInt(p, 10) || 0) > 0) {
          const preco = Number(String(precos[n] ?? '0').replace(',', '.'));
          itens.push([parseInt(p, 10), parseInt(qtds[n], 10) || 0, Number.isFinite(preco) ? preco : 0]);
        }
      });
      if (!itens.length) throw new bd.ErroNegocio('Adicione ao menos um item à compra.');
      const vencimento = entrada(ctx, 'vencimento');
      if (!/^\d{4}-\d{2}-\d{2}$/.test(vencimento)) throw new bd.ErroNegocio('Informe o vencimento da primeira parcela.');

      const id = await bd.transacao(async (tx) => {
        const novo = Number(await tx.chamarValor('sp_compra_criar', [entradaInt(ctx, 'fornecedor_id'), u.id, entrada(ctx, 'nf'), entrada(ctx, 'observacao')]));
        for (const [produto, qtd, preco] of itens) await tx.chamar('sp_compra_item_adicionar', [novo, produto, qtd, preco]);
        await tx.chamar('sp_compra_finalizar', [novo, entradaDecimal(ctx, 'desconto'), Math.max(1, entradaInt(ctx, 'parcelas', 1)), vencimento, 'pago' in ctx.corpo ? 1 : 0, u.id]);
        return novo;
      });
      return [`Compra #${id} registrada. O estoque e o Fluxo de Caixa já foram atualizados.`, url(ctx, { novo: null, fornecedor: null, ver: id })];
    }
    if (acao === 'cancelar') {
      const id = entradaInt(ctx, 'id');
      await bd.chamar('sp_compra_cancelar', [id, u.id]);
      return [`Compra #${id} cancelada e estoque estornado.`, url(ctx, { cancelar: null })];
    }
    throw new bd.ErroNegocio('Ação não reconhecida.');
  });

  // ---------- dados (GET) ----------
  const [de, ate, periodo] = periodoFiltro(ctx, '90dias');
  const busca = entrada(ctx, 'q');
  const status = ['FINALIZADA', 'CANCELADA'].includes(entrada(ctx, 'status')) ? entrada(ctx, 'status') : '';
  let pg = Math.max(1, entradaInt(ctx, 'pagina', 1));

  const [resumoBd, contagem] = await Promise.all([
    ler(ctx, () => bd.chamarUm('sp_compra_resumo', [de, ate]), null),
    ler(ctx, () => bd.chamarUm('sp_compra_contagem', [busca, de, ate]), { todos: 0, finalizadas: 0, canceladas: 0 }),
  ]);
  const resumo = resumoBd || { total_comprado: 0, compras: 0, a_pagar: 0, vencido: 0, principal_fornecedor: null };
  const totalFiltro = N(status === 'FINALIZADA' ? contagem.finalizadas : status === 'CANCELADA' ? contagem.canceladas : contagem.todos);
  const paginasTotal = Math.max(1, Math.ceil(totalFiltro / POR_PAGINA));
  pg = Math.min(pg, paginasTotal);
  const listaCompras = await ler(ctx, () => bd.chamar('sp_compra_listar', [busca, status, de, ate, POR_PAGINA, (pg - 1) * POR_PAGINA]));

  // nova compra
  const formAberto = 'novo' in ctx.query;
  const [fornecedores, produtos] = formAberto ? await Promise.all([
    ler(ctx, () => bd.chamar('sp_fornecedor_opcoes')),
    ler(ctx, () => bd.chamar('sp_compra_opcoes_produtos')),
  ]) : [[], []];
  const fornecedorInicial = entradaInt(ctx, 'fornecedor');

  // detalhe / cancelamento
  const verId = entradaInt(ctx, 'ver');
  const compra = verId > 0 ? await ler(ctx, () => bd.chamarUm('sp_compra_obter', [verId]), null) : null;
  const [itensCompra, parcelas] = compra ? await Promise.all([
    ler(ctx, () => bd.chamar('sp_compra_itens', [verId])),
    ler(ctx, () => bd.chamar('sp_compra_parcelas', [verId])),
  ]) : [[], []];
  const cancelarId = entradaInt(ctx, 'cancelar');
  const alvoCancelar = cancelarId > 0 ? await ler(ctx, () => bd.chamarUm('sp_compra_obter', [cancelarId]), null) : null;

  const opcoesProduto = () => {
    let grupo = null;
    const saida = [h`<option value="0">— Escolha um produto —</option>`];
    for (const p of produtos) {
      const rot = p.tipo === 'INSUMO' ? 'Insumos · ' + p.categoria : 'Revenda · ' + p.categoria;
      if (rot !== grupo) {
        if (grupo !== null) saida.push(cru('</optgroup>'));
        saida.push(h`<optgroup label="${rot}">`);
        grupo = rot;
      }
      saida.push(h`<option value="${N(p.id)}" data-preco="${p.preco}">${p.nome} (${p.unidade} · estoque ${N(p.estoque)})</option>`);
    }
    if (grupo !== null) saida.push(cru('</optgroup>'));
    return saida;
  };

  const [classeCompra, rotuloCompra] = compra ? (SITUACOES[compra.status] || ['', compra.status]) : [];

  const conteudo = h`
        <section class="grid-cards" aria-label="Resumo das compras">
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone brasa"><i class="fa-solid fa-cart-flatbed" aria-hidden="true"></i></span> Comprado no período</span>
            <div class="card-valor">${moeda(resumo.total_comprado)}</div>
            <span class="card-detalhe">${N(resumo.compras)} ${N(resumo.compras) === 1 ? 'compra recebida' : 'compras recebidas'}</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone mostarda"><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i></span> Contas a pagar</span>
            <div class="card-valor">${moeda(resumo.a_pagar)}</div>
            <span class="card-detalhe">Parcelas de compras em aberto</span>
          </div>
          <div class="card-metrica alerta-box${N(resumo.vencido) > 0 ? ' ativo-alerta' : ''}">
            <span class="card-titulo"><span class="card-icone brasa"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i></span> Vencidas</span>
            <div class="card-valor">${moeda(resumo.vencido)}</div>
            <span class="card-detalhe ${N(resumo.vencido) > 0 ? 'alerta' : ''}">${N(resumo.vencido) > 0 ? 'Pague no Fluxo de Caixa' : 'Nenhuma parcela vencida'}</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone azul"><i class="fa-solid fa-truck-field" aria-hidden="true"></i></span> Principal fornecedor</span>
            <div class="card-valor" style="font-size:19px;white-space:normal">${resumo.principal_fornecedor || '—'}</div>
            <span class="card-detalhe">Maior valor comprado no período</span>
          </div>
        </section>

        <form class="toolbar" method="get" action="compras">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar compra</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="${busca}" placeholder="Fornecedor, nota fiscal ou nº da compra" autocomplete="off">
            </label>
            <div class="grupo-input seletor">
              <label class="sr-only" for="periodo">Período</label>
              <select id="periodo" name="periodo" class="input-dash" onchange="this.form.submit()">
                ${Object.entries(periodosRotulos()).filter(([v]) => v !== 'personalizado').map(([v, r]) => h`<option value="${v}"${sel(periodo === v)}>${r}</option>`)}
              </select>
            </div>
            ${status ? h`<input type="hidden" name="status" value="${status}">` : ''}
            <button type="submit" class="btn btn-secundario">Filtrar</button>
            <div class="filtros" role="group" aria-label="Situação">
              ${[['', 'Todas', contagem.todos], ['FINALIZADA', 'Recebidas', contagem.finalizadas], ['CANCELADA', 'Canceladas', contagem.canceladas]].map(([v, r, q]) => h`<a class="filtro${status === v ? ' ativo' : ''}" href="${url(ctx, { status: v || null, pagina: null })}">${r} <span>${N(q)}</span></a>`)}
            </div>
          </div>
          <div class="toolbar-grupo">
            <a class="btn" href="fornecedores"><i class="fa-solid fa-truck-field" aria-hidden="true"></i> Fornecedores</a>
            <a class="btn btn-primario" href="${url(ctx, { novo: 1, ver: null })}"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i> Registrar compra</a>
          </div>
        </form>

        <section class="painel-card painel-tabela" aria-label="Compras">
          ${!listaCompras.length ? h`<p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-cart-flatbed" aria-hidden="true"></i></span>Nenhuma compra neste período. Use "Registrar compra" quando a mercadoria chegar.</p>` : h`
            <div class="tabela-container">
              <table class="tabela">
                <thead>
                  <tr>
                    <th scope="col">Compra</th>
                    <th scope="col">Fornecedor</th>
                    <th scope="col" class="num">Itens</th>
                    <th scope="col" class="num">Total</th>
                    <th scope="col">Pagamento</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                  </tr>
                </thead>
                <tbody>
                  ${listaCompras.map((c) => {
                    const [classe, rotulo] = SITUACOES[c.status] || ['', c.status];
                    const pct = N(c.parcelas) > 0 ? Math.round((N(c.parcelas_pagas) / N(c.parcelas)) * 100) : 0;
                    return h`<tr class="${c.status === 'CANCELADA' ? 'linha-apagada' : ''}">
                      <td><span class="celula-dupla"><strong class="id-pedido">#${N(c.id)}</strong><small>${c.data}${c.nf ? ' · ' + c.nf : ''}</small></span></td>
                      <td><span class="celula-dupla"><strong>${c.fornecedor}</strong><small>por ${c.responsavel}</small></span></td>
                      <td class="num">${N(c.itens)}</td>
                      <td class="num"><strong>${moeda(c.total)}</strong>${N(c.desconto) > 0 ? h`<br><small class="muted">-${moeda(c.desconto)}</small>` : ''}</td>
                      <td>
                        ${N(c.parcelas) > 0 ? h`
                          <div class="nivel" style="min-width:120px">
                            <span class="painel-meta">${N(c.parcelas_pagas)}/${N(c.parcelas)} ${N(c.parcelas) === 1 ? 'parcela paga' : 'parcelas pagas'}</span>
                            <span class="nivel-barra"><span style="--nivel: ${pct}%"></span></span>
                          </div>` : '—'}
                      </td>
                      <td><span class="status ${classe}">${rotulo}</span></td>
                      <td class="acoes">
                        <a class="btn btn-pequeno btn-fantasma" href="${url(ctx, { ver: c.id, novo: null })}">Detalhes<span class="sr-only"> da compra ${N(c.id)}</span></a>
                      </td>
                    </tr>`;
                  })}
                </tbody>
              </table>
            </div>
            ${paginacao(ctx, pg, paginasTotal, totalFiltro, POR_PAGINA)}`}
        </section>

        ${compra ? h`
          <section class="painel-card form-card" aria-labelledby="t-ver">
            <div class="painel-header">
              <h2 id="t-ver">Compra #${N(compra.id)} <span class="status ${classeCompra}">${rotuloCompra}</span></h2>
              <a class="gaveta-fechar" href="${url(ctx, { ver: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
            </div>
            <div class="gaveta-corpo">
              <div class="detalhe-grade">
                <div><small>Fornecedor</small><strong>${compra.fornecedor}</strong></div>
                <div><small>Data</small><strong>${compra.data}</strong></div>
                <div><small>Nota fiscal</small><strong>${compra.nf || 'Não informada'}</strong></div>
                <div><small>Registrada por</small><strong>${compra.responsavel}</strong></div>
                ${compra.observacao ? h`<div style="grid-column:1/-1"><small>Observação</small><strong>${compra.observacao}</strong></div>` : ''}
              </div>
              <table class="tabela">
                <thead><tr><th scope="col">Item</th><th scope="col" class="num">Qtd.</th><th scope="col" class="num">Preço</th><th scope="col" class="num">Total</th></tr></thead>
                <tbody>
                  ${itensCompra.map((it) => h`<tr><td>${it.nome}</td><td class="num">${N(it.quantidade)} ${it.unidade}</td><td class="num">${moeda(it.preco)}</td><td class="num">${moeda(it.total)}</td></tr>`)}
                </tbody>
              </table>
              <div class="resumo-compra">
                <div><span>Itens</span><span>${moeda(compra.valor)}</span></div>
                ${N(compra.desconto) > 0 ? h`<div><span>Desconto</span><span>- ${moeda(compra.desconto)}</span></div>` : ''}
                <div class="total"><span>Total</span><span>${moeda(compra.total)}</span></div>
              </div>
              ${parcelas.length ? h`
                <h3 class="subtitulo-form">Parcelas <a class="btn-link" href="fluxo_de_caixa?tipo=SAIDA&amp;periodo=ano">Pagar no Fluxo de Caixa</a></h3>
                <ul class="parcelas">
                  ${parcelas.map((p) => {
                    const [cp, rp] = SIT_PARCELA[p.situacao] || ['', p.situacao];
                    return h`<li>
                      <span class="celula-dupla"><strong>${N(p.numero)}/${N(p.quantidade)} · vence ${dataBr(p.vencimento)}</strong><small>${p.pagamento ? 'Paga em ' + dataBr(p.pagamento) : 'Em aberto'}</small></span>
                      <span><span class="status ${cp}">${rp}</span> <strong style="margin-left:8px">${moeda(p.total)}</strong></span>
                    </li>`;
                  })}
                </ul>` : ''}
              ${compra.status === 'FINALIZADA' ? h`
                <div class="form-acoes">
                  <a class="btn btn-perigo" href="${url(ctx, { cancelar: compra.id, ver: null })}"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar compra</a>
                </div>` : ''}
            </div>
          </section>` : ''}

        ${alvoCancelar ? h`
          <section class="confirmar-card" aria-labelledby="t-cancelar">
            <h2 id="t-cancelar">Cancelar a compra #${N(alvoCancelar.id)}?</h2>
            <p>As quantidades saem do estoque e as parcelas em aberto (${moeda(alvoCancelar.total)} no total) são canceladas no Fluxo de Caixa.</p>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="cancelar">
              <input type="hidden" name="id" value="${N(alvoCancelar.id)}">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar compra</button>
              <a class="btn btn-secundario" href="${url(ctx, { cancelar: null })}" data-fechar>Voltar</a>
            </form>
          </section>` : ''}

        ${formAberto ? h`
          <section class="painel-card form-card larga" aria-labelledby="t-nova">
            <div class="painel-header">
              <h2 id="t-nova">Registrar compra</h2>
              <a class="gaveta-fechar" href="${url(ctx, { novo: null, fornecedor: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
            </div>
            ${!fornecedores.length ? h`<p class="vazio">Cadastre um fornecedor antes de registrar compras. <a href="fornecedores?novo=1">Cadastrar fornecedor</a></p>` : h`
              <form method="post" action="${url(ctx)}" id="form-compra">
                ${csrfCampo(ctx)}
                <input type="hidden" name="acao" value="salvar">
                <div class="form-grid tres">
                  <div class="grupo-input" style="grid-column: span 2">
                    <label for="c-fornecedor">Fornecedor</label>
                    <select id="c-fornecedor" name="fornecedor_id" class="input-dash" required>
                      <option value="">Escolha o fornecedor</option>
                      ${fornecedores.map((fo) => h`<option value="${N(fo.id)}"${sel(N(fo.id) === fornecedorInicial)}>${fo.nome}</option>`)}
                    </select>
                  </div>
                  <div class="grupo-input">
                    <label for="c-nf">Nota fiscal (opcional)</label>
                    <input type="text" id="c-nf" name="nf" class="input-dash" maxlength="30" placeholder="Ex.: NF-12345">
                  </div>
                </div>

                <h3 class="subtitulo-form">Itens recebidos <span class="painel-meta">Produto, quantidade e preço unitário</span></h3>
                <div class="itens-pedido">
                  ${Array.from({ length: 8 }, (_, n) => h`
                    <div class="item-pedido compra">
                      <select name="itens_produto[]" class="input-dash" aria-label="Produto ${n + 1}">
                        ${opcoesProduto()}
                      </select>
                      <input type="number" name="itens_qtd[]" class="input-dash" min="1" step="1" placeholder="Qtd." aria-label="Quantidade do produto ${n + 1}">
                      <div class="campo-dinheiro"><input type="number" name="itens_preco[]" class="input-dash" min="0.01" step="0.01" placeholder="0,00" aria-label="Preço unitário do produto ${n + 1}"></div>
                    </div>`)}
                </div>
                <p class="painel-meta" style="margin-top:6px">Linhas sem produto são ignoradas. O preço sugerido é o último custo pago.</p>

                <h3 class="subtitulo-form">Pagamento</h3>
                <div class="form-grid tres">
                  <div class="grupo-input">
                    <label for="c-desconto">Desconto</label>
                    <div class="campo-dinheiro"><input type="number" id="c-desconto" name="desconto" class="input-dash" min="0" step="0.01" value="0"></div>
                  </div>
                  <div class="grupo-input">
                    <label for="c-parcelas">Parcelas</label>
                    <select id="c-parcelas" name="parcelas" class="input-dash">
                      ${Array.from({ length: 12 }, (_, i) => i + 1).map((p) => h`<option value="${p}">${p === 1 ? 'À vista (1x)' : `${p}x mensais`}</option>`)}
                    </select>
                  </div>
                  <div class="grupo-input">
                    <label for="c-venc">1º vencimento</label>
                    <input type="date" id="c-venc" name="vencimento" class="input-dash" required value="${somarDias(hoje(), 14)}">
                  </div>
                  <label class="switch-rotulo" style="grid-column: 1 / -1">
                    <span class="switch"><input type="checkbox" name="pago" value="1" id="c-pago"><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>A primeira parcela já foi paga</span>
                  </label>
                  <div class="grupo-input largo" style="grid-column: 1 / -1">
                    <label for="c-obs">Observação (opcional)</label>
                    <input type="text" id="c-obs" name="observacao" class="input-dash" maxlength="255">
                  </div>
                </div>

                <div class="resumo-compra" aria-live="polite">
                  <div><span>Itens</span><span id="c-subtotal">R$ 0,00</span></div>
                  <div class="total"><span>Total</span><span id="c-total">R$ 0,00</span></div>
                  <div class="painel-meta" id="c-parcela-info"></div>
                </div>

                <div class="form-acoes">
                  <button type="submit" class="btn btn-primario"><i class="fa-solid fa-check" aria-hidden="true"></i> Registrar compra</button>
                  <a class="btn btn-secundario" href="${url(ctx, { novo: null, fornecedor: null })}" data-fechar>Cancelar</a>
                </div>
              </form>`}
          </section>` : ''}`;

  const rodapeExtra = cru(`<script>
  (() => {
    const form = document.getElementById('form-compra');
    if (!form) return;
    const moeda = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    function calcular() {
      let soma = 0;
      form.querySelectorAll('.item-pedido').forEach((linha) => {
        const prod = Number(linha.querySelector('select').value);
        const q = Number(linha.querySelector('[name="itens_qtd[]"]').value) || 0;
        const p = Number(linha.querySelector('[name="itens_preco[]"]').value) || 0;
        if (prod) soma += q * p;
      });
      const desc = Math.min(Number(form.desconto.value) || 0, soma);
      const total = soma - desc;
      const n = Number(form.parcelas.value);
      document.getElementById('c-subtotal').textContent = moeda(soma);
      document.getElementById('c-total').textContent = moeda(total);
      document.getElementById('c-parcela-info').textContent = n > 1 && total ? \`\${n} parcelas de \${moeda(total / n)}\` : '';
    }
    form.addEventListener('change', (e) => {
      // escolher o produto sugere o último custo e a quantidade 1
      if (e.target.matches('[name="itens_produto[]"]')) {
        const linha = e.target.closest('.item-pedido');
        const preco = linha.querySelector('[name="itens_preco[]"]');
        const qtd = linha.querySelector('[name="itens_qtd[]"]');
        const sugerido = e.target.selectedOptions[0]?.dataset.preco;
        if (sugerido && !preco.value) preco.value = Number(sugerido).toFixed(2);
        if (!qtd.value && e.target.value !== '0') qtd.value = 1;
        qtd.required = preco.required = e.target.value !== '0';
      }
      calcular();
    });
    form.addEventListener('input', calcular);
  })();
</script>`);

  return pagina(ctx, { titulo: 'Compras', subtitulo: 'Entrada de mercadorias, contas a pagar e custo dos insumos', paginaAtual: 'compras', rodapeExtra }, conteudo);
};
